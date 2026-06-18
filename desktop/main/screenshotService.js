'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { paths } = require('./config');

let screenshot = null;
try {
  screenshot = require('screenshot-desktop');
} catch (err) {
  console.warn('screenshot-desktop not loaded:', err.message);
}

let Jimp = null;
try {
  Jimp = require('jimp');
} catch (err) {
  console.warn('jimp not loaded (multi-monitor stitch disabled):', err.message);
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
  if (!screenshot) {
    throw new Error('screenshot-desktop not available');
  }

  // Decide single vs multi. Detection failures fall back to single.
  let buffers = null;
  try {
    const displays = await screenshot.listDisplays();
    if (Jimp && Array.isArray(displays) && displays.length > 1) {
      buffers = await screenshot.all({ format: 'png' });
    }
  } catch (err) {
    console.warn('multi-display detection failed, using primary:', err.message);
    buffers = null;
  }

  if (!buffers || buffers.length <= 1) {
    const single = buffers && buffers.length === 1 ? buffers[0] : await screenshot({ format: 'jpg' });
    return writeOut(single, sessionId);
  }

  try {
    return await writeOut(await stitch(buffers), sessionId);
  } catch (err) {
    console.warn('multi-monitor stitch failed, using primary:', err.message);
    return writeOut(await screenshot({ format: 'jpg' }), sessionId);
  }
}

/** Compose display buffers left-to-right, vertically centred, thin gap between. */
async function stitch(buffers) {
  const images = await Promise.all(buffers.map((b) => Jimp.read(b)));
  const gap = 8;
  const maxHeight = Math.max(...images.map((i) => i.bitmap.height));
  const totalWidth =
    images.reduce((sum, i) => sum + i.bitmap.width, 0) + gap * (images.length - 1);

  // 0x0b1220ff = slate-950, matches the app's dark monitoring UI.
  const canvas = new Jimp(totalWidth, maxHeight, 0x0b1220ff);
  let x = 0;
  for (const img of images) {
    const y = Math.floor((maxHeight - img.bitmap.height) / 2);
    canvas.composite(img, x, y);
    x += img.bitmap.width + gap;
  }

  return canvas.quality(70).getBufferAsync(Jimp.MIME_JPEG);
}

module.exports = {
  capture,
  // Backwards-compatible alias; older callers used capturePrimary.
  capturePrimary: capture,
};
