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
     * @return array{bytes: string, mime: ?string}
     */
    public static function fit(string $bytes, int $maxDim): array
    {
        if (! function_exists('imagecreatefromstring') || $bytes === '') {
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
