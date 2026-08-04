<?php

namespace App\Jobs;

use App\Jobs\Concerns\TenantAware;
use App\Models\TrackingScreenshot;
use App\Support\ImageGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a width-bounded thumbnail (default 320px) for a captured screenshot
 * using PHP's GD extension.
 *
 * DRIVER-AGNOSTIC. The previous implementation resolved `$disk->path()` and
 * handed it to getimagesize()/imagecreatefrom*(). On the S3 adapter `path()`
 * returns the object KEY, not a filesystem path, so under the intended
 * production driver (SCREENSHOTS_DRIVER=s3) getimagesize() found nothing and the
 * job returned silently — no thumbnail, no log line, forever. The Timeline then
 * fell back to serving full-resolution originals (up to 8 MB each) where a 320px
 * thumbnail belonged, i.e. a 50-100x bandwidth and egress bill on every page
 * view. Everything here now goes through the disk API, so local and S3 behave
 * identically.
 *
 * MEMORY-BOUNDED, AGAINST MEASURED COST. Dimensions are read from the file
 * HEADER (getimagesizefromstring — no decode) and checked against TWO budgets
 * before GD ever allocates. An 89-byte PNG can declare 40,000 x 40,000 pixels,
 * which imagecreatefromstring would try to allocate and kill the worker with.
 * Under the sync queue driver that worker is a web request from the desktop
 * agent, so the refusal matters more than usual.
 *
 * The first budget (MAX_SOURCE_PIXELS) is the same "is this plausible" bound the
 * upload path uses. The second — ImageGuard::decodePixelBudget() — is the one
 * that actually protects the worker, because it is derived from the memory this
 * process has left. The old constant claimed 40 Mpx "caps GD at roughly 160 MB"
 * on a folklore figure of 4 bytes/pixel; the measured figure is ~7.3, so 40 Mpx
 * is ~274 MB and the documented budget was simply wrong. See ImageGuard.
 *
 * DEGRADE ONCE. A memory-limit fatal is uncatchable: $tries = 3 would otherwise
 * re-run the identical fatal decode three times, and nothing in failed() would
 * ever run to record why. ImageGuard::decodeOnce leaves a breadcrumb before the
 * decode, so retry 2 refuses instead of repeating it.
 *
 * FAILS LOUDLY. Every abort path logs. A silent `return` on a 100%-breakage bug
 * is what let the S3 problem live unnoticed; $tries = 3 can only help if the job
 * actually reports failure.
 *
 * ACTUALLY ASYNCHRONOUS NOW, AND TENANT-BOUND.
 * Measured on this box (PHP 8.3 + GD), the decode/resize/encode below costs
 *   1920x1080  →   39 ms /  28 MB
 *   2560x1440  →   63 ms /  54 MB
 *   3840x2160  →  144 ms / 104 MB
 *   7680x2160  →  320 ms / 208 MB
 * of a PHP worker. Under the old `sync` default that was paid INSIDE the desktop
 * agent's screenshot upload request, once per capture per person — so a
 * 100-person tenant on 4K monitors at 4 captures/hour spent ~1 CPU-minute an hour
 * per tenant holding FPM workers hostage, on a pool shared with every other
 * tenant's page loads.
 *
 * Because it now runs on a worker, it also runs with NO TENANT BOUND. See
 * {@see TenantAware} for exactly what that breaks; the short version is that
 * `TrackingScreenshot::find()` would reach across tenants and any write would be
 * stamped with a NULL organization_id. The tenant is therefore captured at
 * dispatch and re-bound around the work, and `TenantAwareJobTest` asserts it.
 */
class GenerateScreenshotThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Total pixel budget for a source we are willing to consider at all — the
     * same number the upload path stores against, so a capture that was accepted
     * is never silently un-thumbnailable. A per-axis bound is the wrong shape of
     * check twice over: 7999x7999 passes "max 8000 wide" and still costs ~470
     * MB, while an honest triple-4K capture (11520x2160) fails it at 62% of the
     * budget.
     *
     * Whether the decode actually happens is decided by
     * ImageGuard::decodePixelBudget(), not by this constant — see the class
     * docblock for why a pixel constant cannot bound memory.
     */
    private const MAX_SOURCE_PIXELS = ImageGuard::MAX_PIXELS;

    public function __construct(public int $screenshotId, public int $maxWidth = 320)
    {
        // Runs in the dispatching request, where the tenant IS bound.
        $this->captureTenant();
    }

    public function handle(): void
    {
        $this->forTenant(fn () => $this->generate());
    }

    private function generate(): void
    {
        $screenshot = TrackingScreenshot::find($this->screenshotId);
        if (! $screenshot || ! $screenshot->image_path) {
            return;
        }

        if (! extension_loaded('gd')) {
            Log::warning('GD extension not loaded; skipping thumbnail generation', [
                'screenshot_id' => $screenshot->id,
            ]);

            return;
        }

        $disk = Storage::disk('screenshots');

        // No exists() probe: on S3 that is a wasted HEAD round trip per
        // screenshot, and a null/failed read already covers the missing case.
        // The disk is configured with 'throw' => false, so read failures surface
        // as null rather than an exception.
        $bytes = rescue(fn () => $disk->get($screenshot->image_path), null, false);

        if ($bytes === null || $bytes === '') {
            Log::warning('Screenshot bytes unreadable; no thumbnail generated', [
                'screenshot_id' => $screenshot->id,
                'path' => $screenshot->image_path,
            ]);

            return;
        }

        // Header-only read — this does NOT decode the image, so it is safe to run
        // on hostile input and is what makes the pixel budget below meaningful.
        $info = @getimagesizefromstring($bytes);
        if (! $info) {
            Log::warning('Screenshot is not a readable image; no thumbnail generated', [
                'screenshot_id' => $screenshot->id,
            ]);

            return;
        }

        $srcW = (int) ($info[0] ?? 0);
        $srcH = (int) ($info[1] ?? 0);

        if ($srcW < 1 || $srcH < 1 || ((float) $srcW * (float) $srcH) > self::MAX_SOURCE_PIXELS) {
            Log::warning('Oversized or invalid screenshot; skipping thumbnail', [
                'screenshot_id' => $screenshot->id,
                'width' => $srcW,
                'height' => $srcH,
            ]);

            return;
        }

        // The budget that actually protects the worker: derived from the memory
        // left in THIS process, against the measured ~7.3 bytes/pixel GD costs.
        // Refusing costs the Timeline a thumbnail (it falls back to the
        // original); not refusing costs the worker its life, uncatchably, and
        // takes every other queued job with it.
        if (! ImageGuard::canDecode($srcW, $srcH)) {
            Log::warning('Screenshot needs more memory than this worker can spare; skipping thumbnail', [
                'screenshot_id' => $screenshot->id,
                'width' => $srcW,
                'height' => $srcH,
                'estimated_bytes' => ImageGuard::estimatedDecodeBytes($srcW, $srcH),
                'budget_pixels' => ImageGuard::decodePixelBudget(),
                'memory_limit' => ImageGuard::memoryLimitBytes(),
            ]);

            return;
        }

        ImageGuard::decodeOnce(
            'screenshot-thumb:'.$screenshot->id,
            fn () => $this->render($screenshot, $disk, $bytes, $info, $srcW, $srcH),
            function () use ($screenshot): void {
                Log::error('Skipping screenshot thumbnail: a previous decode of this image never returned', [
                    'screenshot_id' => $screenshot->id,
                ]);
            },
        );
    }

    /**
     * Decode → resize → encode → store. Split out so the whole GD section sits
     * behind ImageGuard::decodeOnce()'s breadcrumb rather than only its first
     * line.
     *
     * @param  array<mixed>  $info
     */
    private function render(TrackingScreenshot $screenshot, mixed $disk, string $bytes, array $info, int $srcW, int $srcH): void
    {
        $src = @imagecreatefromstring($bytes);
        unset($bytes);

        if (! $src) {
            Log::warning('Screenshot could not be decoded; no thumbnail generated', [
                'screenshot_id' => $screenshot->id,
                'mime' => $info['mime'] ?? null,
            ]);

            return;
        }

        $maxW = max(64, min(2048, $this->maxWidth));
        $ratio = min(1, $maxW / $srcW);
        $dstW = max(1, (int) round($srcW * $ratio));
        $dstH = max(1, (int) round($srcH * $ratio));

        $dst = imagecreatetruecolor($dstW, $dstH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);

        // Encode in memory and hand the bytes to the disk. makeDirectory() is
        // gone: it is meaningless on S3 (it would create a stray zero-byte key)
        // and unnecessary locally, since put() creates parent directories.
        // Visibility is deliberately NOT passed — the disk config already sets
        // 'private', and hardcoding it here would leak thumbnails if that
        // config ever changed.
        ob_start();
        imagejpeg($dst, null, 75);
        $jpeg = (string) ob_get_clean();
        imagedestroy($dst);

        $thumbRelative = dirname($screenshot->image_path).'/thumbs/'
            .pathinfo($screenshot->image_path, PATHINFO_FILENAME).'.jpg';

        $written = rescue(fn () => $disk->put($thumbRelative, $jpeg), false, false);

        if (! $written) {
            // Fixing only the read would have reproduced the original class of
            // bug on the write side: 'throw' => false means a failed put() is
            // simply `false`, with no exception and no signal anywhere.
            Log::error('Failed to write screenshot thumbnail', [
                'screenshot_id' => $screenshot->id,
                'path' => $thumbRelative,
            ]);

            return;
        }

        $screenshot->update([
            'thumbnail_path' => $thumbRelative,
            'width' => $srcW,
            'height' => $srcH,
        ]);
    }
}
