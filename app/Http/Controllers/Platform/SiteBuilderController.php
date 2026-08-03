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
use Illuminate\Support\Facades\Schema;
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
    /**
     * Page `title` / `meta_title` / `meta_description` live in the columns the
     * PUBLIC renderer reads (SitePage::publicPayload). Writing them on every
     * draft save meant an unpublished edit went live on its own the moment the
     * six-hour page cache expired — the Draft → Publish promise only ever
     * covered `blocks`. The draft copies now sit in `draft_*` columns and are
     * promoted by publish(), same as blocks.
     *
     * Guarded rather than assumed: the site is already live, so the code has to
     * behave (as it did before) between the deploy and `migrate --force`.
     */
    private function hasDraftMetaColumns(): bool
    {
        return Schema::hasColumn('site_pages', 'draft_title');
    }

    public function index(): Response
    {
        DefaultSiteContent::ensure();

        $columns = ['id', 'slug', 'title', 'status', 'updated_at'];

        if ($this->hasDraftMetaColumns()) {
            $columns[] = 'draft_title';
        }

        $pages = SitePage::orderBy('sort')->orderBy('id')->get($columns)
            // The builder list is an editing surface: show the draft name.
            ->map(fn (SitePage $page) => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->draft_title ?? $page->title,
                'status' => $page->status,
                'updated_at' => $page->updated_at,
            ]);

        return Inertia::render('Platform/SiteBuilder/Index', [
            'pages' => $pages,
            'theme' => SiteTheme::current(),
            'palettes' => SitePalettes::options(),
            'fonts' => SitePalettes::fonts(),
            'editableColors' => SitePalettes::editableKeys(),
        ]);
    }

    public function edit(SitePage $page): Response
    {
        $fields = $page->only(['id', 'slug', 'title', 'meta_title', 'meta_description', 'status', 'blocks']);

        if ($this->hasDraftMetaColumns() && $page->draft_title !== null) {
            // Editor shows the draft, not what is live.
            $fields['title'] = $page->draft_title;
            $fields['meta_title'] = $page->draft_meta_title;
            $fields['meta_description'] = $page->draft_meta_description;
        }

        return Inertia::render('Platform/SiteBuilder/Editor', [
            'page' => $fields,
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
        $request->validate([
            'title' => 'required|string|max:120',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string|max:400',
            'blocks' => 'present|array',
            // `blocks.*.data.html` is what ends up inside dangerouslySetInnerHTML.
            // The sanitiser now coerces a non-string value instead of skipping it,
            // but a malformed block should be REJECTED at the door rather than
            // silently normalised: a client sending `data[html][0]=…` is not a
            // browser using the builder, it is someone probing the sanitiser.
            'blocks.*.data.html' => 'nullable|string',
        ]);

        // Read `blocks` off the request, NOT off validate()'s return value:
        // adding a wildcard child rule makes Validator::validated() drop the
        // parent key, which would silently save an empty page. `blocks` is
        // free-form by design — HtmlSanitizer, not validation, is its guard.
        $blocks = HtmlSanitizer::sanitizeBlocks((array) $request->input('blocks', []));

        $attributes = ['blocks' => $blocks];

        if ($this->hasDraftMetaColumns()) {
            $attributes['draft_title'] = $request->string('title')->toString();
            $attributes['draft_meta_title'] = $request->input('meta_title');
            $attributes['draft_meta_description'] = $request->input('meta_description');
        } else {
            $attributes['title'] = $request->string('title')->toString();
            $attributes['meta_title'] = $request->input('meta_title');
            $attributes['meta_description'] = $request->input('meta_description');
        }

        $page->update($attributes);

        return back()->with('success', 'Draft saved.');
    }

    public function publish(SitePage $page): RedirectResponse
    {
        if ($this->hasDraftMetaColumns() && $page->draft_title !== null) {
            // Promote the draft title/SEO alongside the blocks — publishing is
            // the only moment either may become visible to the public.
            $page->forceFill([
                'title' => $page->draft_title,
                'meta_title' => $page->draft_meta_title,
                'meta_description' => $page->draft_meta_description,
            ])->save();
        }

        $page->publish();

        return back()->with('success', 'Published — your changes are now live.');
    }

    public function createPage(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:site_pages,slug'],
        ]);

        $attributes = [
            'slug' => $data['slug'],
            'title' => $data['title'],
            'status' => 'draft',
            'blocks' => [],
            'published_blocks' => [],
            'sort' => (int) SitePage::max('sort') + 1,
        ];

        if ($this->hasDraftMetaColumns()) {
            $attributes['draft_title'] = $data['title'];
        }

        $page = SitePage::create($attributes);

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
        // Header nav, footer columns and social icons render in the chrome of
        // EVERY public page, straight into an <a href>. React 18 does not block
        // `javascript:` hrefs (the production react-dom bundle ships no URL
        // sanitiser), so one such link is a click away from running script on
        // staffvora.com — the same origin that holds the customer app's session
        // cookie. Same rule as the href check inside HtmlSanitizer::clean(),
        // reached through the same code so the two cannot drift apart.
        $safeLink = static function (string $attribute, mixed $value, callable $fail): void {
            if (! HtmlSanitizer::isSafeLink($value)) {
                $fail('The link must be an http(s), mailto:, tel: or site-relative URL.');
            }
        };

        $request->validate([
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
            'nav_links.*.url' => ['nullable', $safeLink],
            'nav_links.*.children' => ['nullable', 'array'],
            'nav_links.*.children.*.url' => ['nullable', $safeLink],
            'footer_columns' => 'present|array',
            'footer_columns.*.links' => ['nullable', 'array'],
            'footer_columns.*.links.*.url' => ['nullable', $safeLink],
            'social_links' => 'present|array',
            'social_links.*' => ['nullable', $safeLink],
            'contact_email' => 'nullable|email|max:120',
            'contact_phone' => 'nullable|string|max:60',
            'contact_address' => 'nullable|string|max:200',
        ]);

        // Taken from the request rather than validate()'s return value: adding a
        // wildcard child rule (nav_links.*.url) makes Validator::validated()
        // drop the PARENT key, which would wipe the header nav on every save.
        $data = $request->only([
            'site_name', 'tagline', 'accent_color', 'highlight_color',
            'palette_preset', 'palette', 'font', 'nav_links', 'footer_columns',
            'social_links', 'contact_email', 'contact_phone', 'contact_address',
        ]);

        // Belt and braces behind the rules above: the rules name the shapes the
        // builder UI produces, this walks whatever actually arrived — including
        // a nesting depth nobody thought to write a rule for — and neutralises
        // any `url`/`href` it finds. SiteTheme::publicPayload serves these
        // arrays verbatim, so the stored row has to be the clean one.
        foreach (['nav_links', 'footer_columns'] as $tree) {
            if (isset($data[$tree]) && is_array($data[$tree])) {
                $data[$tree] = HtmlSanitizer::sanitizeBlocks($data[$tree]);
            }
        }

        // social_links is a map (x, linkedin, …) => URL, so the key is the
        // network, not `url`; scheme-check the values directly. An unsafe or
        // non-scalar value collapses to '' rather than being dropped, because
        // clearing the field is how the operator overrides a brand default.
        $data['social_links'] = array_map(
            static fn (mixed $v): string => HtmlSanitizer::safeLink($v) ?? '',
            $data['social_links'] ?? []
        );

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

    /**
     * Builder image upload → S3, returns the servable URL for use in a block.
     *
     * NO SVG — deliberately. `/site/media/{id}` serves the stored bytes back
     * under the stored mime, inline, from the app origin, and an SVG is a
     * scriptable XML document: `<svg><script>…` executes with full access to
     * staffvora.com, which is the origin the customer app's session cookie is
     * scoped to. `X-Content-Type-Options: nosniff` does not help — the type is
     * not being sniffed, it is being honoured. That upload path never goes
     * through HtmlSanitizer, so it was a sanitiser-free stored-XSS channel.
     *
     * PNG/JPEG/WEBP cover every real use (logos included — the logo route
     * downscales raster formats). If SVG is ever wanted back it needs a
     * separate origin or `Content-Disposition: attachment` PLUS an XML
     * sanitiser; re-adding it to this list alone re-opens the hole.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192']]);

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
        // See uploadImage(): SVG is excluded because /site/logo serves the raw
        // bytes as image/svg+xml, inline, on the app origin.
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192']]);

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
