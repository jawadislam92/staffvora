<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A marketing blog post. Published posts (status=published, published_at in the
 * past) are public at /blog/{slug}; drafts are only visible in the builder.
 */
class BlogPost extends Model
{
    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
    ];

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
            'body' => $this->body,
            'meta' => [
                'title' => $this->meta_title ?: $this->title,
                'description' => $this->meta_description ?: $this->excerpt,
            ],
        ]);
    }
}
