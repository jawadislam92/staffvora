<?php

namespace App\Support;

/**
 * Header-only inspection of untrusted image bytes.
 *
 * Two jobs, both of which have to happen BEFORE anything decodes or stores the
 * upload:
 *
 * 1. DECIDE THE EXTENSION FROM THE CONTENT, NEVER FROM THE CLIENT.
 *    Every upload path in this app is reachable by an ordinary employee (the
 *    desktop agent ships a personal API token, the profile form is
 *    self-service). If the client gets to name the stored file, it gets to name
 *    the extension, and the extension is what the S3 adapter turns into the
 *    object's ContentType — Flysystem writes uploads as a *stream*, so
 *    FinfoMimeTypeDetector cannot sniff the buffer and falls through to the
 *    key's extension. A genuine PNG carrying an HTML/JS payload in a tEXt chunk,
 *    stored as `.html`, is then served back to a logged-in manager as
 *    `text/html` from the app origin: stored XSS with the manager's session.
 *
 * 2. REFUSE DECOMPRESSION BOMBS.
 *    getimagesize()/getimagesizefromstring() read the header only and never
 *    allocate the raster, so they are safe on hostile input. A 2 MB PNG can
 *    declare 20,000 x 20,000 px, which GD would allocate at ~4 bytes/pixel
 *    (~1.6 GB) the moment anyone views the page. Per-axis bounds cannot express
 *    this — 7999x7999 is 64 Mpx and passes any "max 8000 wide" rule — so the
 *    budget is a TOTAL pixel count.
 */
class ImageGuard
{
    /**
     * Total pixel budget. 40 Mpx comfortably covers a dual-4K screen capture
     * (7680x2160 = 16.6 Mpx) while capping GD at roughly 160 MB. Kept in step
     * with GenerateScreenshotThumbnail::MAX_SOURCE_PIXELS.
     */
    public const MAX_PIXELS = 40_000_000;

    /**
     * The only formats we are willing to store or re-serve, keyed by the
     * IMAGETYPE_* constant getimagesize() reports for the actual bytes.
     *
     * Deliberately raster-only: SVG is a scripting container, not an image, and
     * would never appear here anyway because getimagesize() cannot read it.
     *
     * @var array<int, array{0: string, 1: string}> [extension, canonical mime]
     */
    private const RASTER_TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    /**
     * Inspect a file on disk. Returns null when the bytes are not a genuine
     * raster image of an allowed type, or when they blow the pixel budget.
     *
     * @param  list<string>|null  $allowedExtensions  restrict further, e.g. ['jpg','png','webp']
     * @return array{width: int, height: int, extension: string, mime: string}|null
     */
    public static function inspect(?string $realPath, int $maxPixels = self::MAX_PIXELS, ?array $allowedExtensions = null): ?array
    {
        if (! $realPath || ! is_file($realPath)) {
            return null;
        }

        return self::interpret(@getimagesize($realPath), $maxPixels, $allowedExtensions);
    }

    /**
     * Inspect bytes already in memory (cached avatar/logo reads).
     *
     * @param  list<string>|null  $allowedExtensions
     * @return array{width: int, height: int, extension: string, mime: string}|null
     */
    public static function inspectBytes(string $bytes, int $maxPixels = self::MAX_PIXELS, ?array $allowedExtensions = null): ?array
    {
        if ($bytes === '') {
            return null;
        }

        return self::interpret(@getimagesizefromstring($bytes), $maxPixels, $allowedExtensions);
    }

    /**
     * @param  array<mixed>|false  $info
     * @param  list<string>|null  $allowedExtensions
     * @return array{width: int, height: int, extension: string, mime: string}|null
     */
    private static function interpret($info, int $maxPixels, ?array $allowedExtensions): ?array
    {
        if (! is_array($info)) {
            return null;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $type = (int) ($info[2] ?? 0);

        if ($width < 1 || $height < 1) {
            return null;
        }

        // Multiply as floats: 400 Mpx is fine in int, but the guard should not
        // be the thing that overflows on an absurd declared size.
        if (((float) $width * (float) $height) > $maxPixels) {
            return null;
        }

        if (! isset(self::RASTER_TYPES[$type])) {
            return null;
        }

        [$extension, $mime] = self::RASTER_TYPES[$type];

        if ($allowedExtensions !== null && ! in_array($extension, $allowedExtensions, true)) {
            return null;
        }

        return [
            'width' => $width,
            'height' => $height,
            'extension' => $extension,
            'mime' => $mime,
        ];
    }
}
