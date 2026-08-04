<?php

namespace App\Http\Controllers;

use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Support\ImageGuard;
use App\Support\ImageResizer;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

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
     *
     * ⚠️ THIS ROUTE IS ANONYMOUS. It renders on the login page, so anything
     * expensive here is reachable by anyone on the internet, at whatever rate
     * they like, with no account. That is why the upload side is now guarded
     * (see update()) and why the decode below goes through ImageResizer, whose
     * memory budget and crash breadcrumb are what stop one bad logo from
     * turning every unauthenticated hit into a fresh out-of-memory fatal.
     *
     * The Content-Type is decided from the BYTES, never from the stored path's
     * extension. A file extension is attacker-influenced and a stored value
     * written before a rule was tightened lives forever; the header in front of
     * us is the only trustworthy source.
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

            $bytes = (string) Storage::disk('avatars')->get($path);

            // Decide the type from the header, not from the extension. A row
            // written before the upload rule was tightened still carries
            // whatever it carried then, and this response goes to anonymous
            // visitors on the app origin — the origin the customer session
            // cookie is scoped to. Non-raster bytes (an SVG is a scriptable XML
            // document, not an image) get no response at all: 404 makes the
            // frontend draw the bundled logo, which is the correct outcome.
            $sourceMime = ImageGuard::serveMime($bytes);

            if ($sourceMime === null) {
                return false;
            }

            // Keyed by PATH, so the breadcrumb survives across requests and a
            // replaced logo starts with a clean slate (see update()).
            $resized = ImageResizer::fit($bytes, 320, ImageGuard::MAX_PIXELS, 'branding-logo:'.md5($path));

            return ['bytes' => $resized['bytes'], 'mime' => $resized['mime'] ?? $sourceMime];
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

        // ONE guard for every image upload in the app — see App\Support\ImageGuard.
        //
        // This was the worst of the unguarded siblings: `image|mimes:...|max:8192`
        // with no dimension check at all, feeding logo() — which is ANONYMOUS.
        // An 89-byte PNG declaring 8000x4999 cost GD ~274 MB there, which at a
        // 128-256 MB memory_limit is an uncatchable fatal, so Cache::remember()
        // never wrote and EVERY subsequent hit on the login page repeated it.
        // One upload, by one admin, and the login page is a self-sustaining
        // anonymous denial of service.
        $request->validate([
            'logo' => ImageGuard::rules(['png', 'jpg', 'webp'], 8192, ImageGuard::MAX_DISPLAY_PIXELS),
        ]);

        $settings = MonitoringSetting::current();
        $old = $settings->branding_logo_path;

        $path = $request->file('logo')->store('branding', 'avatars');
        $settings->update(['branding_logo_path' => $path]);

        // New bytes, clean slate: a replaced logo must not inherit the previous
        // one's "unrenderable" verdict. store() hashes the name, so this is
        // normally a fresh key anyway — but the verdict outliving the file it
        // was about is exactly the kind of thing that is discovered six months
        // later as "the logo just won't come back".
        ImageGuard::forgetDecodeVerdict('branding-logo:'.md5($path));

        if ($old && $old !== $path) {
            Storage::disk('avatars')->delete($old);
            Cache::forget('branding-logo-thumb:'.md5($old));
            ImageGuard::forgetDecodeVerdict('branding-logo:'.md5($old));
        }

        MonitoringSetting::forgetSharedCache();

        return back()->with('success', 'Logo updated. Browsers may show the old one for a moment until their cache refreshes.');
    }

    /** The white-label settings page (workspace name, accent, logo, default theme). */
    public function edit(): Response
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
