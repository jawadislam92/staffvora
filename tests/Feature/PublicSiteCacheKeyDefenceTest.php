<?php

namespace Tests\Feature;

use App\Models\SitePage;
use App\Support\DefaultSiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * THE PUBLIC MARKETING CATCH-ALL MUST NOT LET A STRANGER WRITE TO OUR DISK.
 *
 * `/{slug}` is anonymous, needs no cookie and no CSRF token, and accepts any
 * slug the route pattern allows. SitePage::published() used to wrap the lookup
 * in Cache::remember(), and remember() STORES the null a miss returns — so every
 * made-up URL minted a permanent cache entry keyed by the visitor's own string.
 * On the production file driver that is one file plus one inode per bogus
 * request, and the file store only reclaims an expired entry when that exact
 * key is read again, which for a random slug never happens. A few thousand curl
 * calls therefore fill the disk of a VPS that also hosts the owner's other live
 * businesses.
 *
 * These tests are the inverse of the audit's proof: N requests to N bogus slugs
 * must add ZERO cache entries, while a real page must still be cached.
 */
class PublicSiteCacheKeyDefenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seeding is a deploy step now, so the test does what the deploy does.
        DefaultSiteContent::ensure();
    }

    public function test_anonymous_404s_never_mint_a_cache_entry(): void
    {
        $before = $this->siteKeyCount();

        $slugs = [];
        for ($i = 0; $i < 50; $i++) {
            $slugs[] = 'flood-'.bin2hex(random_bytes(6)).'-'.$i;
        }

        foreach ($slugs as $slug) {
            $this->get('/'.$slug)->assertNotFound();
        }

        $this->assertSame(
            $before,
            $this->siteKeyCount(),
            'A 404 on the public catch-all wrote a cache entry keyed by attacker input.',
        );

        $this->assertArrayNotHasKey(
            Cache::getPrefix().'site_page.'.$slugs[0],
            Cache::getStore()->all(),
            'A cache key derived from unvalidated URL input is resident.',
        );
    }

    /** The optimisation the cache exists for must survive the fix. */
    public function test_a_published_page_is_still_cached_after_the_first_view(): void
    {
        $this->get('/')->assertOk();

        $this->assertArrayHasKey(
            Cache::getPrefix().'site_page.home',
            Cache::getStore()->all(),
            'The home page is no longer cached — the fix threw the baby out.',
        );

        DB::enableQueryLog();
        $this->get('/')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $pageLookups = array_filter($queries, fn ($q) => str_contains($q['query'], 'site_pages'));

        $this->assertSame([], array_values($pageLookups), 'A warm page view still hit site_pages.');
    }

    /**
     * Case, whitespace, stray slashes and non-ASCII look-alikes must all fold
     * onto the ONE canonical key (or onto no key at all). Otherwise the same
     * page can be requested in unlimited spellings and each spelling is a fresh
     * entry — the flood, wearing a hat.
     *
     * These go through the model rather than the router on purpose: the route
     * pattern already rejects most of them, and this asserts the defence that
     * remains when a future caller does not go through the router.
     */
    public function test_slug_variants_cannot_multiply_cache_entries(): void
    {
        SitePage::published('home');
        $baseline = $this->siteKeyCount();
        $this->assertSame(1, $baseline, 'expected exactly one cached page to start from');

        $variants = [
            'HOME',
            'Home',
            ' home ',
            "home\n",
            '/home/',
            "home\u{200b}",       // zero-width space
            "\u{0068}\u{043E}me", // Cyrillic 'о' homoglyph
            'ho me',
            'home?x=1',
            'home%00',
            str_repeat('a', 5000),
        ];

        foreach ($variants as $variant) {
            SitePage::published($variant);
        }

        $this->assertSame(
            $baseline,
            $this->siteKeyCount(),
            'A slug variant created its own cache entry.',
        );
    }

    /**
     * An over-long slug must be refused before it becomes a lookup or a cache
     * key — a cache key is a filename on the production driver.
     */
    public function test_an_over_long_slug_is_refused_without_a_database_lookup(): void
    {
        DB::enableQueryLog();
        $this->assertNull(SitePage::published(str_repeat('a', 400)));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, 'An over-long slug reached the database.');
        $this->assertSame(0, $this->siteKeyCount(), 'An over-long slug reached the cache.');
    }

    /** Cache busting has to normalise the same way, or publishing stops working. */
    public function test_publishing_busts_the_cache_whatever_the_spelling_of_the_slug(): void
    {
        $this->get('/')->assertOk();
        $this->assertArrayHasKey(Cache::getPrefix().'site_page.home', Cache::getStore()->all());

        SitePage::forgetCache('HOME ');

        $this->assertArrayNotHasKey(
            Cache::getPrefix().'site_page.home',
            Cache::getStore()->all(),
            'forgetCache() and published() disagree about how a slug is spelled.',
        );
    }

    private function siteKeyCount(): int
    {
        $store = Cache::getStore();

        if (! method_exists($store, 'all')) {
            $this->markTestSkipped('Cache store does not expose its contents.');
        }

        return count(array_filter(
            array_keys($store->all()),
            fn ($k) => str_contains((string) $k, 'site_page.')
        ));
    }
}
