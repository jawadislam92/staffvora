<?php

namespace App\Http\Controllers;

use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

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
        $this->authorizeBranding($request);

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

        \App\Models\MonitoringSetting::forgetSharedCache();

        return back()->with('success', 'Logo updated. Browsers may show the old one for a moment until their cache refreshes.');
    }

    /** The white-label settings page (workspace name, accent, logo, default theme). */
    public function edit(): \Inertia\Response
    {
        $org = $this->currentOrg();
        $logoPath = MonitoringSetting::current()->branding_logo_path;

        return Inertia::render('Settings/Branding', [
            'branding' => [
                'name' => $org?->name,
                'display_name' => $org?->display_name,
                'accent_color' => $org?->accent_color ?: '#f97316',
                'default_theme' => $org?->default_theme ?: 'dark',
                'logo_url' => $logoPath ? route('branding.logo').'?v='.substr(md5($logoPath), 0, 8) : null,
            ],
            // Editable only by a workspace admin AND only when the plan includes
            // white-label (otherwise the page shows an upgrade prompt).
            'canManage' => (bool) request()->user()?->isSuperAdmin(),
            'canBrand' => (bool) ($org?->can('white_label') ?? false),
        ]);
    }

    /** Save the workspace display name + accent + default theme onto the org. */
    public function saveBranding(Request $request): RedirectResponse
    {
        $org = $this->authorizeBranding($request);

        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_theme' => ['nullable', 'in:light,dark,black'],
        ]);

        $org->update([
            'display_name' => $data['display_name'] ?: null,
            'accent_color' => $data['accent_color'] ?: '#f97316',
            'default_theme' => $data['default_theme'] ?: 'dark',
        ]);

        MonitoringSetting::forgetSharedCache();
        Cache::forget('branding.shared.'.$org->id);

        return back()->with('success', 'Branding updated.');
    }

    /**
     * Branding edits require a workspace admin AND the white-label entitlement.
     * White-label is a paid feature, so a Free/Starter tenant is rejected on the
     * server even if they reach the endpoint. Returns the current org.
     */
    private function authorizeBranding(Request $request): Organization
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        $org = $this->currentOrg();
        abort_unless($org, 404);
        abort_unless($org->can('white_label'), 403, 'White-label branding requires a higher plan.');

        return $org;
    }

    private function currentOrg(): ?Organization
    {
        $id = app(CurrentOrganization::class)->id();

        return $id ? Organization::find($id) : null;
    }
}
