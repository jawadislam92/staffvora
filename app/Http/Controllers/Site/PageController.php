<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\BlogPost;
use App\Models\Plan;
use App\Models\SiteMedia;
use App\Models\SitePage;
use App\Models\SiteTheme;
use App\Support\ImageGuard;
use App\Support\ImageResizer;
use App\Support\SiteContext;
use App\Support\SiteNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Renders the public, builder-driven marketing website (home + any published
 * page) and serves builder-uploaded images from S3 with long cache headers.
 * All public; no tenant context.
 */
class PageController extends Controller
{
    /**
     * Per-IP budget for /site/media/{id}, in requests per minute.
     *
     * KEYED ON THE IP, unlike the user-keyed limiters in RouteServiceProvider,
     * because there is no user to key on — that is the whole point of the route
     * — so the number has to survive a big customer whose whole office sits
     * behind one NAT looking at the marketing site. 600/minute is 10 requests a
     * second sustained from a single address: far above a page full of images
     * (the responses carry a 30-day Cache-Control and an ETag, so a real browser
     * fetches each asset once and then revalidates), and far below what a flood
     * needs to be interesting. Refusing an honest visitor an image is a worse
     * outcome than the attack, so this is deliberately loose — the streaming in
     * media() is the real fix, this is the ceiling on top of it.
     */
    private const MEDIA_HITS_PER_MINUTE = 600;

    /**
     * How much of an object we are willing to read in order to identify it.
     *
     * getimagesize() only needs the header, but "the header" for a JPEG means
     * everything up to the SOF marker, which sits behind whatever EXIF and ICC
     * blocks the camera wrote. 512 KB clears that with room to spare while being
     * 16x smaller than the 8 MB upload cap this route used to read in full on
     * every single anonymous hit.
     */
    private const SNIFF_BYTES = 524288;

    /**
     * Objects at or under this size are sniffed whole when the partial read was
     * inconclusive. Bounded by the upload cap, and only ever reached on a cache
     * miss for one specific media row — an attacker cannot make it repeat,
     * because the verdict is keyed on the row, not on anything they send.
     */
    private const FULL_SNIFF_LIMIT = 8388608;

    /**
     * ⚠️ READ-ONLY. Nothing on this path may write.
     *
     * `DefaultSiteContent::ensure()` used to run here, so every anonymous hit on
     * the least-protected page in the product re-ran the site seeder: ~13
     * site_pages lookups plus the blog seeder, in the request, for a page that
     * only ever needs to READ one row. Seeding is now a deploy step
     * (`php artisan site:sync`) — see App\Console\Commands\SyncSiteContent.
     */
    public function home(Request $request)
    {
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
            'addons' => $this->addonsPayload(),
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
                'name' => $b['name'] ?? 'Staffvora',
                'url' => url('/'),
                'description' => $b['description'] ?? null,
                'email' => $b['support_email'] ?? null,
                'sameAs' => array_values(array_filter($b['socials'] ?? [])),
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $b['name'] ?? 'Staffvora',
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
            foreach (BlogPost::live()->get() as $post) {
                $entries->push(['loc' => $post->url(), 'lastmod' => $post->updated_at->toAtomString(), 'priority' => '0.6']);
            }
        }

        $urls = $entries->map(fn ($e) => '  <url><loc>'.e($e['loc']).'</loc><lastmod>'.$e['lastmod']
            .'</lastmod><changefreq>weekly</changefreq><priority>'.$e['priority'].'</priority></url>')->implode("\n");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".$urls."\n".'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Public plan tiers for the PricingTable block — wired to the live catalogue.
     *
     * ⚠️ READ-ONLY. `Plan::ensureSeeded()` used to run here, which meant an
     * anonymous page view issued one SELECT per catalogue plan (and a write on a
     * cold catalogue) before it could render a price list. The catalogue is
     * seeded on deploy (`php artisan site:sync`, which calls PlanSeeder), so an
     * empty table here means "the deploy step has not run", not "seed it now".
     */
    private function plansPayload(): array
    {
        return Plan::where('is_public', true)->orderBy('sort')->get()
            ->map(fn (Plan $p) => [
                'key' => $p->key,
                'name' => $p->name,
                'price_cents' => (int) $p->price_cents,
                'currency' => $p->currency ?: 'USD',
                'max_users' => $p->max_users,
                'contact' => $p->key === 'enterprise',
            ])->values()->all();
    }

    /**
     * Public add-ons for the PricingTable block — wired to the live catalogue
     * for exactly the reason plans are. The Agency toolkit price used to sit in
     * the page's authored content, so changing it in the operator panel left the
     * public page advertising the old figure: a price a customer could
     * reasonably hold us to.
     *
     * ONLY `visible()` rows leave this method — `visibility = public` AND not
     * archived. Hidden, custom-negotiated and legacy add-ons are commercial
     * terms belonging to individual companies and must never reach an anonymous
     * page. Entitlements are deliberately NOT exposed: this is a price list, not
     * an access-control document.
     *
     * @return array<int, array<string, mixed>>
     */
    private function addonsPayload(): array
    {
        if (! Schema::hasTable('addons')) {
            return [];
        }

        return Addon::query()->visible()->ordered()->get()
            ->map(fn (Addon $a) => [
                'key' => $a->key,
                'name' => $a->name,
                'description' => $a->description,
                'price_cents' => (int) $a->price_cents,
                'price_cents_yearly' => $a->price_cents_yearly === null ? null : (int) $a->price_cents_yearly,
                'currency' => $a->currency ?: 'USD',
            ])->values()->all();
    }

    /**
     * The brand identity + palette the marketing site renders from — the single
     * swap point (config/brand.php). Emitted as CSS vars on the page root so no
     * block hardcodes a hex (SITE_BUILD_SPEC §3).
     */
    private function brandPayload(): array
    {
        return SiteContext::brand();
    }

    /**
     * Serve a builder-uploaded image by id, cached a month, ETag-validated.
     *
     * ⚠️ THE CONTENT-TYPE COMES FROM THE BYTES, NOT FROM `$media->mime`.
     *
     * The SVG hole was closed on the WRITE side (SiteBuilderController dropped
     * svg from its `mimes:` list) and that fixed nothing that was already in the
     * database. This route echoed the stored `mime` column verbatim, inline, on
     * the app origin — the origin the customer app's session cookie is scoped
     * to — so every SVG uploaded before that rule changed still executed
     * `<svg><script>` in the visitor's browser, forever. A stored column is a
     * record of what an earlier, weaker rule allowed; it is not a fact about the
     * bytes. `X-Content-Type-Options: nosniff` never helped here: the type was
     * not being sniffed, it was being obeyed.
     *
     * So the header in front of us decides, every time. Anything that is not a
     * raster image we recognise is served as an inert download rather than
     * rendered inline — 404 would silently blank existing marketing pages, and
     * the operator needs to SEE that an asset needs re-uploading.
     */
    public function media(Request $request, SiteMedia $media)
    {
        $disk = Storage::disk('avatars');
        $path = $media->path;
        abort_unless($disk->exists($path), 404);

        // Metered here rather than as route middleware, and AFTER the row has
        // resolved, on purpose. A named `throttle:` limiter keys its bucket on
        // md5($name.$key) — an opaque, attacker-reachable cache entry on an
        // anonymous route, which is precisely the shape the previous round spent
        // its time removing from this surface. Doing it in the action with a
        // readable key keeps the anonymous cache surface legible, and matches
        // DesktopDownloadController, the app's other public byte-serving route.
        // The cost being rationed is the transfer; a request for an id that does
        // not exist has already 404'd on one primary-key lookup, which is what
        // every other unmatched public URL costs.
        $key = 'site-media:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MEDIA_HITS_PER_MINUTE)) {
            abort(429, 'Too many image requests. Please slow down.');
        }

        RateLimiter::hit($key, 60);

        $etag = '"'.substr(md5($path), 0, 16).'"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=2592000']);
        }

        $size = (int) $disk->size($path);
        $mime = $this->mediaMime($media, $path, $size);

        if ($mime === null) {
            Log::warning('Site media is not a raster image; serving it inert.', [
                'site_media_id' => $media->id,
                'path' => $path,
                'stored_mime' => $media->mime,
            ]);

            return $disk->response($path, null, [
                // Not the stored mime, and never inline. octet-stream +
                // attachment + a null CSP means a browser that navigates
                // straight at this URL downloads a file instead of executing a
                // document, and an <img> pointing at it simply fails to render
                // — which is the visible signal that the asset must be replaced.
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="media-'.$media->id.'.bin"',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'public, max-age=2592000',
                'Content-Length' => $size,
                'ETag' => $etag,
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $disk->response($path, null, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=2592000',
            'Content-Length' => $size,
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The canonical mime for a media row, decided by the BYTES and remembered.
     *
     * The type still may not come from `$media->mime` — see the media() docblock
     * — but re-deriving it on every hit meant re-reading the whole object on
     * every hit, which is what made this anonymous route a memory amplifier.
     * The verdict is a property of the file, so it is cached against the row id
     * plus the byte size (a replacement upload changes the size, and a same-size
     * replacement still writes a different path, which changes the ETag and the
     * public URL). No part of the key is attacker-supplied: a request for a row
     * that does not exist 404s in route model binding before it gets here.
     */
    private function mediaMime(SiteMedia $media, string $path, int $size): ?string
    {
        $mime = Cache::remember(
            'site_media_mime:'.$media->id.':'.$size,
            now()->addDays(30),
            fn (): string => ImageGuard::serveMime($this->sniffBytes($path, $size)) ?? '',
        );

        return $mime === '' ? null : $mime;
    }

    /** Enough of the object to identify it, without pulling all of it into RAM. */
    private function sniffBytes(string $path, int $size): string
    {
        $disk = Storage::disk('avatars');

        if ($size <= self::SNIFF_BYTES) {
            return (string) $disk->get($path);
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return '';
        }

        $head = (string) stream_get_contents($stream, self::SNIFF_BYTES);
        fclose($stream);

        // A genuine image whose header did not fit gets one honest second look
        // rather than being demoted to an inert download — mislabelling a real
        // marketing photo is worse for the product than the attack this whole
        // change is about.
        if ($head !== '' && ImageGuard::serveMime($head) === null && $size <= self::FULL_SNIFF_LIMIT) {
            return (string) $disk->get($path);
        }

        return $head;
    }

    /**
     * Serve the site logo (downscaled + cached), like BrandingController::logo.
     *
     * ⚠️ ANONYMOUS, and the type comes from the BYTES.
     *
     * There used to be an explicit branch here that returned the raw stored
     * bytes as `image/svg+xml`, inline — a hand-written exemption from every
     * other protection on this path. It is gone. An SVG is a scriptable XML
     * document; serving one inline from this origin is stored XSS against every
     * visitor, and the extension it was keyed off is not evidence of anything.
     *
     * A non-raster logo now 404s, which is the RIGHT failure for a logo
     * specifically: the frontend already falls back to the bundled mark, so the
     * page still renders correctly instead of showing a broken image.
     */
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

            $bytes = (string) Storage::disk('avatars')->get($path);
            $sourceMime = ImageGuard::serveMime($bytes);

            if ($sourceMime === null) {
                Log::warning('Site logo is not a raster image; refusing to serve it.', [
                    'path' => $path,
                ]);

                return false;
            }

            $resized = ImageResizer::fit($bytes, 400, ImageGuard::MAX_PIXELS, 'site-logo:'.md5($path));

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
}
