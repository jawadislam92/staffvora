<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\SiteTheme;
use App\Support\SiteContext;
use App\Support\SiteNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Public marketing blog — /blog (index) and /blog/{slug} (post). Renders through
 * the same SiteShell as the block-pages, so the day/night theme, nav, footer and
 * SEO all carry over. Only live posts are visible.
 */
class BlogController extends Controller
{
    public function index(Request $request)
    {
        \App\Support\DefaultSiteContent::ensure();

        $paginator = BlogPost::live()->paginate(9);
        $posts = collect($paginator->items())->map->cardPayload()->all();

        return Inertia::render('Site/Blog/Index', [
            'brand' => SiteContext::brand(),
            'theme' => SiteTheme::current()->publicPayload(),
            'nav' => SiteNavigation::resolve(),
            'isAuthed' => (bool) $request->user(),
            'posts' => $posts,
            'pagination' => [
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'title' => 'Blog — '.config('brand.name', 'Tracksuite'),
                'description' => 'Guides on time tracking, remote teams and building a humane, productive workplace.',
                'canonical' => url('/blog'),
                'robots' => 'index,follow',
            ],
            'jsonLd' => [[
                '@context' => 'https://schema.org',
                '@type' => 'Blog',
                'name' => config('brand.name', 'Tracksuite').' Blog',
                'url' => url('/blog'),
            ]],
        ]);
    }

    public function show(Request $request, string $slug)
    {
        $post = BlogPost::live()->where('slug', $slug)->first();
        abort_unless($post, 404);

        $payload = $post->publicPayload();
        $payload['meta']['canonical'] = $post->url();
        $payload['meta']['robots'] = 'index,follow';
        $payload['meta']['type'] = 'article';
        if ($post->cover_path) {
            $payload['meta']['image'] = $post->cover_path;
        }

        return Inertia::render('Site/Blog/Show', [
            'brand' => SiteContext::brand(),
            'theme' => SiteTheme::current()->publicPayload(),
            'nav' => SiteNavigation::resolve(),
            'isAuthed' => (bool) $request->user(),
            'post' => $payload,
            'jsonLd' => [[
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $post->title,
                'description' => $post->excerpt,
                'image' => $post->cover_path ?: null,
                'datePublished' => optional($post->published_at)->toAtomString(),
                'dateModified' => $post->updated_at->toAtomString(),
                'author' => ['@type' => 'Person', 'name' => $post->author_name ?: config('brand.name', 'Tracksuite')],
                'publisher' => ['@type' => 'Organization', 'name' => config('brand.name', 'Tracksuite')],
                'mainEntityOfPage' => $post->url(),
            ]],
        ]);
    }
}
