'use strict';

const path = require('node:path');
const { app, BrowserWindow, shell } = require('electron');
const { isDev } = require('./config');
const queue = require('./queue');
const ipc = require('./ipc');
const store = require('./store');
const tray = require('./tray');
const updater = require('./updater');

const PROTOCOL = 'timetracker';

let mainWindow = null;
let pendingDeepLink = null;

// ---- Single instance + protocol registration -------------------------------
// A second launch (including the browser invoking timetracker://...) focuses
// the existing window and forwards the URL instead of opening a duplicate.
const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  app.quit();
} else {
  registerProtocol();

  app.on('second-instance', (_event, argv) => {
    const url = extractDeepLink(argv);
    if (url) handleDeepLink(url);
    focusWindow();
  });

  // macOS delivers protocol URLs via open-url instead of argv.
  app.on('open-url', (event, url) => {
    event.preventDefault();
    handleDeepLink(url);
    focusWindow();
  });

  app.whenReady().then(() => {
    queue.init();
    ipc.register();
    createWindow();
    updater.init();

    tray.init(() => mainWindow, !!(store.get('prefs') || {}).minimizeToTray);

    // First launch may itself carry a protocol URL (cold start from browser).
    const url = extractDeepLink(process.argv);
    if (url) handleDeepLink(url);

    app.on('activate', () => {
      if (BrowserWindow.getAllWindows().length === 0) createWindow();
    });
  });

  app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') app.quit();
  });
}

function registerProtocol() {
  try {
    if (isDev && process.platform === 'win32') {
      // In dev the executable is electron.exe, so the protocol must point at
      // it with our app path as argument. Packaged builds register cleanly
      // via the installer; this is best-effort for local testing.
      app.setAsDefaultProtocolClient(PROTOCOL, process.execPath, [path.resolve(process.argv[1] || '.')]);
    } else {
      app.setAsDefaultProtocolClient(PROTOCOL);
    }
  } catch {
    // Non-fatal: launching from web simply won't work until installed.
  }
}

function extractDeepLink(argv) {
  return (argv || []).find((arg) => typeof arg === 'string' && arg.startsWith(`${PROTOCOL}://`)) || null;
}

function handleDeepLink(url) {
  let payload = { action: 'open' };
  try {
    const parsed = new URL(url);
    payload = {
      action: parsed.hostname || 'open', // timetracker://start -> "start"
      client_id: parsed.searchParams.get('client_id'),
      task_note: parsed.searchParams.get('note'),
    };
  } catch {
    // Malformed URL: still just focus the app.
  }

  if (mainWindow && !mainWindow.webContents.isLoading()) {
    mainWindow.webContents.send('deeplink', payload);
  } else {
    pendingDeepLink = payload;
  }
}

function focusWindow() {
  if (!mainWindow) return;
  if (mainWindow.isMinimized()) mainWindow.restore();
  mainWindow.show();
  mainWindow.focus();
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 980,
    height: 720,
    minWidth: 400,
    minHeight: 560,
    title: 'Timetracker Desktop',
    autoHideMenuBar: true,
    webPreferences: {
      preload: path.join(__dirname, '..', 'preload', 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: false,
    },
  });

  // Open external links in the system browser, not inside the app.
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });

  if (isDev) {
    mainWindow.loadURL('http://localhost:5173');
    mainWindow.webContents.openDevTools({ mode: 'detach' });
  } else {
    mainWindow.loadFile(path.join(__dirname, '..', 'renderer', 'dist', 'index.html'));
  }

  // Deliver a deep link that arrived before the renderer was ready.
  mainWindow.webContents.on('did-finish-load', () => {
    if (pendingDeepLink) {
      mainWindow.webContents.send('deeplink', pendingDeepLink);
      pendingDeepLink = null;
    }
  });

  // When the user enabled minimize-to-tray, minimizing hides the window;
  // the tray icon brings it back.
  mainWindow.on('minimize', () => {
    const prefs = store.get('prefs') || {};
    if (prefs.minimizeToTray) {
      mainWindow.hide();
    }
  });

  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}
