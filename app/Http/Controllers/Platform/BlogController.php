<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Support\HtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * No-code blog management in the Platform console (platform-admin only). Mirrors
 * the page builder: list posts, write/edit one in a simple form (title, slug,
 * cover, body HTML, category, SEO), draft → publish.
 */
class BlogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/Blog/Index', [
            'posts' => BlogPost::orderByRaw('COALESCE(published_at, created_at) desc')->get()
                ->map(fn (BlogPost $p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'slug' => $p->slug,
                    'status' => $p->status,
                    'category' => $p->category,
                    'date' => optional($p->published_at)->format('M j, Y'),
                ])->all(),
        ]);
    }

    public function create(): Response
    {
        return $this->editor(new BlogPost(['status' => 'draft']));
    }

    public function edit(BlogPost $post): Response
    {
        return $this->editor($post);
    }

    private function editor(BlogPost $post): Response
    {
        return Inertia::render('Platform/Blog/Editor', [
            'post' => [
                'id' => $post->id,
                'title' => $post->title,
                'slug' => $post->slug,
                'excerpt' => $post->excerpt,
                'body' => $post->body,
                'cover_path' => $post->cover_path,
                'author_name' => $post->author_name,
                'category' => $post->category,
                'status' => $post->status ?: 'draft',
                'meta_title' => $post->meta_title,
                'meta_description' => $post->meta_description,
                'published_at' => optional($post->published_at)->toDateString(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePost($request, null);
        $attrs = $this->fill($data);
        if ($request->boolean('publish')) {
            $attrs['status'] = 'published';
            $attrs['published_at'] = now();
        }
        $post = BlogPost::create($attrs);

        return redirect()->route('platform.blog.edit', $post)
            ->with('success', $request->boolean('publish') ? 'Published — your post is now live.' : 'Post created.');
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $data = $this->validatePost($request, $post->id);
        $post->update($this->fill($data, $post));

        return back()->with('success', 'Saved.');
    }

    public function publish(BlogPost $post): RedirectResponse
    {
        $post->update([
            'status' => 'published',
            'published_at' => $post->published_at ?: now(),
        ]);

        return back()->with('success', 'Published — your post is now live.');
    }

    public function unpublish(BlogPost $post): RedirectResponse
    {
        $post->update(['status' => 'draft']);

        return back()->with('success', 'Moved back to draft.');
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        $post->delete();

        return redirect()->route('platform.blog.index')->with('success', 'Post deleted.');
    }

    private function validatePost(Request $request, ?int $ignoreId): array
    {
        return $request->validate([
            'title' => 'required|string|max:160',
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:blog_posts,slug'.($ignoreId ? ','.$ignoreId : '')],
            'excerpt' => 'nullable|string|max:500',
            'body' => 'nullable|string',
            'cover_path' => 'nullable|string|max:2048',
            'author_name' => 'nullable|string|max:120',
            'category' => 'nullable|string|max:80',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string|max:400',
        ]);
    }

    /**
     * Normalise the post before it reaches the model.
     *
     * The body goes through App\Support\HtmlSanitizer — the same allow-list
     * parser the rest of the public site uses, and the same one BlogPost applies
     * on write and again on read.
     *
     * WHAT USED TO BE HERE, AND WHY IT IS GONE. This method ran three
     * hand-rolled regexes over the body first, including
     * `preg_replace('#javascript:#i', '', $html)` — a blind substring delete
     * across the whole document, attribute or not. By the time the real
     * sanitiser existed those regexes added nothing (an allow-list parser drops
     * `<script>`, every `on*` handler and every unsafe href by construction),
     * but they were still MANGLING legitimate copy: a security post or a how-to
     * that names the `javascript:` scheme in a `<code>` block silently lost the
     * word, in the operator's saved draft, with no error and no way to get it
     * back. A guard that only ever damages honest content is not a guard.
     *
     * Regex is the wrong tool here in any case: `<scr<script>ipt>` defeats the
     * tag pattern, and `on\w+=` cannot tell an attribute from prose. The parser
     * decides what an attribute is by parsing.
     */
    private function fill(array $data, ?BlogPost $post = null): array
    {
        if (array_key_exists('body', $data)) {
            $data['body'] = HtmlSanitizer::clean($data['body']);
        }
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']);
        }

        return $data;
    }
}
