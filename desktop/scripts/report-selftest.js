'use strict';

/*
 * Standalone regression test for the diagnostics reporter, run with plain node
 * (no Electron, no test framework). The reporter's collaborators (config, api,
 * store, diag) are mocked via require.cache so the REAL report.js singleton is
 * exercised.
 *
 * These are the behaviours that must never regress, because each of them is a
 * way the telemetry pipeline could hurt the product it exists to protect:
 *   1. batch cap        — never post more than the server accepts (50)
 *   2. byte cap         — never post a body over 64 KB, even with huge stacks
 *   3. buffer cap       — an offline machine never grows memory without bound,
 *                         and the loss is reported rather than hidden
 *   4. coalescing       — a flapping error loop costs one buffer slot
 *   5. backoff          — repeated failures back off exponentially instead of
 *                         hammering the server every minute
 *   6. no data loss     — a failed flush keeps the buffer for the next attempt
 *   7. never throws     — a broken/absent API can't break the caller
 *   8. no secrets       — credential-ish context keys never leave the machine
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

let sent = [];        // [{ events, deviceName }]
let failNext = 0;     // how many upcoming sendDiagnostics calls should reject
let apiCalls = 0;     // every attempt, successful or not
let mockToken = 'test-token';

mockModule('diag', { log: () => {}, setUser: () => {}, LOG_PATH: '' });
mockModule('store', {
  get: (key) => (key === 'token' ? mockToken : key === 'deviceName' ? 'TEST-PC' : null),
  set: () => {},
  delete: () => {},
});
mockModule('api', {
  sendDiagnostics: async (events, deviceName) => {
    apiCalls++;
    if (failNext > 0) {
      failNext--;
      const err = new Error('network down');
      throw err;
    }
    sent.push({ events, deviceName });
    return { stored: events.length };
  },
});

const report = require(path.join(MAIN, 'report.js'));

function reset() {
  report._reset();
  sent = [];
  failNext = 0;
  apiCalls = 0;
  mockToken = 'test-token';
}

const tests = [];
const test = (name, fn) => tests.push([name, fn]);

// ---- 1. batch cap ----------------------------------------------------------
test('a flush never posts more than the server-side cap of 50 events', async () => {
  reset();
  for (let i = 0; i < 120; i++) report.info('evt_' + i, 'message ' + i);
  await report.flush();
  assert.strictEqual(sent.length, 1, 'one request per flush');
  assert.strictEqual(sent[0].events.length, report.FLUSH_BATCH);
  assert.strictEqual(sent[0].deviceName, 'TEST-PC');
  assert.strictEqual(report.stats().buffered, 120 - report.FLUSH_BATCH, 'unsent events stay buffered');
});

// ---- 2. byte cap -----------------------------------------------------------
test('a batch never exceeds the byte cap, even with oversized events', async () => {
  reset();
  const huge = 'x'.repeat(1900); // just under the per-message cap
  for (let i = 0; i < 50; i++) report.warn('big_' + i, huge + i);
  await report.flush();
  const bytes = Buffer.byteLength(JSON.stringify(sent[0].events));
  assert.ok(bytes <= report.MAX_BATCH_BYTES, 'body ' + bytes + ' > cap ' + report.MAX_BATCH_BYTES);
  assert.ok(sent[0].events.length < 50, 'byte cap trimmed the batch below the row cap');
});

test('one pathological event is truncated, not left to jam the queue', async () => {
  reset();
  // A single event bigger than the whole byte cap (message is capped at 2000
  // chars, so oversize has to come from context).
  report.error('giant', 'boom', { stack: 'y'.repeat(200_000) });
  report.info('after', 'still here');
  await report.flush();
  assert.strictEqual(sent.length, 1);
  assert.ok(Buffer.byteLength(JSON.stringify(sent[0].events)) <= report.MAX_BATCH_BYTES);
  assert.strictEqual(report.stats().buffered, 0, 'queue drained past the giant event');
});

// ---- 3. buffer cap + overflow accounting -----------------------------------
test('the buffer is hard-capped and the loss is reported, not hidden', async () => {
  reset();
  // Distinct messages so coalescing does not absorb them.
  for (let i = 0; i < report.MAX_BUFFER + 75; i++) report.info('offline_' + i, 'msg ' + i);
  assert.strictEqual(report._buffer().length, report.MAX_BUFFER, 'buffer capped');
  assert.strictEqual(report.stats().dropped, 75, 'dropped events counted');

  await report.flush();
  const names = sent[0].events.map((e) => e.event);
  assert.ok(names.includes('diagnostics_overflow') || report._buffer().some((e) => e.event === 'diagnostics_overflow'),
    'an overflow marker is queued so the server knows events were lost');
  assert.strictEqual(report.stats().dropped, 0, 'dropped counter resets once reported');
});

// ---- 4. coalescing ---------------------------------------------------------
test('a flapping identical error costs one buffer slot with a repeat count', async () => {
  reset();
  for (let i = 0; i < 500; i++) report.warn('screenshot_capture_failed', 'blocked by antivirus');
  assert.strictEqual(report._buffer().length, 1, 'identical repeats coalesce');
  assert.strictEqual(report._buffer()[0].context.repeat, 500);
});

// ---- 5. backoff ------------------------------------------------------------
test('repeated failures back off instead of retrying every tick', async () => {
  reset();
  failNext = 3;

  report.info('a', 'one');
  await report.flush();                     // failure 1 → backoff armed
  assert.strictEqual(sent.length, 0);
  assert.strictEqual(report.stats().consecutive_failures, 1);
  assert.ok(report.stats().backing_off, 'backoff armed after the first failure');

  // While backing off, a flush is a no-op — it must not even attempt a request.
  const callsSoFar = apiCalls;
  await report.flush();
  await report.flush();
  assert.strictEqual(report.stats().consecutive_failures, 1, 'no attempt made during backoff');
  assert.strictEqual(apiCalls, callsSoFar, 'the api was not called again while backing off');
});

test('a successful flush clears the backoff', async () => {
  reset();
  failNext = 1;
  report.info('a', 'one');
  await report.flush();                     // fails, arms backoff
  assert.ok(report.stats().backing_off);
  report._reset();                          // simulate the backoff window elapsing
  report.info('b', 'two');
  await report.flush();
  assert.strictEqual(report.stats().consecutive_failures, 0);
  assert.strictEqual(report.stats().backing_off, false);
});

// ---- 5b. request-rate floor ------------------------------------------------
test('back-to-back flush calls collapse into one request', async () => {
  reset();
  for (let i = 0; i < 200; i++) report.info('evt_' + i, 'message ' + i);
  // flush() is called by the 60s telemetry timer AND by every 15s queue drain
  // AND on sign-in — three callers must not mean three requests.
  await report.flush();
  await report.flush();
  await report.flush();
  assert.strictEqual(apiCalls, 1, 'a rate floor of ' + report.MIN_FLUSH_INTERVAL_MS + 'ms is enforced');
  assert.ok(report._buffer().length > 0, 'the rest is still queued for the next window');

  // Sign-out / session-stop may force past the floor (last chance to send)...
  await report.flush({ force: true });
  assert.strictEqual(apiCalls, 2, 'force bypasses the rate floor');
});

test('force never bypasses the failure backoff', async () => {
  reset();
  failNext = 1;
  report.info('a', 'one');
  await report.flush();               // fails → backoff armed
  const calls = apiCalls;
  report.info('b', 'two');
  await report.flush({ force: true });
  assert.strictEqual(apiCalls, calls, 'backoff protects the server even when forced');
});

// ---- 6. no data loss on failure -------------------------------------------
test('a failed flush keeps every event for the next attempt', async () => {
  reset();
  failNext = 1;
  report.info('keep_me', 'important');
  await report.flush();
  assert.strictEqual(sent.length, 0);
  assert.strictEqual(report._buffer().length, 1, 'nothing is dropped on failure');
  assert.strictEqual(report._buffer()[0].event, 'keep_me');
});

// ---- 7. never throws into the caller --------------------------------------
test('reporting and flushing never throw, whatever the api does', async () => {
  reset();
  // Circular context, undefined message, exotic values — none may throw.
  const circular = {}; circular.self = circular;
  report.info('weird', undefined, circular);
  report.error(Symbol.toStringTag ? 'sym' : 'sym', null, { n: NaN, d: new Date() });
  failNext = 1;
  await report.flush(); // rejection is swallowed
  assert.ok(true);
});

test('a flush without a session token is a no-op that keeps the buffer', async () => {
  reset();
  mockToken = null; // signed out
  report.info('pre_login', 'buffered before sign-in');
  await report.flush();
  assert.strictEqual(apiCalls, 0, 'nothing uploaded without a token');
  assert.strictEqual(report._buffer().length, 1, 'the event waits for sign-in');

  mockToken = 'test-token'; // sign-in flushes the backlog
  await report.flush();
  assert.strictEqual(sent.length, 1);
  assert.strictEqual(sent[0].events[0].event, 'pre_login');
});

// ---- 8. no secrets / PII ---------------------------------------------------
test('credential-looking context keys never leave the machine', async () => {
  reset();
  report.error('login_failed', 'nope', {
    password: 'hunter2',
    token: 'sanctum-plaintext',
    api_key: 'abc',
    email: 'someone@example.com',
    authorization: 'Bearer x',
    status: 401,
  });
  await report.flush();
  const ctx = sent[0].events[0].context;
  assert.deepStrictEqual(Object.keys(ctx), ['status'], 'only the safe key survives');
  const body = JSON.stringify(sent[0]);
  for (const secret of ['hunter2', 'sanctum-plaintext', 'someone@example.com', 'Bearer x']) {
    assert.ok(!body.includes(secret), 'leaked: ' + secret);
  }
});

test('event names, messages and context values are capped to the server limits', async () => {
  reset();
  report.warn('e'.repeat(300), 'm'.repeat(9000), { note: 'n'.repeat(9000), a: 1, b: 2, c: 3 });
  await report.flush();
  const e = sent[0].events[0];
  assert.strictEqual(e.event.length, 80);
  assert.strictEqual(e.message.length, 2000);
  assert.strictEqual(e.context.note.length, 200);
  assert.ok(['info', 'warn', 'error'].includes(e.level));
  assert.ok(typeof e.at === 'string' && e.at.endsWith('Z'));
  assert.ok(typeof e.app_version === 'string');
  assert.ok(typeof e.platform === 'string');
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
  console.log('\n' + (tests.length - failed) + '/' + tests.length + ' reporter checks passed.');
  process.exit(failed ? 1 : 0);
})();
