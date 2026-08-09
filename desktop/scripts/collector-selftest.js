'use strict';

/*
 * Standalone regression test for the MACHINE COMPLIANCE collector, run with
 * plain node (no Electron, no test framework). The collector's collaborators
 * (api, store, report) are mocked via require.cache and its four probes are
 * swapped for fixtures, so the REAL collector.js singleton is exercised.
 *
 * Each of these is a way the collector could either lie to the dashboard or hurt
 * the product it is bolted onto, and every one of them has bitten the source app
 * or is a direct consequence of this fork being multi-tenant:
 *
 *   1. no `profile` field      — a repeated `profile` key made the host WAF 403
 *                                the ENTIRE upload before it reached PHP, losing
 *                                all four inventories at once for half the fleet
 *   2. WAF-hostile characters  — angle brackets and control characters are
 *                                scrubbed CLIENT-side (the server sanitises too,
 *                                but the server is behind the firewall)
 *   3. extension enablement    — an unknown Chromium shape counts as ENABLED;
 *                                `state === 1` once dropped 100% of extensions
 *   4. item + byte caps        — mirror the server's caps, and shed the least
 *                                actionable inventory first so extensions survive
 *   5. agent version           — always sent, or the stale-agent warning cannot
 *                                tell "clean" from "too old to look"
 *   6. blind vs clean          — processes/network are omitted when the probe
 *                                returned nothing (they are NOT blind-protected
 *                                server-side, so an empty list would falsely
 *                                clear a VPN finding); extensions/programs are
 *                                sent even when empty so the server marks the
 *                                machine UNKNOWN
 *   7. request rate            — a floor between uploads and one run in flight
 *   8. backoff + entitlement   — failures back off; a 403 (workspace has no
 *                                `machine_compliance`) stops collection entirely
 *   9. never throws            — nothing here may reject into the tracker
 *  10. no tenant ids           — the agent never sends organization_id/user_id
 *
 * Usage:  npm test   (from desktop/)
 */

const assert = require('node:assert');
const path = require('node:path');

const MAIN = path.join(__dirname, '..', 'main');

function mockModule(name, exports) {
  const file = path.join(MAIN, name + '.js');
  require.cache[file] = { id: file, filename: file, loaded: true, exports };
}

let sent = [];          // serialised bodies posted
let apiCalls = 0;       // every attempt, successful or not
let nextError = null;   // error the next send should reject with
let mockToken = 'test-token';
let events = [];
let mockConsented = true;   // has the signed-in employee passed the consent gate?

// The consent gate. Machine-compliance inventory is the one capture path that
// runs on its own timer from app.whenReady(), so it — unlike screenshots and
// activity — could otherwise collect from a machine whose user has not been
// told anything. Mocked here; the real module is covered by consent-selftest.js.
mockModule('consent', {
  hasAccepted: () => mockConsented,
});
mockModule('store', {
  get: (key) => (key === 'token' ? mockToken : key === 'deviceName' ? 'TEST-PC' : null),
  set: () => {},
  delete: () => {},
});
mockModule('report', {
  info: (event, message, context) => events.push({ level: 'info', event, message, context }),
  warn: (event, message, context) => events.push({ level: 'warn', event, message, context }),
  error: (event, message, context) => events.push({ level: 'error', event, message, context }),
});
mockModule('api', {
  sendMachineReport: async (body) => {
    apiCalls++;
    if (nextError) {
      const err = nextError;
      nextError = null;
      throw err;
    }
    sent.push(body);
    return { machine_id: 1, stored: {}, truncated: {}, flagged: 0, alerted: 0 };
  },
});

const collector = require(path.join(MAIN, 'collector.js'));

// The collector is Windows-only by design. The suite has to run on any dev box,
// so pretend to be Windows for the duration — the probes are fixtures anyway.
const realPlatform = process.platform;
Object.defineProperty(process, 'platform', { value: 'win32', configurable: true });

const FIXTURE = {
  extensions: [
    { browser: 'Chrome', browser_profile: 'Upwork Faryal', id: 'aaaabbbbccccdddd', name: 'Auto Refresh Plus', version: '3.1', enabled: true },
    { browser: 'Chrome', browser_profile: 'Default', id: 'aaaabbbbccccdddd', name: 'Auto Refresh Plus', version: '3.1', enabled: true },
  ],
  programs: [{ name: 'AnyDesk', version: '8.0', publisher: 'AnyDesk Software GmbH' }],
  processes: [{ name: 'chrome.exe', detail: 'C:\\Program Files\\Google\\Chrome\\chrome.exe' }],
  network: [{ name: 'Ethernet', detail: 'Realtek PCIe GbE Family Controller' }],
};

function setProbes(fixture) {
  collector._probes.extensions = async () => JSON.parse(JSON.stringify(fixture.extensions || []));
  collector._probes.programs = async () => JSON.parse(JSON.stringify(fixture.programs || []));
  collector._probes.processes = async () => JSON.parse(JSON.stringify(fixture.processes || []));
  collector._probes.network = async () => JSON.parse(JSON.stringify(fixture.network || []));
}

function reset(fixture) {
  collector._reset();
  sent = [];
  apiCalls = 0;
  nextError = null;
  events = [];
  mockToken = 'test-token';
  mockConsented = true;
  setProbes(fixture || FIXTURE);
}

function httpError(status, data, headers) {
  const err = new Error('Request failed with status code ' + status);
  err.response = { status, data: data || {}, headers: headers || {} };
  return err;
}

/** The last posted body, parsed. */
function lastBody() {
  assert.ok(sent.length, 'expected a body to have been posted');
  return JSON.parse(sent[sent.length - 1]);
}

const tests = [];
const test = (name, fn) => tests.push([name, fn]);

// ---- 0. NOTHING IS COLLECTED BEFORE THE EMPLOYEE HAS CONSENTED --------------
test('no inventory is collected, or even probed, before the consent gate is passed', async () => {
  reset();
  mockConsented = false;
  let probed = false;
  collector._probes.extensions = async () => { probed = true; return []; };

  const result = await collector.run('test');

  assert.strictEqual(result, 'skip_no_consent');
  assert.strictEqual(apiCalls, 0, 'inventory was uploaded from an unconsented machine');
  assert.strictEqual(sent.length, 0);
  assert.strictEqual(probed, false, 'the PC was inventoried before anyone was asked');
});

test('collection resumes once the employee has accepted', async () => {
  reset();
  mockConsented = false;
  assert.strictEqual(await collector.run('test'), 'skip_no_consent');

  reset();                       // fresh state, consent granted
  await collector.run('test');
  assert.strictEqual(apiCalls, 1);
});

// ---- 1. THE WAF KILLER: no field named `profile` ----------------------------
test('no key named "profile" ever appears in the payload', async () => {
  reset();
  await collector.run('test');
  const raw = sent[0];
  assert.ok(!/"profile"\s*:/.test(raw), 'a "profile" key is present — the host WAF 403s the WHOLE upload');
  const body = lastBody();
  const ext = body.reports.find((r) => r.kind === 'extensions').items[0];
  assert.strictEqual(ext.browser_profile, 'Upwork Faryal', 'the profile label ships as browser_profile');
  assert.ok(!('profile' in ext));
});

// ---- 2. WAF-hostile characters are scrubbed on the CLIENT -------------------
test('angle brackets and control characters never reach the wire', async () => {
  reset({
    extensions: [{
      browser: 'Chrome',
      browser_profile: '<script>alert(1)</script>',
      id: 'x'.repeat(90),
      name: 'Bad\u0000Name\nwith\u2028breaks',
      version: '1',
      enabled: true,
    }],
    programs: [{ name: 'ok', version: null, publisher: 'A<B>C' }],
    processes: [],
    network: [],
  });
  await collector.run('test');
  const raw = sent[0];
  assert.ok(!raw.includes('<') && !raw.includes('>'), 'angle brackets survived into the body');
  // eslint-disable-next-line no-control-regex
  assert.ok(!/[\u0000-\u001F\u2028\u2029]/.test(raw), 'control characters survived into the body');

  const ext = lastBody().reports.find((r) => r.kind === 'extensions').items[0];
  assert.strictEqual(ext.browser_profile.length <= 60, true, 'profile label capped to the column width');
  assert.ok(ext.id.length <= 64, 'extension id capped');
  assert.ok(!ext.name.includes('\n'));
});

test('non-Latin names survive intact (Urdu/Arabic profile labels are normal here)', () => {
  assert.strictEqual(collector._clean('اپ ورک پروفائل', 60), 'اپ ورک پروفائل');
  assert.strictEqual(collector._clean('  spaced   out  ', 60), 'spaced out');
  assert.strictEqual(collector._clean('   ', 60), null, 'an all-whitespace value is dropped, not stored as a ghost');
  assert.strictEqual(collector._clean(null, 60), null);
  assert.strictEqual(collector._clean({}, 60), null);
});

// ---- 3. THE EXTENSION-DROPPING BUG -----------------------------------------
test('an unknown Chromium enablement shape counts as ENABLED, never dropped', () => {
  const enabled = collector._isExtensionEnabled;
  // Current Chrome/Edge.
  assert.strictEqual(enabled({ disable_reasons: [] }), true);
  assert.strictEqual(enabled({ disable_reasons: [1] }), false);
  // Numeric bitmask builds.
  assert.strictEqual(enabled({ disable_reasons: 0 }), true);
  assert.strictEqual(enabled({ disable_reasons: 4 }), false);
  // Old Chrome.
  assert.strictEqual(enabled({ state: 1 }), true);
  assert.strictEqual(enabled({ state: 0 }), false);
  // THE REGRESSION: 0.4.5 read `state === 1` on a record that has no `state`,
  // decided every extension was disabled, and reported ZERO extensions from the
  // whole fleet while the dashboard showed everyone clean.
  assert.strictEqual(enabled({}), true, 'an unrecognised shape must be KEPT, not silently dropped');
  assert.strictEqual(enabled({ manifest: { name: 'x' } }), true);
});

// ---- 4. client-side item caps and byte budget ------------------------------
test('per-kind item caps mirror the server and are enforced before sending', async () => {
  reset({
    extensions: Array.from({ length: 1500 }, (_, i) => ({ browser: 'Chrome', browser_profile: 'P' + i, id: 'id' + i, name: 'Ext ' + i, version: '1', enabled: true })),
    programs: Array.from({ length: 900 }, (_, i) => ({ name: 'Prog ' + i, version: '1', publisher: 'Pub' })),
    processes: Array.from({ length: 900 }, (_, i) => ({ name: 'p' + i + '.exe', detail: 'C:\\x' })),
    network: Array.from({ length: 200 }, (_, i) => ({ name: 'Adapter ' + i, detail: 'desc' })),
  });
  await collector.run('test');
  const body = lastBody();
  for (const r of body.reports) {
    assert.ok(r.items.length <= collector.ITEM_CAPS[r.kind], r.kind + ' exceeded its cap: ' + r.items.length);
  }
});

/** The largest payload the caps physically allow: every field at its column width. */
function worstCaseKinds() {
  return {
    extensions: Array.from({ length: 900 }, (_, i) => ({ browser: 'B'.repeat(40), browser_profile: 'P'.repeat(58) + i, id: 'i'.repeat(60) + i, name: 'E'.repeat(196) + i, version: 'v'.repeat(40), enabled: true })),
    programs: Array.from({ length: 600 }, (_, i) => ({ name: 'P'.repeat(196) + i, version: 'v'.repeat(40), publisher: 'X'.repeat(150) })),
    processes: Array.from({ length: 500 }, (_, i) => ({ name: 'n'.repeat(196) + i, detail: 'D'.repeat(196) + i })),
    network: Array.from({ length: 80 }, (_, i) => ({ name: 'n'.repeat(196) + i, detail: 'D'.repeat(196) + i })),
  };
}

const HEAD = { device_name: 'PC', app_version: '0.4.6', platform: 'win32' };

test('the worst payload the item caps allow still fits the byte budget', () => {
  const { body, counts } = collector._fitPayload(
    { collected_at: new Date().toISOString(), head: HEAD },
    worstCaseKinds(),
  );
  assert.ok(Buffer.byteLength(body) <= collector.MAX_BODY_BYTES,
    'worst case ' + Buffer.byteLength(body) + ' > budget ' + collector.MAX_BODY_BYTES);
  for (const kind of Object.keys(counts)) {
    assert.strictEqual(counts[kind], collector.ITEM_CAPS[kind], kind + ' should sit exactly at its cap');
  }
});

test('an over-budget body is trimmed, and extensions are trimmed LAST', () => {
  const kinds = worstCaseKinds();
  const { body, counts, trimmed } = collector._fitPayload(
    { collected_at: new Date().toISOString(), head: HEAD },
    kinds,
    300 * 1024, // forced small budget: the safety net has to be exercisable
  );

  assert.ok(Buffer.byteLength(body) <= 300 * 1024,
    'body ' + Buffer.byteLength(body) + ' exceeded the forced budget');
  assert.ok(trimmed.processes > 0, 'processes are shed first');
  assert.ok(counts.extensions > 0, 'extensions — the whole point of the feature — survive');
  assert.ok(counts.extensions >= counts.processes, 'extensions are trimmed after everything else');

  // A block the byte trim emptied must be DROPPED, never shipped as items: []
  // for a kind the server does not blind-protect — that would read as "the VPN
  // adapter is gone" and close a live finding.
  const parsedKinds = JSON.parse(body).reports;
  for (const r of parsedKinds) {
    if (r.items.length === 0) {
      assert.ok(['extensions', 'programs'].includes(r.kind),
        'an emptied "' + r.kind + '" block was sent — that is a false all-clear');
    }
  }

  // Still valid JSON matching the contract.
  const parsed = JSON.parse(body);
  assert.ok(Array.isArray(parsed.reports) && parsed.reports.length <= 6);
  for (const r of parsed.reports) {
    assert.ok(['extensions', 'programs', 'processes', 'network'].includes(r.kind));
    assert.ok(typeof r.collected_at === 'string');
    assert.ok(Array.isArray(r.items));
  }
});

// ---- 5. the stale-agent warning depends on app_version ---------------------
test('a byte trim that empties processes/network drops the block instead of sending []', () => {
  const kinds = worstCaseKinds();
  const { body } = collector._fitPayload(
    { collected_at: new Date().toISOString(), head: HEAD },
    kinds,
    24 * 1024, // brutal budget: everything but the blind-protected kinds is shed
  );
  const reports = JSON.parse(body).reports;
  const kindsSent = reports.map((r) => r.kind);
  assert.ok(!kindsSent.includes('processes') || reports.find((r) => r.kind === 'processes').items.length > 0);
  assert.ok(!kindsSent.includes('network') || reports.find((r) => r.kind === 'network').items.length > 0);
  assert.ok(kindsSent.includes('extensions'), 'the blind-protected kinds always ship, even emptied');
  assert.ok(kindsSent.includes('programs'));
});

test('device_name, app_version and platform are always sent', async () => {
  reset();
  await collector.run('test');
  const body = lastBody();
  assert.strictEqual(body.device_name, 'TEST-PC');
  assert.ok(typeof body.app_version === 'string' && body.app_version.length > 0,
    'without app_version the server cannot tell "clean" from "agent too old to look"');
  assert.strictEqual(body.platform, 'win32');
});

// ---- 6. blind vs clean ------------------------------------------------------
test('extensions/programs are reported EVEN WHEN EMPTY so the server marks the machine unknown', async () => {
  reset({ extensions: [], programs: [], processes: FIXTURE.processes, network: FIXTURE.network });
  await collector.run('test');
  const kinds = lastBody().reports.map((r) => r.kind);
  assert.ok(kinds.includes('extensions'), 'an empty extension list must still be reported (server treats it as UNKNOWN)');
  assert.ok(kinds.includes('programs'));
});

test('processes/network are OMITTED when the probe returned nothing, never sent as empty', async () => {
  reset({ extensions: FIXTURE.extensions, programs: FIXTURE.programs, processes: [], network: [] });
  await collector.run('test');
  const kinds = lastBody().reports.map((r) => r.kind);
  assert.ok(!kinds.includes('processes'),
    'an empty process list is NOT blind-protected server-side — sending it would falsely clear findings');
  assert.ok(!kinds.includes('network'),
    'an empty adapter list would falsely clear a VPN finding');
});

test('a machine where every probe failed still reports, so it is visible as unknown', async () => {
  reset({ extensions: [], programs: [], processes: [], network: [] });
  const result = await collector.run('test');
  assert.strictEqual(result, 'sent');
  const body = lastBody();
  assert.strictEqual(body.reports.length, 2, 'only the blind-protected kinds');
  assert.ok(body.app_version);
});

// ---- 7. request rate --------------------------------------------------------
test('back-to-back runs collapse into one request', async () => {
  reset();
  await collector.run('a');
  await collector.run('b');
  await collector.run('c');
  assert.strictEqual(apiCalls, 1, 'a floor of ' + collector.MIN_INTERVAL_MS + 'ms is enforced between uploads');
  assert.strictEqual(collector.stats().last_result, 'skip_debounced');
});

test('a run is never started while another is in flight', async () => {
  reset();
  let release;
  collector._probes.extensions = () => new Promise((r) => { release = () => r([]); });
  const first = collector.run('slow');
  await new Promise((r) => setImmediate(r));
  const second = await collector.run('overlap');
  assert.strictEqual(second, 'skip_running', 'a second run must not spawn a second set of probes');
  release();
  await first;
});

test('nothing is collected or sent while signed out', async () => {
  reset();
  mockToken = null;
  const result = await collector.run('test');
  assert.strictEqual(result, 'skip_signed_out');
  assert.strictEqual(apiCalls, 0);
});

// ---- 8. backoff + entitlement suppression ----------------------------------
test('a failure arms a backoff instead of retrying immediately', async () => {
  reset();
  nextError = httpError(500, {});
  assert.strictEqual(await collector.run('a'), 'failed');
  assert.strictEqual(collector.stats().consecutive_failures, 1);
  assert.ok(collector.stats().backing_off, 'backoff armed');

  const calls = apiCalls;
  assert.strictEqual(await collector.run('b'), 'skip_backoff');
  assert.strictEqual(apiCalls, calls, 'no request attempted while backing off');
});

test('a 403 (workspace has no machine_compliance entitlement) STOPS collection', async () => {
  reset();
  nextError = httpError(403, { code: 'feature_not_in_plan', feature: 'machine_compliance' });
  assert.strictEqual(await collector.run('a'), 'not_entitled');
  assert.ok(collector.stats().quiet, 'collector went quiet');
  assert.strictEqual(collector.stats().quiet_reason, 'feature_not_in_plan');

  const calls = apiCalls;
  assert.strictEqual(await collector.run('b'), 'skip_quiet');
  assert.strictEqual(apiCalls, calls, 'no inventory is collected for a workspace that has not bought the feature');

  // Signing in clears it — an operator who just granted the add-on should not
  // have to wait out the quiet window.
  collector.onSignIn();
  assert.strictEqual(collector.stats().quiet, false);
});

test('the device limit puts the collector to sleep rather than looping', async () => {
  reset();
  nextError = httpError(422, { code: 'device_limit_reached' });
  assert.strictEqual(await collector.run('a'), 'device_limit');
  assert.ok(collector.stats().quiet);
});

test('a 429 honours Retry-After', async () => {
  reset();
  nextError = httpError(429, {}, { 'retry-after': '600' });
  assert.strictEqual(await collector.run('a'), 'failed');
  assert.ok(collector.stats().backing_off);
  const backoff = events.find((e) => e.event === 'machine_report_failed');
  assert.ok(backoff.context.backoff_seconds >= 600, 'Retry-After respected: ' + backoff.context.backoff_seconds);
});

test('a 401 does not burn a backoff — the api layer already dropped the token', async () => {
  reset();
  nextError = httpError(401, {});
  assert.strictEqual(await collector.run('a'), 'unauthenticated');
  assert.strictEqual(collector.stats().consecutive_failures, 0);
});

// ---- 9. never throws --------------------------------------------------------
test('run() never rejects, whatever the probes or the api do', async () => {
  reset();
  collector._probes.extensions = async () => { throw new Error('powershell blocked by policy'); };
  collector._probes.programs = async () => { throw new Error('registry access denied'); };
  collector._probes.processes = async () => { throw new Error('CIM unavailable'); };
  collector._probes.network = async () => { throw new Error('Get-NetAdapter missing'); };
  const result = await collector.run('degraded');
  assert.strictEqual(result, 'sent', 'a machine where every probe is denied still reports');

  reset();
  nextError = new Error('socket hang up'); // no .response at all
  await collector.run('offline');
  assert.ok(true, 'a network error is swallowed');
});

// ---- 10. no tenant identifiers ---------------------------------------------
test('the agent never sends an organization or user id', async () => {
  reset();
  await collector.run('test');
  const raw = sent[0];
  for (const forbidden of ['organization_id', 'user_id', 'organization', 'tenant']) {
    assert.ok(!raw.includes(forbidden), 'payload carries ' + forbidden + ' — the server derives both from the Sanctum token');
  }
});

test('only the four contract report kinds are ever sent', async () => {
  reset();
  await collector.run('test');
  const body = lastBody();
  assert.ok(body.reports.length <= 6, 'the server accepts at most 6 report blocks');
  const kinds = body.reports.map((r) => r.kind);
  assert.deepStrictEqual([...new Set(kinds)], kinds, 'no duplicate kinds (the server de-dupes, but we must not waste the bytes)');
  for (const k of kinds) assert.ok(['extensions', 'programs', 'processes', 'network'].includes(k));
});

test('no field the server ignores is collected — permissions and from_webstore are gone', async () => {
  reset();
  await collector.run('test');
  const raw = sent[0];
  assert.ok(!raw.includes('permissions'), 'extension permissions must never be collected (they mislabelled ad blockers as VPNs)');
  assert.ok(!raw.includes('from_webstore'));
  assert.ok(!raw.includes('gaia_name') && !raw.includes('user_name'), 'the signed-in Google account is never collected');
});

(async () => {
  let failed = 0;
  for (const [name, fn] of tests) {
    try {
      await fn();
      console.log('  ok   ' + name);
    } catch (err) {
      failed++;
      console.error('  FAIL ' + name + '\n       ' + err.message);
    }
  }
  Object.defineProperty(process, 'platform', { value: realPlatform, configurable: true });
  console.log('\n' + (tests.length - failed) + '/' + tests.length + ' collector checks passed.');
  process.exit(failed ? 1 : 0);
})();
