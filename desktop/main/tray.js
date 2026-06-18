'use strict';

const { Tray, Menu, nativeImage, app } = require('electron');

let tray = null;
let getWindowRef = () => null;

/**
 * Build a 16x16 purple-dot icon at runtime so we don't need a bundled asset.
 * createFromBitmap expects raw BGRA bytes.
 */
function buildIcon() {
  const size = 16;
  const buffer = Buffer.alloc(size * size * 4, 0);
  const cx = (size - 1) / 2;
  const cy = (size - 1) / 2;
  const radius = 6.5;

  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      const dist = Math.sqrt((x - cx) ** 2 + (y - cy) ** 2);
      if (dist <= radius) {
        const i = (y * size + x) * 4;
        buffer[i] = 0xff;     // B
        buffer[i + 1] = 0x68; // G
        buffer[i + 2] = 0x7a; // R
        buffer[i + 3] = dist > radius - 1 ? Math.round(255 * (radius - dist)) : 0xff; // soft edge
      }
    }
  }

  return nativeImage.createFromBitmap(buffer, { width: size, height: size });
}

function show() {
  const win = getWindowRef();
  if (!win) return;
  win.show();
  if (win.isMinimized()) win.restore();
  win.focus();
}

function create() {
  if (tray) return;
  tray = new Tray(buildIcon());
  tray.setToolTip('Timetracker Desktop');
  tray.setContextMenu(Menu.buildFromTemplate([
    { label: 'Open Timetracker', click: show },
    { type: 'separator' },
    { label: 'Quit', click: () => { app.isQuitting = true; app.quit(); } },
  ]));
  tray.on('click', show);
}

function destroy() {
  if (tray) {
    tray.destroy();
    tray = null;
  }
}

function init(getWindow, enabled) {
  getWindowRef = getWindow;
  if (enabled) create();
}

function setEnabled(enabled) {
  if (enabled) create();
  else destroy();
}

module.exports = { init, setEnabled, show };
