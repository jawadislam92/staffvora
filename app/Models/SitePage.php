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
    /**
     * Longest slug we will ever build a cache key from.
     *
     * A cache key is a file on the production (file) cache driver, and the
     * public `/{slug}` catch-all lets an anonymous visitor choose the slug. The
     * builder itself caps a slug at 120 characters (SiteBuilderController), so
     * anything longer cannot match a row and must never reach the cache.
     */
    private const MAX_SLUG_LENGTH = 120;

    /**
     * The only shape a real page slug can have: lowercase alphanumerics and
     * hyphens, with at most one nested level (`solutions/agencies`). This is the
     * public route's own pattern, restated here because `published()` is also
     * reachable from code that never went through the router.
     */
    private const SLUG_PATTERN = '#^[a-z0-9][a-z0-9-]*(?:/[a-z0-9][a-z0-9-]*)?$#';

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

    /**
     * The live page (published blocks + meta) for a slug, cached. Null if
     * unpublished/missing.
     *
     * ⚠️ ONLY A HIT IS EVER WRITTEN TO THE CACHE. This used to be a plain
     * Cache::remember(), and Laravel's remember() stores the null a miss
     * returns — so every made-up URL an anonymous visitor asked for minted a
     * permanent entry keyed by their own string. On the production file driver
     * that is one file plus one inode per bogus request, and the file store
     * only reclaims an expired entry when that exact key is read again, which
     * for a random slug never happens. A few thousand curl calls therefore fill
     * the disk — and this VPS co-hosts other live sites. Resolving first and
     * caching only a real page bounds the key space to the pages that actually
     * exist; a 404 now costs one indexed lookup instead of a permanent write.
     */
    public static function published(string $slug): ?self
    {
        $slug = static::normalizeSlug($slug);

        if ($slug === null) {
            return null;
        }

        $key = static::cacheKey($slug);
        $cached = Cache::get($key);

        if ($cached instanceof static) {
            return $cached;
        }

        $page = static::where('slug', $slug)->where('status', 'published')->first();

        if ($page !== null) {
            Cache::put($key, $page, now()->addHours(6));
        }

        return $page;
    }

    public static function forgetCache(string $slug): void
    {
        $slug = static::normalizeSlug($slug);

        if ($slug !== null) {
            Cache::forget(static::cacheKey($slug));
        }
    }

    /**
     * Fold a requested slug onto the single canonical form used for both the
     * lookup and the cache key, or reject it outright.
     *
     * Case, surrounding whitespace and leading/trailing slashes are normalised
     * so `/Pricing`, `/pricing ` and `/pricing/` cannot become three cache
     * entries for one page. Anything that is not a plausible slug — non-ASCII,
     * control characters, over-long strings — is rejected here and therefore
     * never touches the cache or the database at all.
     */
    private static function normalizeSlug(string $slug): ?string
    {
        $slug = strtolower(trim($slug, " \t\n\r\0\x0B/"));

        if ($slug === '' || strlen($slug) > self::MAX_SLUG_LENGTH) {
            return null;
        }

        return preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : null;
    }

    private static function cacheKey(string $normalizedSlug): string
    {
        return 'site_page.'.$normalizedSlug;
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
