<?php

namespace App\Http\Controllers;

use App\Models\MonitoringSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Self-service app logo. Stored on the S3-backed avatars disk so the host's
 * deploy pruning can't wipe it, and swappable from the Developer page without
 * a code change.
 */
class BrandingController extends Controller
{
    /**
     * Serve the uploaded logo (public — it appears on the login page).
     * 404 when none is uploaded; the frontend falls back to the bundled logo.
     */
    public function logo(Request $request)
    {
        $path = MonitoringSetting::current()->branding_logo_path;

        abort_unless((bool) $path, 404);

        $etag = '"'.substr(md5($path), 0, 16).'"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=604800']);
        }

        // Cache a downscaled copy on the app server: the uploaded cinematic
        // logo is ~1 MB at 1024 px but the nav/login show it under ~80 px, so
        // a 320 px version (retina-safe) loads instantly instead of ~2.7s.
        $cacheKey = 'branding-logo-thumb:'.md5($path);
        $cached = Cache::remember($cacheKey, now()->addDays(7), function () use ($path) {
            if (! Storage::disk('avatars')->exists($path)) {
                return false;
            }
            $fallbackMime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };
            $resized = \App\Support\ImageResizer::fit(Storage::disk('avatars')->get($path), 320);

            return ['bytes' => $resized['bytes'], 'mime' => $resized['mime'] ?? $fallbackMime];
        });

        if ($cached === false) {
            Cache::forget($cacheKey);
            abort(404);
        }

        return response($cached['bytes'], 200, [
            'Content-Type' => $cached['mime'],
            'Cache-Control' => 'public, max-age=604800',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ]);

        $settings = MonitoringSetting::current();
        $old = $settings->branding_logo_path;

        $path = $request->file('logo')->store('branding', 'avatars');
        $settings->update(['branding_logo_path' => $path]);

        if ($old && $old !== $path) {
            Storage::disk('avatars')->delete($old);
        }

        Cache::forget('branding.shared');

        return back()->with('success', 'Logo updated. Browsers may show the old one for a moment until their cache refreshes.');
    }
}
