<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

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
     * ─────────────────────────────────────────────────────────────────────────
     * THIS IS THE CHOKEPOINT. Every avatar render (AvatarController), the app
     * logo on the login page (BrandingController::logo — anonymous) and the
     * marketing-site logo (Site\PageController::logo — anonymous) reach GD
     * through this one function. That is deliberate: the guard below therefore
     * covers callers that have not been edited, including any added later.
     *
     * TWO BUDGETS, BECAUSE THEY ANSWER DIFFERENT QUESTIONS.
     *
     *   $maxPixels — "is this a plausible image at all", the same total-pixel
     *   bound the upload paths use. Also rejects non-raster bytes, so the decode
     *   below is never handed a surprise.
     *
     *   ImageGuard::decodePixelBudget() — "can THIS process afford it", derived
     *   from the memory actually left. The store budget is not a memory bound:
     *   at ~7.3 measured bytes/pixel a 40 Mpx image costs GD ~274 MB, and an
     *   89-byte PNG can declare 40 Mpx. Under a 128–256 MB memory_limit that is
     *   an UNCATCHABLE fatal — the try/catch below would never run — so the
     *   check has to happen before imagecreatefromstring(), not around it.
     *
     * DEGRADE ONCE. Because the fatal kills the process, the Cache::remember()
     * wrapping most callers never writes, so the next request repeats it and
     * dies identically — forever, on an anonymous route. ImageGuard::decodeOnce
     * leaves a breadcrumb BEFORE the decode (the only record that can outlive a
     * fatal) and refuses to repeat a decode that previously failed to return.
     *
     * Both refusals land on the contract this function already had — "give up,
     * hand back the original bytes" — so a caller sees a slightly larger image,
     * never an error and never a blank page.
     *
     * @param  string|null  $decodeKey  stable identity for the breadcrumb (a storage
     *                                  path is ideal). Derived from the bytes when null.
     * @return array{bytes: string, mime: ?string}
     */
    public static function fit(string $bytes, int $maxDim, int $maxPixels = ImageGuard::MAX_PIXELS, ?string $decodeKey = null): array
    {
        $original = ['bytes' => $bytes, 'mime' => null];

        if (! function_exists('imagecreatefromstring') || $bytes === '') {
            return $original;
        }

        // Header-only budget check BEFORE GD allocates anything.
        $info = ImageGuard::inspectBytes($bytes, $maxPixels);

        if ($info === null) {
            return $original;
        }

        if (! ImageGuard::canDecode($info['width'], $info['height'])) {
            Log::warning('Refusing to decode an image that does not fit this process\'s memory budget; serving the original.', [
                'width' => $info['width'],
                'height' => $info['height'],
                'estimated_bytes' => ImageGuard::estimatedDecodeBytes($info['width'], $info['height']),
                'budget_pixels' => ImageGuard::decodePixelBudget(),
                'memory_limit' => ImageGuard::memoryLimitBytes(),
            ]);

            return $original;
        }

        return ImageGuard::decodeOnce(
            $decodeKey ?? ImageGuard::keyForBytes($bytes),
            static fn (): array => self::resize($bytes, $maxDim),
            static fn (): array => $original,
        );
    }

    /**
     * Longest side we keep when re-encoding an upload. Avatars and logos are
     * rendered at 32–160 px (see AvatarController, which downscales to 160), so
     * 512 is already several times more than anything on screen needs while
     * leaving room for a future larger display without a re-upload.
     */
    public const SANITIZED_MAX_DIM = 512;

    /**
     * Turn attacker-supplied image bytes into bytes WE produced.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WHY RE-ENCODE RATHER THAN SNIFF HARDER.
     *
     * A file can be a valid GIF *and* a valid JavaScript program at the same
     * time: the GIF header `GIF89a...` parses as a JS identifier assignment, and
     * everything after the trailing `\x3b` (which ends the GIF) is free space
     * for `alert(document.domain)`. The same trick exists for PNG/HTML and
     * JPEG/PHP. Every one of them passes getimagesize(), passes a `mimes:` rule,
     * and passes finfo — because they genuinely ARE images. There is no
     * signature check that closes this class of bug, only a longer and longer
     * list of specific signatures.
     *
     * Decoding to a pixel raster and writing our own encoding closes all of it
     * at once, permanently, and for formats nobody has thought of yet: the
     * attacker's byte stream never reaches storage, so there is nothing left for
     * a future serving path — one without `nosniff`, one behind a different
     * proxy, a CDN, a signed S3 URL — to hand to a browser. What we store is a
     * function of the PIXELS, and pixels cannot be script.
     *
     * COST. The decode is the expensive half, so it is fenced by exactly the
     * same two budgets ImageResizer::fit() uses and nothing else:
     * ImageGuard::inspectBytes() (header-only, no allocation) and
     * ImageGuard::canDecode() (how much memory THIS process has left), plus the
     * decodeOnce breadcrumb so a blob that has already killed a worker is never
     * handed to GD a second time. No new memory ceiling is introduced and none
     * of the existing ones is relaxed.
     *
     * QUALITY. The output is not visibly worse than the input at the sizes these
     * images are displayed:
     *   - a JPEG stays a JPEG at quality 88, so a photographic avatar does not
     *     balloon into a multi-megabyte PNG;
     *   - PNG/GIF/WebP become PNG with the alpha channel preserved, so a logo
     *     with a transparent background stays transparent;
     *   - an image already inside $maxDim is re-encoded at its original
     *     dimensions — never upscaled, never cropped.
     * EXIF is dropped, which is deliberate twice over: it is a parser surface we
     * do not want to run on hostile bytes, it routinely carries GPS coordinates
     * from a phone photo, and the render path already discarded it on every
     * avatar bigger than 160 px.
     *
     * @return array{bytes: string, mime: string, extension: string}|null null when the bytes
     *                                                                   cannot be safely re-encoded — callers MUST refuse the upload rather
     *                                                                   than fall back to storing the original
     */
    public static function sanitize(
        string $bytes,
        int $maxDim = self::SANITIZED_MAX_DIM,
        int $maxPixels = ImageGuard::MAX_DISPLAY_PIXELS,
        ?array $allowedExtensions = null,
    ): ?array {
        if ($bytes === '' || ! function_exists('imagecreatefromstring')) {
            // No GD means we cannot prove what these bytes are, and storing them
            // unexamined is the hole. Refusing is the safe direction; every
            // serving path in this app already requires GD anyway.
            return null;
        }

        $info = ImageGuard::inspectBytes($bytes, $maxPixels, $allowedExtensions);

        if ($info === null) {
            return null;
        }

        if (! ImageGuard::canDecode($info['width'], $info['height'])) {
            Log::warning('Refusing to re-encode an upload that does not fit this process\'s memory budget.', [
                'width' => $info['width'],
                'height' => $info['height'],
                'estimated_bytes' => ImageGuard::estimatedDecodeBytes($info['width'], $info['height']),
                'budget_pixels' => ImageGuard::decodePixelBudget(),
                'memory_limit' => ImageGuard::memoryLimitBytes(),
            ]);

            return null;
        }

        return ImageGuard::decodeOnce(
            ImageGuard::keyForBytes($bytes),
            static fn (): ?array => self::reencode($bytes, $info, $maxDim, $maxPixels),
            static fn (): ?array => null,
        );
    }

    /**
     * The actual re-encode. Only ever called with bytes that have cleared both
     * budgets and the breadcrumb.
     *
     * @param  array{width: int, height: int, extension: string, mime: string}  $info
     * @return array{bytes: string, mime: string, extension: string}|null
     */
    private static function reencode(string $bytes, array $info, int $maxDim, int $maxPixels): ?array
    {
        $src = @imagecreatefromstring($bytes);

        if ($src === false) {
            return null;
        }

        $out = '';

        try {
            $w = imagesx($src);
            $h = imagesy($src);

            // Never upscale: min(1, ...) leaves a small avatar at its own size.
            $scale = min(1.0, $maxDim / max(1, max($w, $h)));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));

            $asJpeg = $info['extension'] === 'jpg';

            $dst = imagecreatetruecolor($nw, $nh);

            if ($asJpeg) {
                // JPEG has no alpha channel; compose onto white so a source with
                // transparency does not come back with a black background.
                imagefilledrectangle($dst, 0, 0, $nw, $nh, (int) imagecolorallocate($dst, 255, 255, 255));
            } else {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefilledrectangle($dst, 0, 0, $nw, $nh, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }

            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

            ob_start();

            if ($asJpeg) {
                imagejpeg($dst, null, 88);
            } else {
                imagepng($dst, null, 6);
            }

            $out = (string) ob_get_clean();

            imagedestroy($dst);
        } catch (\Throwable $e) {
            Log::warning('Re-encoding an uploaded image failed; refusing the upload.', [
                'error' => $e->getMessage(),
            ]);

            $out = '';
        } finally {
            imagedestroy($src);
        }

        if ($out === '') {
            return null;
        }

        // Read back OUR OWN output through the same guard the upload came
        // through. Two things fall out of this: the bytes we are about to store
        // are provably a raster image of an allowed type, and the extension we
        // name the object with is decided by the file's own header rather than
        // by anything the client said — which is what keeps the S3 adapter from
        // inferring a ContentType we never intended.
        $verified = ImageGuard::inspectBytes($out, $maxPixels);

        if ($verified === null) {
            return null;
        }

        return [
            'bytes' => $out,
            'mime' => $verified['mime'],
            'extension' => $verified['extension'],
        ];
    }

    /**
     * The actual GD work. Only ever called with bytes that have cleared both
     * budgets and the breadcrumb.
     *
     * @return array{bytes: string, mime: ?string}
     */
    private static function resize(string $bytes, int $maxDim): array
    {
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
