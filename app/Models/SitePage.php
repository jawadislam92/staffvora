<?php

namespace App\Models;

use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A public marketing page composed of ordered content blocks. `blocks` is the
 * working draft the builder edits; `published_blocks` is what the public sees.
 * Publishing copies draft → live and busts the cache, so the owner can edit
 * freely without anything going live until they hit Publish.
 *
 * XSS: `rich_text` blocks are injected into the public page with
 * `dangerouslySetInnerHTML`, so any `html` value in a block is run through
 * App\Support\HtmlSanitizer. That happens TWICE, deliberately:
 *   - on WRITE (setAttribute below), which covers the builder, both seeders and
 *     any future import path, because it sits on the model rather than in one
 *     controller's validation rules; and
 *   - on READ (publicPayload below), because rows written before the sanitiser
 *     existed — every legal page currently in a live database is one — were
 *     never cleaned, and a row is not trustworthy merely because it is stored.
 * The sanitiser is idempotent, so the second pass is a no-op on clean content.
 */
class SitePage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'blocks' => 'array',
        'published_blocks' => 'array',
    ];

    /**
     * Sanitise block markup on the way in, whatever the write path.
     *
     * This overrides setAttribute rather than declaring an Eloquent mutator so
     * it composes with the `array` cast above instead of competing with it.
     */
    public function setAttribute($key, $value)
    {
        if (in_array($key, ['blocks', 'published_blocks'], true) && is_array($value)) {
            $value = HtmlSanitizer::sanitizeBlocks($value);
        }

        return parent::setAttribute($key, $value);
    }

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

    /**
     * The payload handed to the public renderer. Blocks are sanitised again
     * here — see the class docblock: this is the pass that protects against a
     * row that predates the sanitiser or was written around the model.
     */
    public function publicPayload(): array
    {
        return [
            'slug' => $this->slug,
            'blocks' => HtmlSanitizer::sanitizeBlocks($this->published_blocks ?? []),
            'meta' => [
                'title' => $this->meta_title ?: $this->title,
                'description' => $this->meta_description,
            ],
        ];
    }
}
