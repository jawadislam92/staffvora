'use strict';

const os = require('node:os');
const path = require('node:path');
const { app, BrowserWindow, shell } = require('electron');
const { isDev } = require('./config');
const queue = require('./queue');
const diag = require('./diag');
const ipc = require('./ipc');
const store = require('./store');
const tray = require('./tray');
const updater = require('./updater');
const report = require('./report');
const collector = require('./collector');

const PROTOCOL = 'tracksuite';

// How often buffered telemetry is pushed, and how often the agent posts a
// compact state snapshot. The snapshot is deliberately HOURLY, not per-minute:
// at fleet scale (hundreds of orgs x 100+ agents) a chattier cadence would turn
// the diagnostics table into a firehose the server has to prune.
const TELEMETRY_FLUSH_MS = 60_000;
const AGENT_STATE_MS = 60 * 60_000;

// Capture crashes so they reach the server diagnostics instead of vanishing on
// the employee's PC. We report and KEEP RUNNING — a stray error in a background
// module must never take down a running tracker.
process.on('uncaughtException', (err) => {
  try {
    report.error('uncaughtException', err && err.message, {
      stack: err && err.stack ? String(err.stack).split('\n').slice(0, 8).join('\n') : null,
    });
  } catch { /* best-effort */ }
});
process.on('unhandledRejection', (reason) => {
  try {
    report.error('unhandledRejection', reason && reason.message ? reason.message : String(reason), {
      stack: reason && reason.stack ? String(reason.stack).split('\n').slice(0, 8).join('\n') : null,
    });
  } catch { /* best-effort */ }
});

/**
 * Compact "is this agent healthy" snapshot: versions, platform, tracker state,
 * pending upload backlog and the last error seen. This is what makes a silent
 * machine diagnosable — the server watchdog can see the agent is alive and
 * whether its queue is stuck, without anyone visiting the PC.
 *
 * Contains no window titles, no file paths, no account identifiers.
 */
function reportAgentState(trigger) {
  try {
    const tracker = require('./trackerService');
    const status = tracker.status();
    const pending = status.pending || {};
    const telemetry = report.stats();
    // A compliance collector that has gone quiet (no entitlement) or is backing
    // off looks identical to one that is working, unless we say so here.
    const compliance = collector.stats();
    report.info('agent_state', 'agent health snapshot (' + (trigger || 'timer') + ')', {
      trigger: trigger || 'timer',
      app_version: app.getVersion ? app.getVersion() : telemetry.app_version,
      electron: process.versions.electron || null,
      os_release: os.release(),
      arch: process.arch,
      uptime_seconds: Math.round(process.uptime()),
      tracking: !!status.running,
      paused: !!status.paused,
      session_id: status.session ? status.session.id : null,
      pending_screenshots: pending.screenshots ?? null,
      pending_samples: pending.samples ?? null,
      pending_heartbeats: pending.heartbeats ?? null,
      capture_enabled: status.settings ? status.settings.capture_enabled !== false : null,
      telemetry_buffered: telemetry.buffered,
      last_error_event: telemetry.last_error_event,
      compliance: compliance.last_result,
      compliance_ext: compliance.extensions_kept + '/' + compliance.extensions_raw,
    });
  } catch { /* a snapshot must never break startup */ }
}

let mainWindow = null;
let pendingDeepLink = null;

// ---- Single instance + protocol registration -------------------------------
// A second launch (including the browser invoking tracksuite://...) focuses
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
    // Scope the local diagnostic log to the remembered employee straight away so
    // a shared PC never mixes two people's capture logs.
    const remembered = store.get('user');
    if (remembered && remembered.id) diag.setUser(remembered.id);
    ipc.register();
    createWindow();
    updater.init();

    report.info('app_ready', 'desktop app started', {
      version: app.getVersion ? app.getVersion() : null,
      platform: process.platform,
    });
    // Push buffered telemetry periodically. Fire-and-forget on purpose: report
    // .flush() never rejects into the timer, and back-pressure/backoff is
    // handled inside the reporter.
    setInterval(() => { report.flush().catch(() => {}); }, TELEMETRY_FLUSH_MS);
    // One state snapshot shortly after launch, then hourly.
    setTimeout(() => reportAgentState('launch'), 30_000);
    setInterval(() => reportAgentState('timer'), AGENT_STATE_MS);

    // Machine-compliance endpoint inventory. Deliberately started LAST and
    // never awaited: the first sweep is 45s out, the routine sweep is hourly
    // with jitter, and a browser-profile watcher uploads within ~60s of an
    // extension being installed. Windows-only, and a no-op for a workspace
    // without the `machine_compliance` entitlement (the server answers 403 and
    // the collector goes quiet). Nothing here can delay or fail startup.
    collector.init();

    tray.init(() => mainWindow, !!(store.get('prefs') || {}).minimizeToTray);

    // A renderer or GPU process dying is the classic "app looks open but does
    // nothing" failure. Report it instead of leaving a white window nobody can
    // explain. `reason` is an Electron enum — no user content.
    app.on('render-process-gone', (_evt, _contents, details) => {
      report.error('render_process_gone', details && details.reason ? details.reason : 'unknown', {
        reason: details && details.reason ? details.reason : null,
        exit_code: details && typeof details.exitCode === 'number' ? details.exitCode : null,
      });
    });
    app.on('child-process-gone', (_evt, details) => {
      report.warn('child_process_gone', (details && details.type ? details.type : 'process') + ': ' + (details && details.reason ? details.reason : 'unknown'), {
        type: details && details.type ? details.type : null,
        reason: details && details.reason ? details.reason : null,
      });
    });

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
      action: parsed.hostname || 'open', // tracksuite://start -> "start"
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
    title: 'Tracksuite Desktop',
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

  // A hung UI thread on an employee machine is otherwise invisible to us.
  mainWindow.on('unresponsive', () => {
    report.warn('window_unresponsive', 'main window stopped responding');
  });

  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}
