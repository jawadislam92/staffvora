'use strict';

// Local diagnostic log for the capture/tracking pipeline. Writes plain,
// timestamped lines to the app's userData folder so we can see *why* capture
// (or anything else) isn't happening on a given PC, instead of the error being
// swallowed. Everything here is wrapped: logging can NEVER break tracking.
//
// Retention is bounded from day one — the file rotates at ~1 MB to `.old` and
// the `.old` is overwritten, so the on-disk footprint is capped at ~2 MB per
// employee no matter how long the machine runs.

const fs = require('node:fs');
const path = require('node:path');
const { paths } = require('./config');

// On a shared PC the log is scoped to users/<id>/capture.log once we know who
// is signed in, so one employee's diagnostics don't mix with the next's. Until
// then it falls back to the shared root path.
let LOG_PATH = path.join(paths.userData, 'capture.log');
const MAX_BYTES = 1_000_000;

function setUser(userId) {
  try {
    const safe = String(userId || 'anonymous').replace(/[^A-Za-z0-9_-]/g, '_');
    const dir = path.join(paths.userData, 'users', safe);
    fs.mkdirSync(dir, { recursive: true });
    LOG_PATH = path.join(dir, 'capture.log');
  } catch { /* keep the shared path if the per-user dir can't be created */ }
}

function log(...parts) {
  try {
    try {
      const st = fs.statSync(LOG_PATH);
      if (st.size > MAX_BYTES) {
        try { fs.renameSync(LOG_PATH, LOG_PATH + '.old'); } catch { fs.truncateSync(LOG_PATH, 0); }
      }
    } catch { /* no file yet */ }
    const msg = parts
      .map((p) => (typeof p === 'string' ? p : safeJson(p)))
      .join(' ');
    fs.appendFileSync(LOG_PATH, `[${new Date().toISOString()}] ${msg}\n`);
  } catch {
    /* never let logging break the tracker */
  }
}

function safeJson(value) {
  try { return JSON.stringify(value); } catch { return String(value); }
}

module.exports = { log, setUser, get LOG_PATH() { return LOG_PATH; } };
