<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Stable, cacheable avatar proxy. The old approach minted a fresh presigned
 * S3 URL on every render, so the browser could never cache an avatar and a
 * single team page fired ~50 simultaneous signed GETs to the bucket in
 * Stockholm — slow, and enough to trip S3's 503 throttling. This serves each
 * avatar from a stable URL with a week-long browser cache and a server-side
 * byte cache, so S3 is touched at most once per image until it changes.
 */
class AvatarController extends Controller
{
    public function show(Request $request, User $user)
    {
        $path = $user->avatar;

        // No avatar, or the file vanished — 404 so the frontend draws initials.
        if (! $path) {
            abort(404);
        }

        $etag = '"'.substr(md5($path), 0, 16).'"';

        // Conditional request: the URL carries ?v=<hash> and never changes for
        // a given image, so once the browser has it, it only revalidates.
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=604800']);
        }

        // Cache the downscaled bytes (most uploads are multi-MB phone photos
        // shown at 32–80 px). 160 px covers retina at the largest display.
        $cacheKey = 'avatar-thumb:'.md5($path);
        $cached = Cache::remember($cacheKey, now()->addDays(7), function () use ($path) {
            if (! Storage::disk('avatars')->exists($path)) {
                return false;
            }
            $resized = \App\Support\ImageResizer::fit(Storage::disk('avatars')->get($path), 160);

            return ['bytes' => $resized['bytes'], 'mime' => $resized['mime'] ?? $this->mimeFor($path)];
        });

        if ($cached === false) {
            Cache::forget($cacheKey); // file may appear after a re-upload
            abort(404);
        }

        return response($cached['bytes'], 200, [
            'Content-Type' => $cached['mime'],
            'Cache-Control' => 'public, max-age=604800',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
