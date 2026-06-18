'use strict';

const { app, BrowserWindow } = require('electron');

// Auto-update via electron-updater against the generic feed on the web app
// (https://timetracker.sparkingasia.com/desktop-updates — configured in
// package.json build.publish, baked into app-update.yml at package time).
// Windows installs updates in place; unsigned macOS builds cannot auto-update
// (Squirrel.Mac requires code signing), so this no-ops there.
//
// UX lives in the renderer: we broadcast progress/downloaded events and the
// Tracker view shows a progress banner with a "Restart now" action.

let autoUpdater = null;
let downloadedVersion = null;
let pendingVersion = null;
let checking = false;

function broadcast(payload) {
  for (const win of BrowserWindow.getAllWindows()) {
    win.webContents.send('updates:event', payload);
  }
}

function available() {
  if (!app.isPackaged) return false;
  if (process.platform === 'darwin') return false; // unsigned build — manual updates
  try {
    ({ autoUpdater } = require('electron-updater'));
    return true;
  } catch {
    return false;
  }
}

function init() {
  if (!available()) return;

  autoUpdater.autoDownload = true;
  autoUpdater.autoInstallOnAppQuit = true;
  // Let the feed move clients DOWN a version, not just up. This is what makes
  // rollback possible: if a release turns out broken, an admin republishes the
  // previous good build as the active version (latest.yml) and every client
  // reverts to it on the next check — without anyone reinstalling by hand.
  autoUpdater.allowDowngrade = true;

  autoUpdater.on('update-available', (info) => {
    pendingVersion = info?.version || null;
    broadcast({ type: 'available', version: pendingVersion });
  });

  autoUpdater.on('download-progress', (progress) => {
    broadcast({
      type: 'progress',
      version: pendingVersion,
      percent: Math.max(0, Math.min(100, Math.round(progress?.percent || 0))),
      bytesPerSecond: Math.round(progress?.bytesPerSecond || 0),
    });
  });

  autoUpdater.on('update-downloaded', (info) => {
    downloadedVersion = info?.version || pendingVersion;
    broadcast({ type: 'downloaded', version: downloadedVersion });
  });

  autoUpdater.on('error', () => {
    broadcast({ type: 'error' });
  });

  const check = () => {
    if (checking) return;
    checking = true;
    autoUpdater.checkForUpdates().catch(() => {}).finally(() => { checking = false; });
  };

  // First check shortly after launch (don't compete with startup I/O), then
  // every 4 hours while the app stays open.
  setTimeout(check, 15_000);
  setInterval(check, 4 * 3600 * 1000);
}

/**
 * Manual "Check for updates": resolves with a small status object the
 * renderer can show as a toast. Download progress arrives via events.
 */
async function checkNow() {
  if (!app.isPackaged) return { status: 'dev', message: 'Updates are disabled in development.' };
  if (process.platform === 'darwin') {
    return { status: 'manual', message: 'Mac updates are manual — download the new build from the website.' };
  }
  if (!available()) return { status: 'unavailable', message: 'Updater not available in this build.' };
  if (downloadedVersion) {
    return { status: 'ready', message: `Version ${downloadedVersion} is downloaded — restart to apply.` };
  }

  try {
    const result = await autoUpdater.checkForUpdates();
    const next = result?.updateInfo?.version;
    if (next && next !== app.getVersion()) {
      return { status: 'downloading', message: `Version ${next} found — downloading now.` };
    }
    return { status: 'latest', message: `You are on the latest version (v${app.getVersion()}).` };
  } catch (err) {
    return { status: 'error', message: 'Could not reach the update server.' };
  }
}

/** Apply a downloaded update immediately (renderer "Restart now" button). */
function installNow() {
  if (!downloadedVersion || !autoUpdater) return { ok: false };
  setImmediate(() => autoUpdater.quitAndInstall());
  return { ok: true };
}

module.exports = { init, checkNow, installNow };
