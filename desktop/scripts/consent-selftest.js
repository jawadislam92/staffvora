'use strict';

/*
 * Standalone regression test for the monitoring-consent gate, run with plain
 * node (no Electron, no test framework). The module's collaborators (api,
 * store, report) are mocked via require.cache so the REAL main/consent.js is
 * exercised.
 *
 * This is the code that decides whether an employee is monitored without ever
 * having been asked, so every check below corresponds to a specific way that
 * could happen:
 *
 *   1. per-user       — one person's acceptance must never cover the next
 *                       person to sign in on a shared PC
 *   2. version-exact  — an acceptance of old terms must not satisfy new terms
 *   3. fails closed   — an unreachable/errored server means NOT accepted, and
 *                       an offline machine still sees the gate
 *   4. never implicit — nothing records a consent except an explicit accept()
 *   5. no data loss   — an acceptance taken offline survives and is uploaded
 *   6. stale rejected — a 409 discards the local record instead of banking
 *                       consent to terms the person never saw
 *   7. link allow-list— only http(s) URLs from the served document open
 *   8. never throws   — a broken api/store cannot break the launch path
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

// ---- mock collaborators ----------------------------------------------------
let data = {};                 // the electron-store contents
let serverResponse = null;     // what api.getConsent() resolves to
let serverError = null;        // ...or rejects with
let postError = null;          // what api.sendConsent() rejects with
let posted = [];               // every accepted consent that reached the api
let opened = [];               // urls handed to shell.openExternal (via ipc)

const SERVER_DOC = {
  version: '2027-01-01',
  effective_date: '1 January 2027',
  terms_url: 'https://staffvora.com/terms',
  privacy_url: 'https://staffvora.com/privacy',
  checkbox_label: 'I have read and agree...',
  disclosure: [{ title: 'Working time', body: 'When you start and stop.' }],
};

mockModule('report', { info: () => {}, warn: () => {}, error: () => {} });
mockModule('store', {
  get: (key) => data[key],
  set: (key, value) => { data[key] = value; },
  delete: (key) => { delete data[key]; },
});
mockModule('api', {
  getConsent: async () => {
    if (serverError) throw serverError;
    return serverResponse;
  },
  sendConsent: async (payload) => {
    if (postError) throw postError;
    posted.push(payload);
    return { recorded: true };
  },
});

const consent = require(path.join(MAIN, 'consent.js'));

const BUNDLED = consent.BUNDLED_DOCUMENT.version;

function reset() {
  consent._reset();
  data = { token: 'test-token', deviceName: 'TEST-PC', user: { id: 7, name: 'Ann' } };
  serverResponse = null;
  serverError = new Error('offline');
  postError = null;
  posted = [];
  opened = [];
}

function httpError(status, body) {
  const err = new Error('HTTP ' + status);
  err.response = { status, data: body || {} };
  return err;
}

const tests = [];
const test = (name, fn) => tests.push([name, fn]);

// ---- 1. consent is per USER, never per machine ------------------------------
test('one employee\'s acceptance does not cover the next person on a shared PC', async () => {
  reset();
  await consent.accept(BUNDLED);                       // Ann accepts (offline)
  let state = await consent.status();
  assert.strictEqual(state.accepted, true, 'Ann is through the gate');

  data.user = { id: 99, name: 'Bilal' };               // shared PC changes hands
  state = await consent.status();
  assert.strictEqual(state.accepted, false, 'Bilal must be asked himself');
  assert.strictEqual(consent.localRecord()?.version, undefined, 'no record for Bilal');
});

test('an acceptance is recorded against the signed-in user id, not the machine', async () => {
  reset();
  await consent.accept(BUNDLED);
  const ledger = data[consent.STORE_KEY];
  assert.deepStrictEqual(Object.keys(ledger), ['7']);
  assert.strictEqual(ledger['7'].version, BUNDLED);
});

// ---- 2. the version must match EXACTLY --------------------------------------
test('an acceptance of superseded terms does not satisfy the current ones', async () => {
  reset();
  await consent.accept('1999-01-01');                  // some ancient version
  const state = await consent.status();
  assert.strictEqual(state.accepted, false, 'old consent must not carry forward');
});

test('the server decides the required version, so new terms re-ask the whole fleet', async () => {
  reset();
  await consent.accept(BUNDLED);                       // accepted offline
  serverError = null;
  serverResponse = { document: SERVER_DOC, accepted: false };   // server moved on
  const state = await consent.status();
  assert.strictEqual(state.document.version, SERVER_DOC.version);
  assert.strictEqual(state.accepted, false, 'the newer server version wins');
});

// ---- 3. fails closed --------------------------------------------------------
test('an unreachable server means the gate is shown, not skipped', async () => {
  reset();                                             // serverError set
  const state = await consent.status();
  assert.strictEqual(state.accepted, false);
  assert.strictEqual(state.offline, true);
  assert.ok(Array.isArray(state.document.disclosure) && state.document.disclosure.length > 0,
    'the bundled disclosure is shown so an offline first launch is still honest');
});

test('a signed-out app never reports consent', async () => {
  reset();
  delete data.user;
  const state = await consent.status();
  assert.strictEqual(state.accepted, false);
});

test('the server saying "accepted" is the only thing that opens the gate online', async () => {
  reset();
  serverError = null;
  serverResponse = { document: SERVER_DOC, accepted: true, accepted_at: '2027-01-02T00:00:00Z' };
  const state = await consent.status();
  assert.strictEqual(state.accepted, true);
  // ...and it is cached so a later offline launch does not re-ask.
  assert.strictEqual(data[consent.STORE_KEY]['7'].version, SERVER_DOC.version);
  assert.strictEqual(data[consent.STORE_KEY]['7'].synced, true);
});

// ---- 4. nothing records a consent implicitly --------------------------------
test('status() alone never records a consent', async () => {
  reset();
  await consent.status();
  await consent.status();
  assert.strictEqual(posted.length, 0, 'no acceptance was invented by merely asking');
  assert.strictEqual(data[consent.STORE_KEY], undefined, 'nothing written locally either');
});

test('accept() without a signed-in user records nothing', async () => {
  reset();
  delete data.user;
  const result = await consent.accept(BUNDLED);
  assert.strictEqual(result.ok, false);
  assert.strictEqual(posted.length, 0);
  assert.strictEqual(data[consent.STORE_KEY], undefined);
});

// ---- 5. an offline acceptance is never lost ---------------------------------
test('an acceptance taken offline is stored locally and uploaded later', async () => {
  reset();
  postError = new Error('network down');
  const result = await consent.accept(BUNDLED);
  assert.strictEqual(result.ok, true, 'the person consented — do not hold them at the gate');
  assert.strictEqual(result.synced, false);
  assert.strictEqual(data[consent.STORE_KEY]['7'].synced, false);

  postError = null;                                    // network comes back
  await consent.sync();
  assert.strictEqual(posted.length, 1);
  assert.strictEqual(posted[0].version, BUNDLED);
  assert.strictEqual(posted[0].device_name, 'TEST-PC');
  assert.strictEqual(data[consent.STORE_KEY]['7'].synced, true);
});

test('a synced acceptance is not uploaded again', async () => {
  reset();
  await consent.accept(BUNDLED);
  assert.strictEqual(posted.length, 1);
  await consent.sync();
  await consent.sync();
  assert.strictEqual(posted.length, 1, 'sync is a no-op once the server has it');
});

test('sync does nothing without a token', async () => {
  reset();
  postError = new Error('network down');
  await consent.accept(BUNDLED);
  delete data.token;
  postError = null;
  await consent.sync();
  assert.strictEqual(posted.length, 0, 'no token, no upload attempt');
});

test('the upload carries no user or tenant identifier — the server derives both', async () => {
  reset();
  await consent.accept(BUNDLED);
  const keys = Object.keys(posted[0]);
  for (const forbidden of ['user_id', 'organization_id', 'email', 'token']) {
    assert.ok(!keys.includes(forbidden), 'client-supplied identity leaked: ' + forbidden);
  }
});

// ---- 6. a stale version is discarded, never banked --------------------------
test('a 409 discards the local record so the person is asked about the real terms', async () => {
  reset();
  postError = httpError(409, { document: SERVER_DOC });
  const result = await consent.accept(BUNDLED);
  assert.strictEqual(result.ok, false);
  assert.strictEqual(result.stale, true);
  assert.strictEqual(result.document.version, SERVER_DOC.version);
  assert.strictEqual(data[consent.STORE_KEY]['7'], undefined, 'no consent was banked');
});

test('a 409 during a later sync also discards the stale local record', async () => {
  reset();
  postError = new Error('network down');
  await consent.accept(BUNDLED);
  assert.strictEqual(data[consent.STORE_KEY]['7'].synced, false);

  postError = httpError(409, { document: SERVER_DOC });
  await consent.sync();
  assert.strictEqual(data[consent.STORE_KEY]['7'], undefined, 'stale offline consent dropped');

  // ...and the gate then shows again.
  serverError = null;
  serverResponse = { document: SERVER_DOC, accepted: false };
  const state = await consent.status();
  assert.strictEqual(state.accepted, false);
});

// ---- 7. document links are an allow-list ------------------------------------
test('only the served http(s) documents can be opened, by name', async () => {
  reset();
  serverError = null;
  serverResponse = { document: SERVER_DOC, accepted: false };
  await consent.status();

  assert.strictEqual(consent.documentUrl('terms'), 'https://staffvora.com/terms');
  assert.strictEqual(consent.documentUrl('privacy'), 'https://staffvora.com/privacy');
  assert.strictEqual(consent.documentUrl('anything-else'), null);
  assert.strictEqual(consent.documentUrl(undefined), null);
});

test('a tampered server cannot make the app launch a non-web scheme', async () => {
  reset();
  serverError = null;
  serverResponse = {
    document: { ...SERVER_DOC, terms_url: 'file:///C:/Windows/System32/cmd.exe', privacy_url: 'javascript:alert(1)' },
    accepted: false,
  };
  await consent.status();
  assert.strictEqual(consent.documentUrl('terms'), null);
  assert.strictEqual(consent.documentUrl('privacy'), null);
  assert.strictEqual(opened.length, 0);
});

// ---- 8. never throws into the launch path -----------------------------------
test('a garbage server payload falls back to the bundled document', async () => {
  reset();
  serverError = null;
  serverResponse = { document: { nope: true }, accepted: true };   // no version
  const state = await consent.status();
  assert.strictEqual(state.accepted, false, 'a malformed payload must not open the gate');
  assert.strictEqual(state.document.version, BUNDLED);
});

test('a corrupt local ledger does not throw or open the gate', async () => {
  reset();
  data[consent.STORE_KEY] = 'not-an-object';
  const state = await consent.status();
  assert.strictEqual(state.accepted, false);
  const result = await consent.accept(BUNDLED);
  assert.strictEqual(result.ok, true);
});

test('the local ledger is capped so a shared PC cannot grow config forever', async () => {
  reset();
  for (let i = 1; i <= consent.MAX_LOCAL_RECORDS + 20; i++) {
    data.user = { id: i };
    // Distinct, increasing timestamps so the drop-oldest ordering is deterministic.
    await consent.accept(BUNDLED);
    data[consent.STORE_KEY][String(i)].accepted_at = new Date(1700000000000 + i * 1000).toISOString();
  }
  assert.ok(
    Object.keys(data[consent.STORE_KEY]).length <= consent.MAX_LOCAL_RECORDS,
    'ledger grew past its cap: ' + Object.keys(data[consent.STORE_KEY]).length
  );
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
  console.log('\n' + (tests.length - failed) + '/' + tests.length + ' consent checks passed.');
  process.exit(failed ? 1 : 0);
})();
