'use strict';

const os = require('node:os');
const { ipcMain, BrowserWindow, app, safeStorage, shell } = require('electron');
const api = require('./api');
const store = require('./store');
const diag = require('./diag');
const report = require('./report');
const tracker = require('./trackerService');
const tray = require('./tray');
const updater = require('./updater');
const collector = require('./collector');
const consent = require('./consent');

// Levels the renderer is allowed to raise. The renderer is the least trusted
// part of the agent, so its events are whitelisted and re-labelled here — it
// can never invent an arbitrary event name that the server-side watchdog reads.
const RENDERER_EVENTS = new Set(['renderer_error', 'renderer_unhandled_rejection', 'renderer_boot_failed']);

const PREF_DEFAULTS = {
  autoStartTracking: false,
  notifyScreenshot: null, // null = follow the admin/server setting
  idleNotifications: true,
  minimizeToTray: false,
  theme: 'cinematic', // appearance theme: cinematic | light | midnight
};

function broadcast(channel, payload) {
  for (const win of BrowserWindow.getAllWindows()) {
    win.webContents.send(channel, payload);
  }
}

// Saved sign-in credentials ("Remember me"): email in plain config, password
// encrypted with the OS keychain via safeStorage — never written in plaintext.
function saveCredentials(email, password) {
  try {
    if (!safeStorage.isEncryptionAvailable()) return;
    store.set('savedLogin', {
      email,
      passwordEnc: safeStorage.encryptString(password).toString('base64'),
    });
  } catch { /* remembering is best-effort */ }
}

function readCredentials() {
  const saved = store.get('savedLogin');
  if (!saved?.email || !saved?.passwordEnc) return null;
  try {
    const password = safeStorage.decryptString(Buffer.from(saved.passwordEnc, 'base64'));
    return { email: saved.email, password };
  } catch {
    return null;
  }
}

function register() {
  // ---- Auth ----
  ipcMain.handle('auth:login', async (_evt, payload) => {
    const user = await api.login(payload);
    if (payload?.remember) saveCredentials(payload.email, payload.password);
    else store.delete('savedLogin');
    // Scope the local capture log to this employee, then push whatever
    // telemetry was buffered before we had a token.
    try { diag.setUser(user.id); } catch { /* ignore */ }
    report.info('signed_in', 'employee signed in on this machine');
    report.flush().catch(() => {});
    // A shared PC can change hands mid-day, so the inventory has to be re-filed
    // under whoever is signed in now. Signing in also clears any entitlement
    // suppression, so an operator who just granted the add-on does not have to
    // wait out the collector's quiet window. Fire-and-forget, off the login path.
    collector.onSignIn();
    setTimeout(() => { collector.run('login').catch(() => {}); }, 5_000).unref?.();
    // A consent this employee gave while offline may still be waiting to reach
    // the server. Signing in is the first moment we have a token to send it with.
    try { consent.onSignIn(); } catch { /* never break sign-in */ }
    return user;
  });
  ipcMain.handle('auth:logout', async () => {
    // Best-effort: get this shift's telemetry up while the token is still valid.
    report.info('signed_out', 'employee signed out on this machine');
    try { await report.flush({ force: true }); } catch { /* ignore */ }
    await api.logout();
    return { ok: true };
  });
  ipcMain.handle('auth:me', async () => {
    const user = store.get('user');
    if (!user) return null;
    try {
      return await api.me();
    } catch {
      return user; // fall back to cached if offline
    }
  });
  ipcMain.handle('auth:state', () => ({
    user: store.get('user'),
    apiBaseUrl: store.get('apiBaseUrl') || require('./config').DEFAULTS.apiBaseUrl,
    deviceName: store.get('deviceName'),
    hostname: os.hostname(),
    hasToken: !!store.get('token'),
  }));
  ipcMain.handle('auth:saved', () => readCredentials());

  // Token rejected by the server → renderer drops to the login screen.
  api.authEvents.on('expired', () => broadcast('auth:expired'));

  // ---- Monitoring consent (first-run gate) ----
  // The renderer may ASK for the current document and REPORT an explicit tick.
  // It cannot record a consent any other way, and there is deliberately no
  // handler that marks someone as having consented without an accept() call.
  ipcMain.handle('consent:status', async () => {
    try {
      return await consent.status();
    } catch {
      // The gate must fail CLOSED: an error here means "not accepted", never
      // "carry on", because carrying on is monitoring somebody who was never
      // asked.
      return {
        document: consent.BUNDLED_DOCUMENT,
        accepted: false,
        accepted_at: null,
        offline: true,
        pending_sync: false,
      };
    }
  });

  ipcMain.handle('consent:accept', async (_evt, version) => {
    if (typeof version !== 'string' || !version.trim()) {
      return { ok: false, synced: false, version: null, error: 'missing_version' };
    }
    try {
      return await consent.accept(version.trim().slice(0, 40));
    } catch {
      return { ok: false, synced: false, version, error: 'accept_failed' };
    }
  });

  // Opened by NAME, never by URL: the renderer picks 'terms' or 'privacy' and
  // the main process resolves it against the document it last rendered. A
  // renderer that has been compromised therefore cannot use the consent screen
  // as a launcher for arbitrary external links.
  ipcMain.handle('consent:openDocument', (_evt, kind) => {
    const url = consent.documentUrl(kind === 'privacy' ? 'privacy' : 'terms');
    if (!url) return { ok: false };
    try {
      shell.openExternal(url);
      return { ok: true, url };
    } catch {
      return { ok: false };
    }
  });

  // ---- Updates ----
  ipcMain.handle('updates:check', () => updater.checkNow());
  ipcMain.handle('updates:install', () => updater.installNow());

  // ---- Settings (server-driven + local override) ----
  ipcMain.handle('settings:apiBaseUrl:set', (_evt, url) => {
    store.set('apiBaseUrl', String(url || '').replace(/\/+$/, ''));
    return { ok: true };
  });

  // Launch on system startup is a per-machine choice, applied directly to the
  // OS login items. In dev this registers the dev electron.exe (harmless);
  // packaged builds register the installed app.
  ipcMain.handle('settings:autoLaunch:get', () => {
    try {
      return { enabled: !!app.getLoginItemSettings().openAtLogin };
    } catch {
      return { enabled: false };
    }
  });
  ipcMain.handle('settings:autoLaunch:set', (_evt, enabled) => {
    try {
      app.setLoginItemSettings({ openAtLogin: !!enabled });
      return { ok: true, enabled: !!app.getLoginItemSettings().openAtLogin };
    } catch (err) {
      return { ok: false, error: err.message };
    }
  });

  // Local user preferences (per machine, not server-driven).
  ipcMain.handle('settings:prefs:get', () => ({ ...PREF_DEFAULTS, ...(store.get('prefs') || {}) }));
  ipcMain.handle('settings:prefs:set', (_evt, patch) => {
    const prefs = { ...PREF_DEFAULTS, ...(store.get('prefs') || {}), ...(patch || {}) };
    store.set('prefs', prefs);
    if (Object.prototype.hasOwnProperty.call(patch || {}, 'minimizeToTray')) {
      tray.setEnabled(!!prefs.minimizeToTray);
    }
    return prefs;
  });

  ipcMain.handle('app:version', () => app.getVersion());

  // ---- Meta ----
  ipcMain.handle('meta:clients', async () => api.getClients());
  ipcMain.handle('meta:workTypes', async () => api.getWorkTypes());
  ipcMain.handle('meta:upworkProfiles', async () => api.getUpworkProfiles());
  ipcMain.handle('meta:settings', async () => api.getSettings());
  ipcMain.handle('meta:todaySessions', async () => api.todaySessions());
  ipcMain.handle('meta:weekSummary', async () => api.weekSummary());
  ipcMain.handle('meta:recentClients', async () => api.recentClients());

  // ---- Attendance clock (clock in/out, breaks) ----
  ipcMain.handle('timeclock:status', async () => api.timeClockStatus());
  ipcMain.handle('timeclock:act', async (_evt, actionType) => {
    const res = await api.timeClockAct(actionType);
    // Break transitions are what stop the capture watchdog from crying
    // "antivirus block" at someone who is simply on a break, so the agent's
    // view of them is worth having next to the server's record.
    report.info('time_clock', 'clock action: ' + String(actionType).slice(0, 20), {
      action: String(actionType).slice(0, 20),
      tracking: !!tracker.status().running,
    });
    // Clocking in is the one moment we know for certain that this person is at
    // this PC and working, so it is the right time to make sure their machine
    // appears on the compliance dashboard for today. Debounced inside the
    // collector, so a clock-in right after the launch sweep costs nothing.
    if (actionType === 'clock_in') {
      setTimeout(() => { collector.run('clock_in').catch(() => {}); }, 3_000).unref?.();
    }
    return res;
  });

  // ---- Client-side (renderer) error reporting ----
  // The renderer forwards window.onerror / unhandledrejection here so a UI
  // crash (white screen, failed boot) is visible server-side instead of only to
  // the employee. Whitelisted event names, capped payload, best-effort.
  ipcMain.handle('diag:report', (_evt, payload) => {
    try {
      const event = RENDERER_EVENTS.has(payload?.event) ? payload.event : 'renderer_error';
      report.error(event, String(payload?.message ?? '').slice(0, 2000), {
        source: String(payload?.source ?? '').slice(0, 200) || null,
        line: Number.isFinite(payload?.line) ? payload.line : null,
        stack: payload?.stack ? String(payload.stack).split('\n').slice(0, 6).join('\n') : null,
      });
    } catch { /* reporting is best-effort */ }
    return { ok: true };
  });

  // ---- Tracker ----
  ipcMain.handle('tracker:start', async (_evt, opts) => {
    await tracker.start(opts || {});
    return tracker.status();
  });
  ipcMain.handle('tracker:stop', async (_evt, opts) => {
    await tracker.stop(opts || {});
    return tracker.status();
  });
  ipcMain.handle('tracker:status', () => tracker.status());

  tracker.on('changed', (status) => broadcast('tracker:changed', status));
  tracker.on('stopped', (session) => broadcast('tracker:stopped', session));
  tracker.on('warning', (msg) => broadcast('tracker:warning', msg));

  // Pull the latest server-driven settings at boot, then keep the settings
  // panel in sync while the app is idle (the tracker refreshes on its own
  // cadence while a session is running). This makes admin Settings changes
  // visible without restarting the desktop app.
  const syncSettings = () => {
    if (!store.get('token')) return;
    tracker.refreshSettings()
      .then(() => broadcast('tracker:changed', tracker.status()))
      .catch(() => {});
  };

  syncSettings();
  setInterval(() => {
    if (!tracker.status().running) syncSettings();
  }, 120_000);
}

module.exports = { register };
