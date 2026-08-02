'use strict';

const { Jimp, JimpMime } = require('jimp');

/** Compose display buffers left-to-right, vertically centred. */
async function stitch(buffers) {
  const images = await Promise.all(buffers.map((buffer) => Jimp.read(buffer)));
  const gap = 8;
  const maxHeight = Math.max(...images.map((image) => image.bitmap.height));
  const totalWidth = images.reduce((sum, image) => sum + image.bitmap.width, 0)
    + gap * (images.length - 1);

  const canvas = new Jimp({ width: totalWidth, height: maxHeight, color: 0x0b1220ff });
  let x = 0;
  for (const image of images) {
    const y = Math.floor((maxHeight - image.bitmap.height) / 2);
    canvas.composite(image, x, y);
    x += image.bitmap.width + gap;
  }

  return canvas.getBuffer(JimpMime.jpeg, { quality: 70 });
}

module.exports = { stitch };
