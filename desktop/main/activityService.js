'use strict';

const { powerMonitor } = require('electron');

let uIOhook = null;
let UiohookKey = null;
try {
  const mod = require('uiohook-napi');
  uIOhook = mod.uIOhook;
  UiohookKey = mod.UiohookKey;
} catch (err) {
  console.warn('uiohook-napi not loaded; keyboard/mouse activity counts will be 0:', err.message);
}

let activeWin = null;
try {
  activeWin = require('active-win');
} catch (err) {
  console.warn('active-win not loaded; active app/window will not be captured:', err.message);
}

// Windows-only browser URL capture via UI Automation (active-win returns URLs
// on macOS only).
const winUrl = require('./winUrl');

let started = false;
let keyboardCount = 0;
let mouseCount = 0;
let lastResetAt = Date.now();

function start() {
  if (started || !uIOhook) return;
  try {
    uIOhook.on('keydown', () => { keyboardCount += 1; });
    uIOhook.on('mousemove', () => { mouseCount += 1; });
    uIOhook.on('mousedown', () => { mouseCount += 1; });
    uIOhook.on('wheel', () => { mouseCount += 1; });
    uIOhook.start();
    started = true;
  } catch (err) {
    console.error('Failed to start uiohook:', err.message);
  }
}

function stop() {
  if (!started || !uIOhook) return;
  try {
    uIOhook.stop();
  } catch (err) {
    console.error('Failed to stop uiohook:', err.message);
  } finally {
    started = false;
    keyboardCount = 0;
    mouseCount = 0;
  }
}

function snapshotAndReset() {
  const now = Date.now();
  const elapsedSec = Math.max(1, Math.round((now - lastResetAt) / 1000));
  const idleSeconds = (() => {
    try { return powerMonitor.getSystemIdleTime(); } catch { return 0; }
  })();
  const snapshot = {
    keyboard_count: keyboardCount,
    mouse_count: mouseCount,
    idle_seconds: Math.min(idleSeconds, elapsedSec),
    elapsed_seconds: elapsedSec,
  };
  keyboardCount = 0;
  mouseCount = 0;
  lastResetAt = now;
  return snapshot;
}

/**
 * Activity percent = portion of interval where the user was NOT idle.
 * 0 = entirely idle, 100 = no idle time.
 */
function computeActivityPercent({ idle_seconds, elapsed_seconds }) {
  if (!elapsed_seconds) return 0;
  const active = Math.max(0, elapsed_seconds - idle_seconds);
  return Math.max(0, Math.min(100, Math.round((active / elapsed_seconds) * 100)));
}

// Normalise a process/app name (e.g. "chrome.exe" -> "Google Chrome").
const BROWSER_NAMES = {
  'chrome.exe': 'Google Chrome',
  'msedge.exe': 'Microsoft Edge',
  'firefox.exe': 'Mozilla Firefox',
  'brave.exe': 'Brave',
  'opera.exe': 'Opera',
  'vivaldi.exe': 'Vivaldi',
};

function normaliseApp(name) {
  if (!name) return null;
  const key = name.toLowerCase();
  if (BROWSER_NAMES[key]) return BROWSER_NAMES[key];
  // Strip a trailing ".exe" for a cleaner label.
  return name.replace(/\.exe$/i, '').slice(0, 255) || null;
}

// active-win only fills `url` on macOS. As a Windows-safe fallback we parse an
// explicit http(s):// URL out of the window title when one is present (some
// sites and address-bar extensions surface it). We never guess from bare
// domains/emails to avoid recording the wrong host.
function domainFromTitle(title) {
  if (!title) return null;
  const match = title.match(/https?:\/\/[^\s"']+/i);
  if (!match) return null;
  try {
    return new URL(match[0]).hostname || null;
  } catch {
    return null;
  }
}

async function activeWindowInfo() {
  if (!activeWin) {
    return { active_app: null, active_window_title: null, url_domain: null };
  }
  try {
    const win = await activeWin();
    if (!win) return { active_app: null, active_window_title: null, url_domain: null };

    const title = (win.title || '').slice(0, 255) || null;
    const appName = win.owner?.name;

    let urlDomain = null;
    if (win.url) {
      try { urlDomain = new URL(win.url).hostname; } catch { /* ignore */ }
    }
    if (!urlDomain) {
      urlDomain = domainFromTitle(win.title);
    }
    // Windows: active-win never fills `url`, so read the browser address bar
    // via UI Automation when the foreground app is a browser.
    if (!urlDomain && process.platform === 'win32' && winUrl.isBrowser(appName)) {
      try {
        urlDomain = await winUrl.getBrowserDomain(appName);
      } catch {
        urlDomain = null;
      }
    }

    return {
      active_app: normaliseApp(appName),
      active_window_title: title,
      url_domain: urlDomain ? urlDomain.slice(0, 255) : null,
    };
  } catch (err) {
    return { active_app: null, active_window_title: null, url_domain: null };
  }
}

function getSystemIdleSeconds() {
  try { return powerMonitor.getSystemIdleTime(); } catch { return 0; }
}

module.exports = {
  start,
  stop,
  snapshotAndReset,
  computeActivityPercent,
  activeWindowInfo,
  getSystemIdleSeconds,
};
