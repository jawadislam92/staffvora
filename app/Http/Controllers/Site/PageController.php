<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\SiteMedia;
use App\Models\SitePage;
use App\Models\SiteTheme;
use App\Support\DefaultSiteContent;
use App\Support\SiteNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Renders the public, builder-driven marketing website (home + any published
 * page) and serves builder-uploaded images from S3 with long cache headers.
 * All public; no tenant context.
 */
class PageController extends Controller
{
    public function home(Request $request)
    {
        DefaultSiteContent::ensure();

        return $this->render('home', $request);
    }

    public function show(Request $request, string $slug)
    {
        return $this->render($slug, $request);
    }

    private function render(string $slug, Request $request)
    {
        $page = SitePage::published($slug);
        abort_unless($page, 404);

        $payload = $page->publicPayload();
        $payload['meta']['canonical'] = $slug === 'home' ? url('/') : url('/'.$slug);
        $payload['meta']['robots'] = 'index,follow';

        return Inertia::render('Site/Show', [
            'page' => $payload,
            'theme' => SiteTheme::current()->publicPayload(),
            'brand' => $this->brandPayload(),
            'nav' => SiteNavigation::resolve(),
            'plans' => $this->plansPayload(),
            'jsonLd' => $this->jsonLd($payload),
            'isAuthed' => (bool) $request->user(),
        ]);
    }

    /**
     * Schema.org JSON-LD for the page: Organization + WebSite site-wide, plus a
     * FAQPage when the page carries an FAQ block (so the Q&As can earn rich
     * results). Rendered as a <script type="application/ld+json"> by Show.jsx.
     */
    private function jsonLd(array $payload): array
    {
        $b = config('brand');
        $graph = [
            [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => $b['name'] ?? 'Tracksuite',
                'url' => url('/'),
                'description' => $b['description'] ?? null,
                'email' => $b['support_email'] ?? null,
                'sameAs' => array_values(array_filter($b['socials'] ?? [])),
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $b['name'] ?? 'Tracksuite',
                'url' => url('/'),
            ],
        ];

        $faq = collect($payload['blocks'] ?? [])->firstWhere('type', 'faq');
        $items = collect($faq['data']['items'] ?? [])->filter(fn ($i) => ! empty($i['q']) && ! empty($i['a']));
        if ($items->isNotEmpty()) {
            $graph[] = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => $items->map(fn ($i) => [
                    '@type' => 'Question',
                    'name' => $i['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i['a']],
                ])->values()->all(),
            ];
        }

        return $graph;
    }

    /** XML sitemap of every published marketing page + blog post (for search engines). */
    public function sitemap()
    {
        $entries = SitePage::where('status', 'published')->get(['slug', 'updated_at'])
            ->map(fn (SitePage $p) => [
                'loc' => $p->slug === 'home' ? url('/') : url('/'.$p->slug),
                'lastmod' => $p->updated_at->toAtomString(),
                'priority' => $p->slug === 'home' ? '1.0' : '0.7',
            ]);

        if (config('features.blog', false)) {
            $entries->push(['loc' => url('/blog'), 'lastmod' => now()->toAtomString(), 'priority' => '0.6']);
            foreach (\App\Models\BlogPost::live()->get() as $post) {
                $entries->push(['loc' => $post->url(), 'lastmod' => $post->updated_at->toAtomString(), 'priority' => '0.6']);
            }
        }

        $urls = $entries->map(fn ($e) => '  <url><loc>'.e($e['loc']).'</loc><lastmod>'.$e['lastmod']
            .'</lastmod><changefreq>weekly</changefreq><priority>'.$e['priority'].'</priority></url>')->implode("\n");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".$urls."\n".'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /** Public plan tiers for the PricingTable block — wired to the live catalogue. */
    private function plansPayload(): array
    {
        \App\Models\Plan::ensureSeeded();

        return \App\Models\Plan::where('is_public', true)->orderBy('sort')->get()
            ->map(fn (\App\Models\Plan $p) => [
                'key' => $p->key,
                'name' => $p->name,
                'price_cents' => (int) $p->price_cents,
                'currency' => $p->currency ?: 'USD',
                'max_users' => $p->max_users,
                'contact' => $p->key === 'enterprise',
            ])->values()->all();
    }

    /**
     * The brand identity + palette the marketing site renders from — the single
     * swap point (config/brand.php). Emitted as CSS vars on the page root so no
     * block hardcodes a hex (SITE_BUILD_SPEC §3).
     */
    private function brandPayload(): array
    {
        $b = config('brand');

        return [
            'name' => $b['name'] ?? 'Tracksuite',
            'tagline' => $b['tagline'] ?? null,
            'legal_name' => $b['legal_name'] ?? null,
            'colors' => $b['colors'] ?? [],
            'colors_dark' => $b['colors_dark'] ?? [],
            'socials' => array_filter($b['socials'] ?? []),
            'support_email' => $b['support_email'] ?? null,
            'whatsapp' => $b['whatsapp'] ?? null,
            'phone' => $b['phone'] ?? null,
            'address' => $b['address'] ?? null,
            'demo_url' => $b['demo_url'] ?? null,
            'pkr_rate' => $b['pkr_rate'] ?? 280,
        ];
    }

    /** Serve a builder-uploaded image by id, cached a month, ETag-validated. */
    public function media(Request $request, SiteMedia $media)
    {
        $path = $media->path;
        abort_unless(Storage::disk('avatars')->exists($path), 404);

        $etag = '"'.substr(md5($path), 0, 16).'"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=2592000']);
        }

        return response(Storage::disk('avatars')->get($path), 200, [
            'Content-Type' => $media->mime ?: 'image/jpeg',
            'Cache-Control' => 'public, max-age=2592000',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Serve the site logo (downscaled + cached), like BrandingController::logo. */
    public function logo(Request $request)
    {
        $path = SiteTheme::current()->logo_path;
        abort_unless((bool) $path, 404);

        $etag = '"'.substr(md5($path), 0, 16).'"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=604800']);
        }

        $cacheKey = 'site-logo-thumb:'.md5($path);
        $cached = Cache::remember($cacheKey, now()->addDays(7), function () use ($path) {
            if (! Storage::disk('avatars')->exists($path)) {
                return false;
            }
            $fallbackMime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', default => 'image/jpeg',
            };
            if ($fallbackMime === 'image/svg+xml') {
                return ['bytes' => Storage::disk('avatars')->get($path), 'mime' => $fallbackMime];
            }
            $resized = \App\Support\ImageResizer::fit(Storage::disk('avatars')->get($path), 400);

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
}
