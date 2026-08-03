<?php

namespace App\Support;

class ImageResizer
{
    /**
     * Downscale image bytes so the longest side is at most $maxDim, returning
     * ['bytes' => ..., 'mime' => ?string]. When the image is already small,
     * GD is missing, or anything fails, the original bytes come back with a
     * null mime (caller keeps the source content-type). Resized output is
     * always PNG so transparency (logos) survives.
     *
     * A 1–2 MB photo dropped to ~160 px lands around 30–60 KB, which is what
     * turns a multi-second avatar/logo load into an instant one.
     *
     * DECOMPRESSION BOMBS. imagecreatefromstring() allocates the FULL source
     * raster (~4 bytes/pixel) before any downscale happens, so a 2 MB file
     * declaring 20,000 x 20,000 px costs ~1.6 GB — inside a plain web request,
     * on a box shared with the owner's other live sites. Dimensions are
     * therefore read from the header first (getimagesizefromstring never
     * decodes) and anything over the pixel budget is handed straight back
     * undecoded, which is the same "give up, keep the original" contract every
     * other failure path here already has.
     *
     * @return array{bytes: string, mime: ?string}
     */
    public static function fit(string $bytes, int $maxDim, int $maxPixels = ImageGuard::MAX_PIXELS): array
    {
        if (! function_exists('imagecreatefromstring') || $bytes === '') {
            return ['bytes' => $bytes, 'mime' => null];
        }

        // Header-only budget check BEFORE GD allocates anything. Also rejects
        // non-raster bytes, so the decode below is never handed a surprise.
        if (ImageGuard::inspectBytes($bytes, $maxPixels) === null) {
            return ['bytes' => $bytes, 'mime' => null];
        }

        try {
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                return ['bytes' => $bytes, 'mime' => null];
            }

            $w = imagesx($src);
            $h = imagesy($src);

            if ($w <= $maxDim && $h <= $maxDim) {
                imagedestroy($src);

                return ['bytes' => $bytes, 'mime' => null];
            }

            $scale = $maxDim / max($w, $h);
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

            ob_start();
            imagepng($dst, null, 6);
            $out = (string) ob_get_clean();

            imagedestroy($src);
            imagedestroy($dst);

            return $out !== ''
                ? ['bytes' => $out, 'mime' => 'image/png']
                : ['bytes' => $bytes, 'mime' => null];
        } catch (\Throwable $e) {
            return ['bytes' => $bytes, 'mime' => null];
        }
    }
}
