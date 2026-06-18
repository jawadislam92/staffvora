'use strict';

const os = require('node:os');
const { ipcMain, BrowserWindow, app, safeStorage } = require('electron');
const api = require('./api');
const store = require('./store');
const tracker = require('./trackerService');
const tray = require('./tray');
const updater = require('./updater');

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
    return user;
  });
  ipcMain.handle('auth:logout', async () => {
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
  ipcMain.handle('timeclock:act', async (_evt, actionType) => api.timeClockAct(actionType));

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
