<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A public marketing page composed of ordered content blocks. `blocks` is the
 * working draft the builder edits; `published_blocks` is what the public sees.
 * Publishing copies draft → live and busts the cache, so the owner can edit
 * freely without anything going live until they hit Publish.
 */
class SitePage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'blocks' => 'array',
        'published_blocks' => 'array',
    ];

    /** Copy the working draft to the live snapshot and bust the public cache. */
    public function publish(): void
    {
        $this->update([
            'published_blocks' => $this->blocks ?? [],
            'status' => 'published',
        ]);
        static::forgetCache($this->slug);
    }

    /** The live page (published blocks + meta) for a slug, cached. Null if unpublished/missing. */
    public static function published(string $slug): ?self
    {
        return Cache::remember('site_page.'.$slug, now()->addHours(6), function () use ($slug) {
            return static::where('slug', $slug)->where('status', 'published')->first();
        });
    }

    public static function forgetCache(string $slug): void
    {
        Cache::forget('site_page.'.$slug);
    }

    public function publicPayload(): array
    {
        return [
            'slug' => $this->slug,
            'blocks' => $this->published_blocks ?? [],
            'meta' => [
                'title' => $this->meta_title ?: $this->title,
                'description' => $this->meta_description,
            ],
        ];
    }
}
