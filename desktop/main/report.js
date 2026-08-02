'use strict';

// Client-side telemetry reporter.
//
// Buffers important events (crashes, screenshot capture failures / antivirus
// blocks, pause & break transitions, upload failures, updater errors) and
// uploads them in batches to POST /api/desktop/diagnostics, so a broken machine
// is diagnosable remotely instead of only in the local capture.log.
//
// Hard rules, all enforced here rather than by the caller:
//   * NEVER blocks or breaks tracking — every entry point is wrapped and every
//     flush is fire-and-forget. A diagnostics failure is invisible to the clock
//     and screenshot pipelines.
//   * NEVER grows unbounded — the buffer is capped (drop-oldest) and identical
//     repeated events are coalesced into one row with a repeat count, so a
//     flapping error loop costs one buffer slot, not a thousand.
//   * NEVER hammers the server — one flush in flight at a time, a batch cap
//     (server accepts max 50 events), a byte cap on the request body, and
//     exponential backoff with jitter after consecutive failures.
//   * NEVER carries secrets or PII beyond what the tracker already sends — the
//     context object is sanitized (shallow, key-capped, value-capped) and keys
//     that look like credentials are dropped outright.
//
// The organization/user is derived server-side from the Sanctum token; the
// agent never sends (and must never be trusted for) any tenant identifier.

const os = require('node:os');
const diag = require('./diag');
const api = require('./api');
const store = require('./store');

const MAX_BUFFER = 300;          // hard cap: an offline stretch can't grow unbounded
const FLUSH_BATCH = 50;          // server validation cap (events: max:50)
const MAX_BATCH_BYTES = 64 * 1024; // hard cap on the JSON body we will send
const MAX_MESSAGE = 2000;        // server validation cap
const MAX_EVENT_NAME = 80;       // server validation cap
const MAX_CONTEXT_KEYS = 12;
const MAX_CONTEXT_VALUE = 200;
const COALESCE_WINDOW_MS = 5 * 60_000;

const BASE_BACKOFF_MS = 60_000;  // first retry delay after a failure
const MAX_BACKOFF_MS = 15 * 60_000;
const ERROR_FLUSH_DEBOUNCE_MS = 30_000; // fastest an error may trigger an early flush
// Floor between requests regardless of who asks. flush() is called from the
// 60s telemetry timer, from every 15s queue drain, on sign-in/out and after an
// error — without this floor a machine with a backlog would post ~4x/min.
const MIN_FLUSH_INTERVAL_MS = 20_000;

// Anything whose key looks like a credential never leaves the machine, even if
// a future caller passes one in by accident.
const SECRET_KEY = /(pass|password|token|secret|key|auth|cookie|session_id|credential|email)/i;

let buffer = [];
let dropped = 0;          // events lost to the buffer cap since the last flush
let flushing = false;
let consecutiveFailures = 0;
let nextAttemptAt = 0;
let lastAttemptAt = 0;
let lastErrorFlushAt = 0;
let lastErrorEvent = null; // surfaced in the periodic agent_state snapshot

let appVersion = '?';
try { appVersion = require('../package.json').version; } catch { /* non-electron */ }

/** Shallow, capped, credential-stripped copy of a caller-supplied context. */
function sanitizeContext(context) {
  if (!context || typeof context !== 'object' || Array.isArray(context)) return null;
  const out = {};
  let keys = 0;
  for (const [key, value] of Object.entries(context)) {
    if (keys >= MAX_CONTEXT_KEYS) break;
    if (SECRET_KEY.test(key)) continue;
    if (value === null || value === undefined) continue;
    let clean;
    if (typeof value === 'number' || typeof value === 'boolean') {
      clean = value;
    } else if (typeof value === 'string') {
      clean = value.slice(0, MAX_CONTEXT_VALUE);
    } else {
      try { clean = JSON.stringify(value).slice(0, MAX_CONTEXT_VALUE); } catch { continue; }
    }
    out[String(key).slice(0, 40)] = clean;
    keys++;
  }
  return keys ? out : null;
}

/**
 * Record one event. Always safe to call from anywhere, including inside a catch
 * on the capture path — it only touches memory and the local log.
 */
function report(level, event, message, context) {
  try {
    diag.log(`[${level}] ${event}` + (message ? ' — ' + message : ''), context ? JSON.stringify(context) : '');
  } catch { /* logging is best-effort */ }

  try {
    const row = {
      level,
      event: String(event).slice(0, MAX_EVENT_NAME),
      message: message == null ? null : String(message).slice(0, MAX_MESSAGE),
      context: sanitizeContext(context),
      at: new Date().toISOString(),
      app_version: appVersion,
      platform: process.platform,
    };

    if (level === 'error' || level === 'warn') lastErrorEvent = row.event;

    // Coalesce a repeat of the newest buffered event (same level/event/message)
    // instead of appending. A tight failure loop — capture blocked every 20s,
    // an upload 500ing on every drain — then costs ONE slot with a repeat count
    // rather than flooding the buffer and pushing out unrelated history.
    const last = buffer[buffer.length - 1];
    if (last
      && last.level === row.level
      && last.event === row.event
      && last.message === row.message
      && Date.parse(row.at) - Date.parse(last.at) < COALESCE_WINDOW_MS) {
      last.context = { ...(last.context || {}), repeat: ((last.context && last.context.repeat) || 1) + 1 };
      last.at = row.at;
      return;
    }

    buffer.push(row);
    if (buffer.length > MAX_BUFFER) {
      const overflow = buffer.length - MAX_BUFFER;
      buffer.splice(0, overflow);
      dropped += overflow;
    }

    // A crash or hard error should reach the server promptly rather than wait
    // for the next 60s tick — but at most once per debounce window, and never
    // while a backoff is in effect.
    if (level === 'error') {
      const now = Date.now();
      if (now - lastErrorFlushAt >= ERROR_FLUSH_DEBOUNCE_MS) {
        lastErrorFlushAt = now;
        setTimeout(() => { flush().catch(() => {}); }, 1_500).unref?.();
      }
    }
  } catch { /* never throw into the caller */ }
}

const info = (event, message, context) => report('info', event, message, context);
const warn = (event, message, context) => report('warn', event, message, context);
const error = (event, message, context) => report('error', event, message, context);

/** Build the next batch, respecting both the row cap and the byte cap. */
function nextBatch() {
  const batch = [];
  let bytes = 2; // "[]"
  for (const row of buffer.slice(0, FLUSH_BATCH)) {
    let candidate = row;
    let size = byteLength(candidate);
    if (size > MAX_BATCH_BYTES) {
      // One pathological row (a giant stack) must not stall the queue forever:
      // send a truncated copy instead of dropping the signal entirely.
      candidate = { ...row, context: null, message: (row.message || '').slice(0, 500) };
      size = byteLength(candidate);
    }
    if (batch.length && bytes + size > MAX_BATCH_BYTES) break;
    batch.push(candidate);
    bytes += size + 1;
  }
  return batch;
}

function byteLength(value) {
  try { return Buffer.byteLength(JSON.stringify(value)); } catch { return MAX_MESSAGE; }
}

function backoffDelay(failures) {
  const raw = Math.min(BASE_BACKOFF_MS * Math.pow(2, Math.max(0, failures - 1)), MAX_BACKOFF_MS);
  const jitter = raw * 0.2 * (Math.random() - 0.5) * 2; // ±20% so a fleet can't sync up
  return Math.max(5_000, Math.round(raw + jitter));
}

/**
 * Push one batch. Best-effort by contract: resolves even when the upload fails.
 * Only what the server confirmed is dropped from the buffer.
 */
async function flush(opts = {}) {
  // `force` skips only the rate floor — used at sign-out and session stop,
  // where the buffer is about to become unsendable (token revoked / app
  // closing). It deliberately does NOT skip the failure backoff: backoff exists
  // to protect the server, and nothing on the client may override that.
  const force = opts && opts.force === true;

  if (flushing) return;
  if (!buffer.length && !dropped) return;
  if (!store.get('token')) return;      // not signed in yet — hold the buffer
  if (Date.now() < nextAttemptAt) return; // backing off after failures
  if (!force && Date.now() - lastAttemptAt < MIN_FLUSH_INTERVAL_MS) return; // rate floor

  // Tell the server we lost events rather than silently under-reporting.
  if (dropped) {
    const lost = dropped;
    dropped = 0;
    buffer.push({
      level: 'warn',
      event: 'diagnostics_overflow',
      message: `dropped ${lost} buffered event(s) — buffer cap ${MAX_BUFFER} reached (long offline stretch?)`,
      context: { dropped: lost },
      at: new Date().toISOString(),
      app_version: appVersion,
      platform: process.platform,
    });
  }

  const batch = nextBatch();
  if (!batch.length) return;

  flushing = true;
  lastAttemptAt = Date.now();
  try {
    await api.sendDiagnostics(batch, store.get('deviceName') || os.hostname());
    buffer.splice(0, batch.length); // only drop what we successfully sent
    consecutiveFailures = 0;
    nextAttemptAt = 0;
  } catch (err) {
    // Offline / server busy / rate limited — keep the (capped) buffer and back
    // off so a flapping network can never turn into a request storm.
    consecutiveFailures++;
    nextAttemptAt = Date.now() + backoffDelay(consecutiveFailures);
    try { diag.log('report: flush failed (' + (err && err.message ? err.message : err) + ') backoff=' + Math.round((nextAttemptAt - Date.now()) / 1000) + 's'); } catch { /* ignore */ }
  } finally {
    flushing = false;
  }
}

/** Snapshot used by the periodic agent_state event and the self-test. */
function stats() {
  return {
    buffered: buffer.length,
    dropped,
    consecutive_failures: consecutiveFailures,
    backing_off: Date.now() < nextAttemptAt,
    last_error_event: lastErrorEvent,
    app_version: appVersion,
  };
}

/** Test hook — resets module state between self-test cases. */
function _reset() {
  buffer = [];
  dropped = 0;
  flushing = false;
  consecutiveFailures = 0;
  nextAttemptAt = 0;
  lastAttemptAt = 0;
  lastErrorFlushAt = 0;
  lastErrorEvent = null;
}

module.exports = {
  report,
  info,
  warn,
  error,
  flush,
  stats,
  _reset,
  _buffer: () => buffer,
  MAX_BUFFER,
  FLUSH_BATCH,
  MAX_BATCH_BYTES,
  MIN_FLUSH_INTERVAL_MS,
};
