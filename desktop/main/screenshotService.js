'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { paths } = require('./config');
const { stitch } = require('./imageService');
const diag = require('./diag');

let screenshot = null;
try {
  screenshot = require('screenshot-desktop');
} catch (err) {
  console.warn('screenshot-desktop not loaded:', err.message);
}

async function writeOut(buffer, sessionId) {
  fs.mkdirSync(paths.screenshotsDir, { recursive: true });
  const filename = `${sessionId}-${Date.now()}.jpg`;
  const filePath = path.join(paths.screenshotsDir, filename);
  await fs.promises.writeFile(filePath, buffer);
  return { localPath: filePath, size: buffer.length };
}

/**
 * Capture the work screen(s) and write a single jpg to disk.
 *
 * On a single-monitor machine this is just the primary display (unchanged).
 * On an extended/dual-monitor setup it grabs every display and stitches them
 * side-by-side into one wide image, so someone working on their second screen
 * no longer looks "frozen" on a static primary-screen capture.
 *
 * Returns { localPath, size }. Always falls back to the primary display if
 * multi-capture or stitching fails, so a capture is never lost.
 */
async function capture({ sessionId }) {
  // Every capture leaves a line in the local diagnostic log, so when a machine
  // reports "screenshot_capture_failed" we can see exactly which stage failed
  // (helper missing, display enumeration, the capture call itself, or the
  // stitch) without visiting the PC.
  diag.log('capture(): start session=' + sessionId, 'screenshot-desktop=' + !!screenshot);

  if (!screenshot) {
    diag.log('capture(): NO capture method available');
    throw new Error('screenshot-desktop not available');
  }

  // Decide single vs multi. Detection failures fall back to single.
  let buffers = null;
  try {
    const displays = await screenshot.listDisplays();
    if (Array.isArray(displays) && displays.length > 1) {
      buffers = await screenshot.all({ format: 'png' });
    }
  } catch (err) {
    console.warn('multi-display detection failed, using primary:', err.message);
    diag.log('capture(): display enumeration failed:', err && err.message ? err.message : String(err));
    buffers = null;
  }

  if (!buffers || buffers.length <= 1) {
    let single;
    try {
      single = buffers && buffers.length === 1 ? buffers[0] : await screenshot({ format: 'jpg' });
    } catch (err) {
      // The classic antivirus / Controlled-Folder-Access symptom: the helper is
      // present but the capture itself is refused.
      diag.log('capture(): screenshot-desktop FAILED:', err && err.message ? err.message : String(err));
      throw err;
    }
    const out = await writeOut(single, sessionId);
    diag.log('capture(): OK (single) bytes=' + out.size);
    return out;
  }

  try {
    const out = await writeOut(await stitch(buffers), sessionId);
    diag.log('capture(): OK (multi x' + buffers.length + ') bytes=' + out.size);
    return out;
  } catch (err) {
    console.warn('multi-monitor stitch failed, using primary:', err.message);
    diag.log('capture(): multi-monitor stitch failed, using primary:', err && err.message ? err.message : String(err));
    const out = await writeOut(await screenshot({ format: 'jpg' }), sessionId);
    diag.log('capture(): OK (primary fallback) bytes=' + out.size);
    return out;
  }
}

module.exports = {
  capture,
  // Backwards-compatible alias; older callers used capturePrimary.
  capturePrimary: capture,
};
