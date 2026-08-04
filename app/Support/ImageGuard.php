<?php

namespace App\Support;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The ONE guard every image upload and every image decode in this app goes
 * through. If you are adding an upload path, call {@see ImageGuard::rules()};
 * if you are adding a decode, call {@see ImageGuard::decodeOnce()} and check
 * {@see ImageGuard::canDecode()}. Nothing else may hand untrusted bytes to GD.
 *
 * Three jobs, in the order they matter:
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
 * 2. BOUND WHAT WE ARE WILLING TO STORE (a TOTAL pixel budget).
 *    getimagesize()/getimagesizefromstring() read the header only and never
 *    allocate the raster, so they are safe on hostile input. Per-axis bounds
 *    cannot express this budget and are actively harmful in both directions:
 *    7999x7999 is 64 Mpx and passes any "max 8000 wide" rule, while an honest
 *    triple-4K capture (11520x2160 = 24.9 Mpx, 62% of the budget) is REFUSED by
 *    one. A monitoring product whose evidence silently stops arriving on a
 *    developer's workstation is a worse outcome than the attack. So: total
 *    pixels, never per-axis.
 *
 * 3. BOUND WHAT WE ARE WILLING TO DECODE (a MEMORY budget, measured).
 *    ────────────────────────────────────────────────────────────────────────
 *    THE STORE BUDGET IS NOT A MEMORY GUARANTEE. The previous revision of this
 *    class said 40 Mpx "caps GD at roughly 160 MB", from the folklore figure of
 *    ~4 bytes/pixel. That is wrong, and a red team walked straight through the
 *    gap: an 89-byte PNG whose IHDR declares 8000x4999 = 39,992,000 px passes
 *    the store budget and costs GD 287 MB. Measured on this box (PHP 8.3 + GD),
 *    peak allocation for imagecreatefromstring() is:
 *
 *        1920x1080  (2.1 Mpx)  ->   14 MB   (7.08 B/px)
 *        3840x2160  (8.3 Mpx)  ->   58 MB   (7.33 B/px)
 *        11520x2160 (24.9 Mpx) ->  174 MB   (7.33 B/px)
 *        8000x4999  (40.0 Mpx) ->  274 MB   (7.18 B/px)
 *
 *    i.e. ~7.3 bytes per pixel, not 4. {@see BYTES_PER_PIXEL} rounds that up to
 *    8 so the estimate errs toward refusing.
 *
 *    A decode that exceeds the process memory limit is an UNCATCHABLE fatal —
 *    try/catch and rescue() do not survive OOM. So the only real defence is to
 *    not start it: {@see decodePixelBudget()} derives, from the memory actually
 *    left in THIS process, how many pixels we can afford, and callers degrade
 *    (serve the original bytes / skip the thumbnail) instead of dying.
 *
 * 4. DEGRADE ONCE, NOT FOREVER.
 *    Because an OOM fatal kills the process mid-request, Cache::remember() never
 *    gets to write its result — so the NEXT request repeats the identical work
 *    and dies identically, forever, with no code path ever reached that could
 *    record the failure. On /branding/logo, which is anonymous (it renders on
 *    the login page), that turns one upload into a self-sustaining DoS.
 *    {@see decodeOnce()} fixes the shape of that problem rather than any one
 *    instance of it: the breadcrumb is written BEFORE the decode, so it survives
 *    a fatal, and a later request that finds it refuses to repeat the decode.
 */
class ImageGuard
{
    /**
     * Total pixel budget for STORING an upload.
     *
     * This is an "is this a plausible image at all" bound, NOT a memory bound —
     * see point 3 above; at {@see BYTES_PER_PIXEL} this many pixels still costs
     * GD ~288 MB, which is precisely why nothing may decode on the strength of
     * this constant alone.
     *
     * Sized from the widest capture a real workstation produces: four 4K
     * monitors side by side is 15360x2160 = 33.2 Mpx, and three is 24.9 Mpx.
     * 36 Mpx clears both with headroom.
     *
     * It was 40 Mpx, and that specific number was load-bearing for the attack:
     * 8000x4999 is 39,992,000 px, so the red team's 89-byte bomb was sized to
     * sit just underneath it. Nothing legitimate lives between 36 and 40 Mpx —
     * the gap exists only for something choosing its dimensions to fit.
     */
    public const MAX_PIXELS = 36_000_000;

    /**
     * Total pixel budget for an image a WEB REQUEST will have to decode —
     * avatars and logos, which AvatarController / BrandingController::logo /
     * Site\PageController::logo downscale with GD on render.
     *
     * Much tighter than MAX_PIXELS, because the two are answering different
     * questions. A screenshot needs 36 Mpx of headroom because a trader's four
     * monitors legitimately are that wide. An avatar is displayed at 32-80 px
     * and a logo at ~80 px: there is no honest reason for either to be 40
     * megapixels, and letting one in means an anonymous route (the logo renders
     * on the LOGIN page) is holding bytes it must then refuse to resize.
     *
     * 12 Mpx is ~96 MB at {@see BYTES_PER_PIXEL}, and in practice the file-size
     * caps bite first for real photography — a 12 Mpx JPEG of an actual scene is
     * several MB, well over the 2 MB avatar cap. What fits under the size cap AT
     * this pixel count is flat synthetic content, which is precisely the shape a
     * decompression bomb has.
     */
    public const MAX_DISPLAY_PIXELS = 12_000_000;

    /**
     * Bytes of process memory GD costs per source pixel, rounded up from the
     * ~7.3 measured above. Used to convert "memory left" into "pixels we can
     * afford". Rounding UP means the budget is conservative.
     */
    public const BYTES_PER_PIXEL = 8;

    /**
     * Share of the remaining process memory a single decode may claim.
     *
     * Not 1.0: the request still has to hold the source bytes, the resized
     * copy, the encoder's output buffer and the framework itself, and PHP's
     * memory_limit is a hard wall rather than a target.
     */
    private const DECODE_MEMORY_FRACTION = 0.6;

    /**
     * Assumed limit when memory_limit is -1 (unlimited).
     *
     * "Unlimited" is a local-dev / CLI convenience, not permission to allocate a
     * gigabyte; but it also must not make the guard stricter than production, so
     * it resolves to the store budget's worth of memory.
     */
    private const UNLIMITED_MEMORY_ASSUMPTION = self::MAX_PIXELS * self::BYTES_PER_PIXEL;

    /** Cache key prefixes for the crash breadcrumb and the durable verdict. */
    private const ATTEMPT_PREFIX = 'imageguard:decoding:';

    private const BLOCKED_PREFIX = 'imageguard:unrenderable:';

    /** How long a breadcrumb lives if the process never comes back to clear it. */
    private const ATTEMPT_TTL = 900;

    /**
     * A breadcrumb younger than this is assumed to belong to a decode running
     * RIGHT NOW in a sibling request, not to one that died — so we decline to
     * decode (cheap, safe) but do not condemn the asset. Older than this and
     * nothing is still running: the previous attempt died without returning.
     */
    private const ATTEMPT_GRACE_SECONDS = 15;

    /** How long an asset stays marked unrenderable. Cleared on re-upload. */
    private const BLOCKED_TTL = 604800;

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

    // ── Header inspection ───────────────────────────────────────────────────

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
     * The canonical mime for these bytes, decided by the header — or null when
     * they are not a raster image we recognise.
     *
     * This is the READ-side half of job 1. A stored `mime` column, a stored file
     * extension and a client-supplied Content-Type are all attacker-influenced
     * and all of them were, at one point or another, echoed straight back into a
     * response header on this origin. Rows written before an upload rule was
     * tightened still carry the old value forever, so a write-side fix alone
     * fixes nothing that is already in the database: serving routes must decide
     * the type from the bytes in front of them, every time.
     */
    public static function serveMime(string $bytes): ?string
    {
        return self::inspectBytes($bytes, PHP_INT_MAX)['mime'] ?? null;
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

    // ── The upload rule every path must use ─────────────────────────────────

    /**
     * The complete validation rule set for an image upload. USE THIS, not a
     * hand-rolled `image|mimes:...|max:...` string.
     *
     * The reason this exists as one call rather than a documented convention:
     * `/profile` had the guard, and its three siblings (the admin user form, the
     * branding logo, the site builder) each shipped `image|mimes:...|max:2048`
     * with no dimension check at all — so a 40000x40000 header that the profile
     * form rejected outright was accepted by every other door in the product.
     * That is what a convention gets you. A new upload path that calls this
     * inherits the guard; one that does not is visible in review as the odd one
     * out.
     *
     * Note what is NOT here: any `dimensions:` rule. Per-axis bounds are the
     * wrong shape (see the class docblock) and were themselves a live product
     * bug — they refused honest ultrawide screenshots.
     *
     * @param  list<string>  $extensions  canonical extensions, e.g. ['jpg','png','webp']
     * @param  int  $maxKilobytes  the `max:` rule, in KB, as Laravel counts it
     * @return list<mixed>
     */
    public static function rules(array $extensions, int $maxKilobytes, ?int $maxPixels = null, bool $required = true): array
    {
        $mimes = [];

        foreach ($extensions as $extension) {
            $mimes[] = $extension;

            // Laravel's mimes: rule matches on extension; a JPEG can arrive as
            // either spelling and both must be accepted.
            if ($extension === 'jpg') {
                $mimes[] = 'jpeg';
            }
        }

        return [
            $required ? 'required' : 'nullable',
            'file',
            'image',
            'mimes:'.implode(',', array_values(array_unique($mimes))),
            'max:'.$maxKilobytes,
            self::rule($extensions, $maxPixels),
        ];
    }

    /**
     * The header-inspection half of {@see rules()}, on its own, for a path that
     * already has its own `mimes:`/`max:` rules for other reasons.
     *
     * Rejections are LOGGED as well as returned. A monitoring product that
     * silently drops a capture is worse than one that refuses it loudly, and
     * "the screenshots just stopped" is the support ticket nobody can diagnose.
     *
     * @param  list<string>  $extensions
     */
    public static function rule(array $extensions, ?int $maxPixels = null): Closure
    {
        $budget = $maxPixels ?? self::MAX_PIXELS;

        return function (string $attribute, mixed $value, Closure $fail) use ($extensions, $budget): void {
            if ($value === null || $value === '') {
                return; // `nullable`/`required` own this case.
            }

            if (! $value instanceof UploadedFile || ! $value->isValid()) {
                $fail('The :attribute could not be read as an uploaded file.');

                return;
            }

            $info = @getimagesize((string) $value->getRealPath());
            $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
            $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;
            $pixels = (float) $width * (float) $height;

            $verdict = self::interpret($info, $budget, $extensions);

            if ($verdict !== null) {
                return;
            }

            $reason = $pixels > $budget ? 'pixel_budget' : 'format';

            Log::warning('Rejected image upload.', [
                'attribute' => $attribute,
                'reason' => $reason,
                'width' => $width ?: null,
                'height' => $height ?: null,
                'declared_mime' => is_array($info) ? ($info['mime'] ?? null) : null,
                'bytes' => $value->getSize(),
                'allowed' => $extensions,
                'max_pixels' => $budget,
            ]);

            $fail($reason === 'pixel_budget'
                ? 'The :attribute is '.$width.'x'.$height.' ('.self::megapixels($pixels)
                    .' megapixels), which is over the '.self::megapixels($budget).' megapixel limit.'
                : 'The :attribute is not a readable '.implode('/', $extensions).' image.');
        };
    }

    private static function megapixels(float $pixels): string
    {
        return rtrim(rtrim(number_format($pixels / 1_000_000, 1, '.', ''), '0'), '.') ?: '0';
    }

    // ── The decode budget ───────────────────────────────────────────────────

    /**
     * How many source pixels this process can afford to hand to GD right now.
     *
     * Deliberately derived from live memory rather than being a constant: the
     * same image is safe on a 512 MB queue worker and fatal in a 128 MB FPM
     * child, and a constant chosen for one is wrong for the other. Both
     * arguments are injectable so tests can pin the answer instead of inheriting
     * whatever the CLI's memory_limit happens to be.
     */
    public static function decodePixelBudget(?int $limitBytes = null, ?int $inUseBytes = null): int
    {
        $limit = $limitBytes ?? self::memoryLimitBytes();
        $inUse = $inUseBytes ?? memory_get_usage(true);

        $free = $limit - $inUse;

        if ($free <= 0) {
            return 0;
        }

        return max(0, (int) floor($free * self::DECODE_MEMORY_FRACTION / self::BYTES_PER_PIXEL));
    }

    /** Is an image of these dimensions safe to decode in this process? */
    public static function canDecode(int $width, int $height, ?int $budgetPixels = null): bool
    {
        if ($width < 1 || $height < 1) {
            return false;
        }

        return ((float) $width * (float) $height) <= ($budgetPixels ?? self::decodePixelBudget());
    }

    /** Estimated peak GD cost, in bytes, of decoding an image this size. */
    public static function estimatedDecodeBytes(int $width, int $height): int
    {
        return (int) ((float) $width * (float) $height * self::BYTES_PER_PIXEL);
    }

    /** php.ini memory_limit in bytes, with -1/unset resolved to an assumption. */
    public static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return self::UNLIMITED_MEMORY_ASSUMPTION;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        $bytes = match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };

        return $bytes > 0 ? $bytes : self::UNLIMITED_MEMORY_ASSUMPTION;
    }

    // ── Degrade once, not forever ───────────────────────────────────────────

    /**
     * Run $decode behind a crash breadcrumb, falling back to $fallback when the
     * same asset has already killed a process.
     *
     * WHY A BREADCRUMB AND NOT A try/catch. The failure being defended against
     * is a memory-limit fatal, which unwinds nothing: no catch block runs, no
     * `finally` runs, no Cache::remember() gets to write its miss. Every
     * "remember the failure so we don't repeat it" mechanism in PHP lives on the
     * far side of the crash. The only record that can survive is one written
     * BEFORE the decode starts — so that is what this does, and a later request
     * that finds the breadcrumb still sitting there knows the previous attempt
     * never came back.
     *
     * A breadcrumb younger than ATTEMPT_GRACE_SECONDS is treated as a decode
     * running concurrently rather than a corpse: we still decline to decode
     * (declining is cheap and always safe), but we do not condemn the asset on
     * the strength of a race.
     *
     * @template T
     *
     * @param  Closure(): T  $decode
     * @param  Closure(): T  $fallback
     * @return T
     */
    public static function decodeOnce(string $key, Closure $decode, Closure $fallback): mixed
    {
        $blocked = self::BLOCKED_PREFIX.$key;
        $attempt = self::ATTEMPT_PREFIX.$key;

        if (Cache::get($blocked)) {
            return $fallback();
        }

        $startedAt = Cache::get($attempt);

        if (is_numeric($startedAt)) {
            $age = microtime(true) - (float) $startedAt;

            if ($age >= self::ATTEMPT_GRACE_SECONDS) {
                // Nothing has been running for that long. The last process to
                // enter this block never came out of it.
                Cache::put($blocked, true, self::BLOCKED_TTL);
                Cache::forget($attempt);

                Log::error('Image decode marked unrenderable: a previous attempt never returned (likely a memory-limit fatal).', [
                    'key' => $key,
                    'seconds_since_attempt' => round($age, 1),
                ]);
            }

            return $fallback();
        }

        Cache::put($attempt, microtime(true), self::ATTEMPT_TTL);

        try {
            return $decode();
        } finally {
            // Reached on success and on any *catchable* failure. Not reached on
            // a fatal — which is the entire point of the breadcrumb.
            Cache::forget($attempt);
        }
    }

    /**
     * Clear the verdict for an asset. Call this whenever the bytes behind a key
     * change (a re-upload), so a replaced logo is not still refused because its
     * predecessor was a bomb.
     */
    public static function forgetDecodeVerdict(string $key): void
    {
        Cache::forget(self::BLOCKED_PREFIX.$key);
        Cache::forget(self::ATTEMPT_PREFIX.$key);
    }

    /** True when this asset has been condemned by {@see decodeOnce()}. */
    public static function isUnrenderable(string $key): bool
    {
        return (bool) Cache::get(self::BLOCKED_PREFIX.$key);
    }

    /** Stable breadcrumb key for a blob whose caller has no better identifier. */
    public static function keyForBytes(string $bytes): string
    {
        // crc32b + length, not md5: this runs on every cache miss of an
        // anonymous route, and hashing 8 MB with md5 on a flood is itself a cost.
        return 'b:'.hash('crc32b', $bytes).':'.strlen($bytes);
    }
}
