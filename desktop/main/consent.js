'use strict';

// Monitoring consent — the first-run gate.
//
// WHY THE GATE IS HERE AND NOT IN THE INSTALLER
// This app can record screenshots, activity levels and the names of the apps
// and sites someone works in. Consent to that has to come from the PERSON being
// monitored, and it has to be provable. An NSIS installer cannot deliver either:
// it runs ONCE PER MACHINE, usually under an IT account, often silently as part
// of a fleet rollout or an auto-update — so an "I agree" there is, at best, an
// IT technician agreeing on behalf of someone who has not been told anything.
// It also cannot be recorded against a user account, because at install time
// nobody has signed in yet.
//
// The first-run window can do all of it: it is PER USER, it runs after sign-in
// so we know exactly who is agreeing, it can show the disclosure and the real
// links, it blocks until the box is actively ticked, and it can post the
// acceptance to the server where it survives a reinstall. The installer's job is
// therefore only to make sure nobody is SURPRISED — it shows a notice that this
// PC will run monitoring software and that each user must accept the terms on
// first launch (build/installer.nsh).
//
// HARD RULES
//   * The box is NEVER pre-ticked and there is no "by continuing you agree"
//     path. The renderer enforces it visually; this module enforces it in fact
//     by refusing to record anything without an explicit accept() call.
//   * The SERVER decides which version is required. New terms => the whole
//     installed fleet re-asks on next launch, with no new build.
//   * Consent is stored PER USER ID, never per machine. A shared PC must not
//     let one employee's acceptance stand for the next person to sign in — that
//     is exactly the "consent you cannot defend" this feature exists to avoid.
//   * Offline is not a bypass. With no server we still show the gate, using the
//     bundled fallback document, record locally, and sync when the network is
//     back. What we must never do is let an unreachable server mean "no consent
//     needed".
//   * Nothing here may break the tracker: every entry point is wrapped, the
//     sync is fire-and-forget, and a failure leaves a pending record rather than
//     throwing into the launch path.

const os = require('node:os');
const api = require('./api');
const store = require('./store');
const report = require('./report');

/**
 * Offline fallback ONLY. The server's copy (App\Models\DesktopConsent) is
 * authoritative; this exists so an employee whose first launch has no network
 * still sees an honest disclosure instead of a spinner or, worse, a silent
 * start. Keep it in step with the server constants when they change — the
 * version below is what an offline acceptance is recorded against, and if it
 * lags the server the employee is simply asked again once online, which is the
 * safe direction to fail in.
 */
const BUNDLED_DOCUMENT = {
  version: '2026-08-09',
  effective_date: '9 August 2026',
  terms_url: 'https://staffvora.com/terms',
  privacy_url: 'https://staffvora.com/privacy',
  checkbox_label: 'I have read and agree to the Terms of Service and the Privacy Policy, and I understand what is monitored.',
  disclosure: [
    {
      title: 'Working time',
      body: 'When you start and stop the tracker, how long you work, and the client or task you assign that time to.',
    },
    {
      title: 'Activity level',
      body: 'How often you use the keyboard and mouse while the tracker is running — as counts, to produce an activity percentage. The keys you press are never recorded.',
    },
    {
      title: 'Screenshots — only if your employer turns them on',
      body: 'Periodic pictures of your screen while the tracker is running. They can be blurred by your employer. They are never taken while the tracker is stopped or paused.',
    },
    {
      title: 'Apps and websites — only if your employer turns them on',
      body: 'The name of the application or website you are working in, and the window title. Page contents, messages and passwords are not recorded.',
    },
    {
      title: 'Your device',
      body: 'Your computer name, operating system and app version, so problems with the tracker can be diagnosed. On workspaces using Machine Compliance, the browser extensions and installed programs on this PC are also listed.',
    },
    {
      title: 'Who can see it',
      body: 'Administrators and managers of your workspace, and Staffvora staff acting on their instructions to support the service. Your data is not sold and is not shared with other workspaces.',
    },
    {
      title: 'When nothing is recorded',
      body: 'Stop the tracker and capture stops — no screenshots, no activity, no app names. Time you have not started is not tracked.',
    },
  ],
};

/** electron-store key holding the per-user local acceptance ledger. */
const STORE_KEY = 'consentAcceptances';

/** Cap on locally remembered users, so a shared PC's config cannot grow forever. */
const MAX_LOCAL_RECORDS = 50;

/** Gap between background attempts to push a locally-recorded consent. */
const SYNC_RETRY_MS = 5 * 60_000;

let syncTimer = null;
let syncing = false;

/**
 * The most recent document the gate rendered. The renderer asks the MAIN
 * process to open the terms/privacy links by NAME ('terms' | 'privacy') rather
 * than by URL, so a compromised renderer cannot turn the consent screen into a
 * launcher for arbitrary external URLs. This is the allow-list it resolves
 * against.
 */
let lastDocument = BUNDLED_DOCUMENT;

function appVersion() {
  try {
    return require('../package.json').version;
  } catch {
    return null;
  }
}

function deviceName() {
  try {
    return store.get('deviceName') || os.hostname();
  } catch {
    return null;
  }
}

/** The signed-in user's id, or null. Consent is meaningless without one. */
function currentUserId() {
  try {
    const user = store.get('user');
    return user && user.id ? String(user.id) : null;
  } catch {
    return null;
  }
}

/** @returns {Record<string, object>} the whole local ledger, keyed by user id. */
function readLedger() {
  try {
    const raw = store.get(STORE_KEY);
    return raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {};
  } catch {
    return {};
  }
}

function writeLedger(ledger) {
  try {
    const keys = Object.keys(ledger);
    if (keys.length > MAX_LOCAL_RECORDS) {
      // Drop the oldest acceptances; the server holds the durable copy, this is
      // only a cache so a returning user is not re-asked while offline.
      keys
        .sort((a, b) => Date.parse(ledger[a]?.accepted_at || 0) - Date.parse(ledger[b]?.accepted_at || 0))
        .slice(0, keys.length - MAX_LOCAL_RECORDS)
        .forEach((key) => { delete ledger[key]; });
    }
    store.set(STORE_KEY, ledger);
  } catch { /* a cache write must never break the gate */ }
}

/** The local acceptance record for the signed-in user, or null. */
function localRecord(userId) {
  const id = userId || currentUserId();
  if (!id) return null;
  const record = readLedger()[id];
  return record && typeof record === 'object' ? record : null;
}

/**
 * What the agent must show, and whether this user has already agreed to it.
 *
 * Always resolves — never throws into the launch path. When the server cannot
 * be reached the bundled document is returned with `offline: true`, and the
 * caller still shows the gate.
 *
 * @returns {Promise<{document: object, accepted: boolean, accepted_at: ?string, offline: boolean, pending_sync: boolean}>}
 */
async function status() {
  const userId = currentUserId();
  const local = localRecord(userId);

  // Not signed in: there is nobody to record a consent for. The caller shows
  // the login screen first; this is only a defensive answer.
  if (!userId) {
    return {
      document: BUNDLED_DOCUMENT,
      accepted: false,
      accepted_at: null,
      offline: true,
      pending_sync: false,
    };
  }

  let server = null;
  try {
    server = await api.getConsent();
  } catch {
    server = null;
  }

  if (!server || !server.document || !server.document.version) {
    // OFFLINE PATH. An unreachable server must not mean "no consent needed",
    // so we fall back to the bundled document and only treat the user as having
    // accepted if their LOCAL record matches that same bundled version.
    const acceptedOffline = !!local && local.version === BUNDLED_DOCUMENT.version;
    lastDocument = BUNDLED_DOCUMENT;
    return {
      document: BUNDLED_DOCUMENT,
      accepted: acceptedOffline,
      accepted_at: acceptedOffline ? local.accepted_at || null : null,
      offline: true,
      pending_sync: !!local && local.synced === false,
    };
  }

  const required = server.document.version;
  lastDocument = server.document;

  // The server's answer wins. A local record for a superseded version is not
  // consent to the current one.
  const accepted = server.accepted === true;

  // Keep the local cache honest: if the server says they accepted, remember it
  // so a later offline launch does not re-ask them for a version they have
  // already agreed to.
  if (accepted && (!local || local.version !== required || local.synced === false)) {
    const ledger = readLedger();
    ledger[userId] = {
      version: required,
      accepted_at: server.accepted_at || new Date().toISOString(),
      device_name: deviceName(),
      synced: true,
    };
    writeLedger(ledger);
  }

  // A local acceptance the server has not got yet (recorded offline) — push it.
  if (!accepted && local && local.version === required && local.synced === false) {
    scheduleSync();
  }

  return {
    document: server.document,
    accepted,
    accepted_at: server.accepted_at || null,
    offline: false,
    pending_sync: !!local && local.synced === false,
  };
}

/**
 * Record that this user actively ticked the box for `version`.
 *
 * Called ONLY from the renderer's explicit "Agree and continue" action. There is
 * no implicit caller anywhere in the app, and there must never be one.
 *
 * Writes locally first so an acceptance is never lost to a flaky network, then
 * pushes to the server. A failed push leaves `synced: false` and a retry timer.
 *
 * @returns {Promise<{ok: boolean, synced: boolean, version: string, stale?: boolean, document?: object}>}
 */
async function accept(version) {
  const userId = currentUserId();

  if (!userId) {
    return { ok: false, synced: false, version: version || null, error: 'not_signed_in' };
  }

  const accepted_at = new Date().toISOString();
  const ledger = readLedger();
  ledger[userId] = {
    version,
    accepted_at,
    device_name: deviceName(),
    synced: false,
  };
  writeLedger(ledger);

  try {
    await api.sendConsent({
      version,
      device_name: deviceName(),
      platform: process.platform,
      app_version: appVersion(),
      at: accepted_at,
    });
    ledger[userId].synced = true;
    writeLedger(ledger);
    report.info('consent_accepted', 'monitoring terms accepted', {
      version,
      synced: true,
    });
    return { ok: true, synced: true, version };
  } catch (err) {
    // 409 = the server moved to a newer version between the screen rendering
    // and the tick. Do not pretend it was recorded: hand the caller the current
    // document so the employee is asked about the terms that actually apply.
    const stale = err?.response?.status === 409;
    if (stale) {
      delete ledger[userId];
      writeLedger(ledger);
      report.warn('consent_version_stale', 'consent screen showed a superseded version', {
        shown: version,
        required: err?.response?.data?.document?.version || null,
      });
      return {
        ok: false,
        synced: false,
        stale: true,
        version,
        document: err?.response?.data?.document || null,
      };
    }

    // Offline / server busy. The acceptance stands locally and is retried.
    report.warn('consent_sync_pending', 'consent recorded locally, not yet uploaded', {
      version,
      status: err?.response?.status || null,
    });
    scheduleSync();
    return { ok: true, synced: false, version };
  }
}

/**
 * Push a locally-recorded acceptance that has not reached the server yet.
 * Fire-and-forget by contract: resolves even when the upload fails.
 */
async function sync() {
  if (syncing) return;

  const userId = currentUserId();
  if (!userId) return;
  if (!store.get('token')) return; // signed out — nothing to authenticate with

  const ledger = readLedger();
  const record = ledger[userId];
  if (!record || record.synced !== false || !record.version) return;

  syncing = true;
  try {
    await api.sendConsent({
      version: record.version,
      device_name: record.device_name || deviceName(),
      platform: process.platform,
      app_version: appVersion(),
      at: record.accepted_at,
    });
    record.synced = true;
    writeLedger(ledger);
    report.info('consent_synced', 'offline consent uploaded', { version: record.version });
    clearSync();
  } catch (err) {
    if (err?.response?.status === 409) {
      // The terms moved on while this machine was offline. Drop the stale local
      // record so the employee is asked again rather than silently treated as
      // having consented to terms they never saw.
      delete ledger[userId];
      writeLedger(ledger);
      report.warn('consent_sync_stale', 'offline consent was for a superseded version', {
        version: record.version,
      });
      clearSync();
    }
    // Anything else: leave it pending, the timer will try again.
  } finally {
    syncing = false;
  }
}

function scheduleSync() {
  if (syncTimer) return;
  syncTimer = setInterval(() => { sync().catch(() => {}); }, SYNC_RETRY_MS);
  syncTimer.unref?.();
}

function clearSync() {
  if (!syncTimer) return;
  clearInterval(syncTimer);
  syncTimer = null;
}

/**
 * Resolve 'terms' | 'privacy' to an https URL from the document the gate last
 * rendered. Anything that is not a plain http(s) URL is refused — a server that
 * has been tampered with must not be able to hand the agent a `file://` or
 * custom-scheme link and have the app launch it.
 *
 * @returns {?string}
 */
function documentUrl(kind) {
  const doc = lastDocument || BUNDLED_DOCUMENT;
  const raw = kind === 'privacy' ? doc.privacy_url : kind === 'terms' ? doc.terms_url : null;
  if (!raw || typeof raw !== 'string') return null;
  try {
    const parsed = new URL(raw);
    if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') return null;
    return parsed.toString();
  } catch {
    return null;
  }
}

/**
 * Synchronous "has the signed-in employee accepted the version we are currently
 * required to show?" — for background collectors that must not start capturing
 * anything before the gate has been passed, and cannot afford a network round
 * trip to find out.
 *
 * Reads the LOCAL cache against the last document `status()` rendered. That is
 * deliberately conservative in the right direction: before the renderer's first
 * status() call the comparison is against the bundled version, so an unknown
 * state reads as NOT accepted and the collector stays quiet.
 */
function hasAccepted() {
  try {
    const record = localRecord();
    if (!record || !record.version) return false;
    const required = (lastDocument || BUNDLED_DOCUMENT).version;
    return record.version === required;
  } catch {
    return false;
  }
}

/** Called after sign-in: a different employee may now be at this PC. */
function onSignIn() {
  const record = localRecord();
  if (record && record.synced === false) scheduleSync();
}

/** Test hook — resets module state between self-test cases. */
function _reset() {
  clearSync();
  syncing = false;
  lastDocument = BUNDLED_DOCUMENT;
}

module.exports = {
  status,
  accept,
  sync,
  onSignIn,
  localRecord,
  hasAccepted,
  documentUrl,
  BUNDLED_DOCUMENT,
  STORE_KEY,
  MAX_LOCAL_RECORDS,
  SYNC_RETRY_MS,
  _reset,
};
