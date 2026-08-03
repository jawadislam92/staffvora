<?php

namespace App\Models;

use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A marketing blog post. Published posts (status=published, published_at in the
 * past) are public at /blog/{slug}; drafts are only visible in the builder.
 *
 * `body` is operator-authored HTML rendered with dangerouslySetInnerHTML by
 * resources/js/Pages/Site/Blog/Show.jsx, so it is sanitised in BOTH directions
 * for exactly the reasons SitePage documents: on WRITE so every path (builder,
 * seeder, any future import) is covered by the model rather than by one
 * controller's validation rules, and on READ because rows written before the
 * sanitiser existed were never cleaned and a row is not trustworthy merely
 * because it is stored. HtmlSanitizer is idempotent, so the second pass is a
 * no-op on clean content.
 */
class BlogPost extends Model
{
    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function setAttribute($key, $value)
    {
        if ($key === 'body' && is_string($value)) {
            $value = HtmlSanitizer::clean($value);
        }

        return parent::setAttribute($key, $value);
    }

    public function scopeLive(Builder $q): Builder
    {
        return $q->where('status', 'published')
            ->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    public function url(): string
    {
        return url('/blog/'.$this->slug);
    }

    /** Card payload for the /blog index. */
    public function cardPayload(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'cover' => $this->cover_path,
            'author' => $this->author_name,
            'category' => $this->category,
            'date' => optional($this->published_at ?? $this->created_at)->toDateString(),
            'date_label' => optional($this->published_at ?? $this->created_at)->format('M j, Y'),
        ];
    }

    /** Full payload for a single post page. */
    public function publicPayload(): array
    {
        return array_merge($this->cardPayload(), [
            'body' => HtmlSanitizer::clean((string) $this->body),
            'meta' => [
                'title' => $this->meta_title ?: $this->title,
                'description' => $this->meta_description ?: $this->excerpt,
            ],
        ]);
    }
}
