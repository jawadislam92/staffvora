<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\SiteMedia;
use App\Models\SitePage;
use App\Models\SiteTheme;
use App\Support\DefaultSiteContent;
use App\Support\HtmlSanitizer;
use App\Support\SitePalettes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The no-code page builder, platform-admin only (routes are under the
 * `platform` middleware group). Lets the owner edit every marketing page's
 * blocks, the global theme/header/footer, and upload images — no code, no
 * deploy. Draft → Publish keeps unfinished edits off the live site.
 */
class SiteBuilderController extends Controller
{
    public function index(): Response
    {
        DefaultSiteContent::ensure();

        return Inertia::render('Platform/SiteBuilder/Index', [
            'pages' => SitePage::orderBy('sort')->orderBy('id')->get(['id', 'slug', 'title', 'status', 'updated_at']),
            'theme' => SiteTheme::current(),
            'palettes' => SitePalettes::options(),
            'fonts' => SitePalettes::fonts(),
            'editableColors' => SitePalettes::editableKeys(),
        ]);
    }

    public function edit(SitePage $page): Response
    {
        return Inertia::render('Platform/SiteBuilder/Editor', [
            'page' => $page->only(['id', 'slug', 'title', 'meta_title', 'meta_description', 'status', 'blocks']),
            'theme' => SiteTheme::current()->only(['accent_color', 'highlight_color', 'site_name', 'logo_path']),
            'logoUrl' => SiteTheme::current()->logo_path ? route('site.media.logo').'?v='.uniqid() : null,
        ]);
    }

    /**
     * Save the working draft.
     *
     * `blocks` is free-form JSON by design (the builder gains block types
     * without a schema migration), and `rich_text` blocks end up inside
     * `dangerouslySetInnerHTML` on the PUBLIC marketing site — including the
     * legal pages, which are now entirely rich_text. Validation cannot express
     * "safe HTML", so the markup goes through App\Support\HtmlSanitizer.
     *
     * The call here is deliberate redundancy: SitePage::setAttribute is what
     * actually guarantees it (it covers every write path, including the
     * seeders), but the save path is where the next person looks, and until
     * now the comments in this area claimed a sanitiser that did not exist.
     *
     * Only platform admins reach this route (the `platform` middleware group).
     * That lowers the likelihood, not the impact: the output is served to every
     * anonymous visitor, and an operator account is a phishing target.
     */
    public function saveDraft(Request $request, SitePage $page): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string|max:400',
            'blocks' => 'present|array',
        ]);

        $data['blocks'] = HtmlSanitizer::sanitizeBlocks($data['blocks']);

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
            'palette_preset' => ['nullable', 'string', 'max:40'],
            'palette' => ['nullable', 'array'],
            'palette.light' => ['nullable', 'array'],
            'palette.dark' => ['nullable', 'array'],
            'font' => ['nullable', 'string', 'max:60'],
            'nav_links' => 'present|array',
            'footer_columns' => 'present|array',
            'social_links' => 'present|array',
            'contact_email' => 'nullable|email|max:120',
            'contact_phone' => 'nullable|string|max:60',
            'contact_address' => 'nullable|string|max:200',
        ]);

        // Keep only valid hex in custom palette overrides; drop empty entries.
        if (! empty($data['palette'])) {
            $hex = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v);
            foreach (['light', 'dark'] as $mode) {
                $data['palette'][$mode] = array_filter($data['palette'][$mode] ?? [], $hex);
            }
            $data['palette'] = array_filter($data['palette']);
        }

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
