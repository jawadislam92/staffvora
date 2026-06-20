<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\SiteMedia;
use App\Models\SitePage;
use App\Models\SiteTheme;
use App\Support\DefaultSiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * The no-code page builder, platform-admin only (routes are under the
 * `platform` middleware group). Lets the owner edit every marketing page's
 * blocks, the global theme/header/footer, and upload images — no code, no
 * deploy. Draft → Publish keeps unfinished edits off the live site.
 */
class SiteBuilderController extends Controller
{
    public function index(): \Inertia\Response
    {
        DefaultSiteContent::ensure();

        return Inertia::render('Platform/SiteBuilder/Index', [
            'pages' => SitePage::orderBy('sort')->orderBy('id')->get(['id', 'slug', 'title', 'status', 'updated_at']),
            'theme' => SiteTheme::current(),
        ]);
    }

    public function edit(SitePage $page): \Inertia\Response
    {
        return Inertia::render('Platform/SiteBuilder/Editor', [
            'page' => $page->only(['id', 'slug', 'title', 'meta_title', 'meta_description', 'status', 'blocks']),
            'theme' => SiteTheme::current()->only(['accent_color', 'highlight_color', 'site_name', 'logo_path']),
            'logoUrl' => SiteTheme::current()->logo_path ? route('site.media.logo').'?v='.uniqid() : null,
        ]);
    }

    public function saveDraft(Request $request, SitePage $page): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string|max:400',
            'blocks' => 'present|array',
        ]);

        $page->update($data);

        return back()->with('success', 'Draft saved.');
    }

    public function publish(SitePage $page): RedirectResponse
    {
        $page->publish();

        return back()->with('success', 'Published — your changes are now live.');
    }

    public function createPage(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:site_pages,slug'],
        ]);

        $page = SitePage::create([
            'slug' => $data['slug'],
            'title' => $data['title'],
            'status' => 'draft',
            'blocks' => [],
            'published_blocks' => [],
            'sort' => (int) SitePage::max('sort') + 1,
        ]);

        return redirect()->route('platform.site.edit', $page);
    }

    public function destroyPage(SitePage $page): RedirectResponse
    {
        abort_if($page->slug === 'home', 403, "The home page can't be deleted.");
        SitePage::forgetCache($page->slug);
        $page->delete();

        return redirect()->route('platform.site.index')->with('success', 'Page deleted.');
    }

    public function updateTheme(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => 'nullable|string|max:80',
            'tagline' => 'nullable|string|max:160',
            'accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'highlight_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'nav_links' => 'present|array',
            'footer_columns' => 'present|array',
            'social_links' => 'present|array',
            'contact_email' => 'nullable|email|max:120',
            'contact_phone' => 'nullable|string|max:60',
            'contact_address' => 'nullable|string|max:200',
        ]);

        SiteTheme::current()->update($data);
        SiteTheme::forgetCache();

        return back()->with('success', 'Website settings saved.');
    }

    /** Builder image upload → S3, returns the servable URL for use in a block. */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:8192']]);

        $path = $request->file('image')->store('site', 'avatars');
        $media = SiteMedia::create([
            'path' => $path,
            'mime' => $request->file('image')->getMimeType(),
            'original_name' => $request->file('image')->getClientOriginalName(),
        ]);

        return response()->json(['id' => $media->id, 'url' => $media->url()]);
    }

    public function setLogo(Request $request): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:8192']]);

        $theme = SiteTheme::current();
        $old = $theme->logo_path;
        $path = $request->file('logo')->store('site', 'avatars');
        $theme->update(['logo_path' => $path]);
        SiteTheme::forgetCache();

        if ($old && $old !== $path) {
            Storage::disk('avatars')->delete($old);
        }

        return back()->with('success', 'Logo updated.');
    }
}
