<?php

namespace App\Jobs;

use App\Models\TrackingScreenshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a width-bounded thumbnail (default 320px) for a captured screenshot
 * using PHP's GD extension. Falls back gracefully if GD is unavailable.
 */
class GenerateScreenshotThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $screenshotId, public int $maxWidth = 320) {}

    public function handle(): void
    {
        $screenshot = TrackingScreenshot::find($this->screenshotId);
        if (! $screenshot || ! $screenshot->image_path) {
            return;
        }

        $disk = Storage::disk('screenshots');
        if (! $disk->exists($screenshot->image_path)) {
            return;
        }

        if (! extension_loaded('gd')) {
            Log::warning('GD extension not loaded; skipping thumbnail generation', [
                'screenshot_id' => $screenshot->id,
            ]);

            return;
        }

        $absolutePath = $disk->path($screenshot->image_path);
        $info = @getimagesize($absolutePath);
        if (! $info) {
            return;
        }

        [$srcW, $srcH] = $info;
        $maxW = max(64, min(2048, $this->maxWidth));
        $ratio = $srcW > 0 ? $maxW / $srcW : 1;
        $dstW = (int) round($srcW * min(1, $ratio));
        $dstH = (int) round($srcH * min(1, $ratio));

        $src = match ($info['mime'] ?? null) {
            'image/jpeg' => @imagecreatefromjpeg($absolutePath),
            'image/png' => @imagecreatefrompng($absolutePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false,
            default => false,
        };

        if (! $src) {
            return;
        }

        $dst = imagecreatetruecolor($dstW, $dstH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        $thumbDir = dirname($screenshot->image_path).'/thumbs';
        $thumbName = pathinfo($screenshot->image_path, PATHINFO_FILENAME).'.jpg';
        $thumbRelative = $thumbDir.'/'.$thumbName;
        $disk->makeDirectory($thumbDir);
        $thumbAbsolute = $disk->path($thumbRelative);

        imagejpeg($dst, $thumbAbsolute, 75);
        imagedestroy($src);
        imagedestroy($dst);

        $screenshot->update([
            'thumbnail_path' => $thumbRelative,
            'width' => $srcW,
            'height' => $srcH,
        ]);
    }
}
