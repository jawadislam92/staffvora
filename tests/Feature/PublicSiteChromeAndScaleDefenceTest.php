<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\PlatformUser;
use App\Models\SitePage;
use App\Models\SiteTheme;
use App\Support\DefaultSiteContent;
use App\Support\HtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE GAPS AROUND THE SANITISER.
 *
 * The sanitiser itself held under re-attack. These are the four things sitting
 * next to it that did not:
 *
 *  1. SiteTheme — the LAST public-content model with no read pass and no model
 *     guard. The URL fix was write-path only, so it was not retroactive: a
 *     `javascript:` nav/footer/social URL already stored in `site_themes` (which
 *     is the state of the live site) was served verbatim and rendered straight
 *     into an href by SiteHeader/SiteFooter, neither of which checked it.
 *  2. JSON-LD — FAQ q/a and blog title/excerpt are copied verbatim into a graph
 *     that SiteShell injects with dangerouslySetInnerHTML. `JSON.stringify`
 *     escapes neither `<` nor `/`. Inert today only by an accident of the HTML
 *     parser; live the day Inertia SSR is switched on.
 *  3. Platform\BlogController::sanitize() — a dead regex pass that deleted the
 *     literal substring `javascript:` from every post body. No security value
 *     once the real sanitiser runs, and it silently mangled honest copy.
 *  4. SCALE — `SitePage::published()` cached the model but `publicPayload()`
 *     re-ran the DOM sanitiser on EVERY anonymous request, and `saveDraft` put
 *     no ceiling on block count or total size. That is an operator-triggered
 *     CPU amplifier on the busiest public page of a co-hosted VPS.
 *
 * Every test here failed against the code as it stood before this round.
 */
class PublicSiteChromeAndScaleDefenceTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $email = 'chrome@platform.test'): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Operator',
            'email' => $email,
            'password' => 'operator-secret-pw',
            'role' => 'owner',
        ]);
    }

    // ================================================================
    // 1. SiteTheme — write pass, read pass, and the render side.
    // ================================================================

    /**
     * THE RETROACTIVITY BUG. The round-1 fix lived in
     * SiteBuilderController::updateTheme, so it only ever cleaned links saved
     * AFTER it shipped. Everything already in the table — which on a live site
     * is every link the owner has ever set — kept being served raw.
     */
    public function test_a_javascript_url_already_stored_in_site_themes_is_neutralised_on_read(): void
    {
        DB::table('site_themes')->insert([
            'id' => 1,
            'nav_links' => json_encode([
                ['label' => 'Pricing', 'url' => 'javascript:alert(1)'],
                ['label' => 'Product', 'children' => [['label' => 'Deep', 'url' => 'JaVaScRiPt:alert(2)']]],
            ]),
            'footer_columns' => json_encode([
                ['title' => 'Legal', 'links' => [['label' => 'Terms', 'url' => "java\tscript:alert(3)"]]],
            ]),
            'social_links' => json_encode([
                'x' => 'javascript:alert(4)',
                'linkedin' => 'data:text/html,<script>alert(5)</script>',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SiteTheme::forgetCache();

        $payload = SiteTheme::current()->publicPayload();

        $this->assertNull($payload['nav_links'][0]['url']);
        $this->assertNull($payload['nav_links'][1]['children'][0]['url']);
        $this->assertNull($payload['footer_columns'][0]['links'][0]['url']);
        $this->assertSame('', $payload['social_links']['x']);
        $this->assertSame('', $payload['social_links']['linkedin']);

        $json = json_encode($payload);
        $this->assertStringNotContainsString('alert(', $json, 'a stored payload reached the public chrome raw');
        $this->assertStringNotContainsString('data:text/html', $json);
    }

    /**
     * The guard belongs on the MODEL, not in one controller, so that seeders,
     * DefaultSiteContent, a future import and a direct `update()` are all
     * covered by the same rule. This writes past the controller entirely.
     */
    public function test_the_theme_model_cleans_links_on_every_write_path(): void
    {
        DefaultSiteContent::ensure();

        SiteTheme::current()->update([
            'nav_links' => [
                ['label' => 'a', 'url' => 'javascript:alert(1)'],
                // Nested deeper than any validation rule names.
                ['label' => 'b', 'groups' => [['items' => [['label' => 'c', 'href' => 'vbscript:msgbox(1)']]]]],
            ],
            'footer_columns' => [['title' => 'Legal', 'links' => [['label' => 'x', 'url' => '//evil.test/phish']]]],
            'social_links' => ['x' => 'javascript:alert(2)'],
        ]);

        $stored = SiteTheme::current()->fresh();

        $this->assertNull($stored->nav_links[0]['url']);
        $this->assertNull($stored->nav_links[1]['groups'][0]['items'][0]['href']);
        $this->assertNull($stored->footer_columns[0]['links'][0]['url']);
        $this->assertSame('', $stored->social_links['x']);
        $this->assertStringNotContainsString('evil.test', json_encode($stored->publicPayload()));
    }

    /**
     * COLLATERAL. A guard that stops honest use is not a win — every link shape
     * the live chrome actually uses has to come back byte-identical through BOTH
     * passes, or the owner's header quietly empties out.
     */
    public function test_legitimate_chrome_links_survive_both_passes_byte_identical(): void
    {
        DefaultSiteContent::ensure();

        $nav = [
            ['label' => 'Pricing', 'url' => '/pricing'],
            ['label' => 'Docs', 'url' => 'https://docs.staffvora.com'],
            ['label' => 'Features', 'url' => '#features'],
            ['label' => 'Contact', 'url' => 'mailto:hello@staffvora.com'],
            ['label' => 'Call', 'url' => 'tel:+441234567890'],
            ['label' => 'Relative', 'url' => 'solutions/agencies'],
            ['label' => 'Product', 'children' => [['label' => 'Time tracking', 'url' => '/time-tracking']]],
        ];
        $footer = [['title' => 'Legal', 'links' => [
            ['label' => 'Terms', 'url' => '/terms'],
            ['label' => 'Privacy', 'url' => '/privacy'],
        ]]];
        $socials = ['x' => 'https://x.com/staffvora', 'linkedin' => 'https://www.linkedin.com/company/staffvora'];

        SiteTheme::current()->update([
            'nav_links' => $nav,
            'footer_columns' => $footer,
            'social_links' => $socials,
        ]);

        $payload = SiteTheme::current()->fresh()->publicPayload();

        $this->assertSame($nav, $payload['nav_links'], 'the write+read passes altered a legitimate header nav');
        $this->assertSame($footer, $payload['footer_columns']);
        $this->assertSame($socials, $payload['social_links']);
    }

    /** Clearing a social field is how the owner overrides a brand default. */
    public function test_a_cleared_social_link_stays_cleared_rather_than_disappearing(): void
    {
        DefaultSiteContent::ensure();

        SiteTheme::current()->update(['social_links' => ['x' => '', 'linkedin' => 'https://li.test/x']]);

        $payload = SiteTheme::current()->fresh()->publicPayload();

        $this->assertSame('', $payload['social_links']['x']);
        $this->assertSame('https://li.test/x', $payload['social_links']['linkedin']);
    }

    /**
     * THE SIBLING ENCODING, found while wiring the theme guard up.
     *
     * The scheme probe used to DELETE entities before testing. That works only
     * while the entity encodes something incidental to the payload. When the
     * entity IS the dangerous character, deleting it destroyed the evidence:
     * `javascript&colon;alert(1)` became `javascriptalert(1)` — no colon, so it
     * passed as an innocuous relative path and was STORED. Same for
     * `&#47;&#47;evil.test`, which lost the slashes that made it
     * protocol-relative. A browser resolves those entities, so anywhere the
     * value is written into HTML source rather than assigned through the DOM
     * (SSR, an email, an export) the scheme comes back to life.
     *
     * The probe now decodes, so it sees what the browser will see.
     *
     * @return array<string, array{0:string}>
     */
    public static function entityEncodedUrls(): array
    {
        return [
            'named colon' => ['javascript&colon;alert(1)'],
            'decimal colon' => ['javascript&#58;alert(1)'],
            'decimal colon no semicolon' => ['javascript&#58alert(1)'],
            'hex colon' => ['javascript&#x3a;alert(1)'],
            'entity inside the scheme' => ['java&#115;cript:alert(1)'],
            'entity first character' => ['&#106;avascript:alert(1)'],
            'tab entity' => ['java&#09;script:alert(1)'],
            'protocol relative, decimal' => ['&#47;&#47;evil.test/x'],
            'protocol relative, hex' => ['&#x2f;&#x2f;evil.test/x'],
            'protocol relative, named' => ['&sol;&sol;evil.test/x'],
        ];
    }

    #[DataProvider('entityEncodedUrls')]
    public function test_an_entity_encoded_scheme_cannot_get_past_the_url_guard(string $url): void
    {
        $this->assertNull(HtmlSanitizer::safeLink($url), 'the scheme probe was fooled by an entity');
    }

    #[DataProvider('entityEncodedUrls')]
    public function test_an_entity_encoded_scheme_cannot_be_stored_in_the_site_chrome(string $url): void
    {
        DefaultSiteContent::ensure();

        SiteTheme::current()->update([
            'nav_links' => [['label' => 'a', 'url' => $url]],
            'social_links' => ['x' => $url],
        ]);

        $payload = SiteTheme::current()->fresh()->publicPayload();

        $this->assertNull($payload['nav_links'][0]['url']);
        $this->assertSame('', $payload['social_links']['x']);
    }

    /** …and decoding must not start rejecting URLs that merely contain entities. */
    public function test_entity_decoding_does_not_reject_ordinary_urls(): void
    {
        foreach (['https://x.test/?a=1&amp;b=2', 'search?q=a&colon;b', '/pricing', '#features'] as $url) {
            $this->assertSame($url, HtmlSanitizer::safeLink($url), "a legitimate URL was rejected: $url");
        }
    }

    /** The browser-side copy of the rule has to decode too, or the two diverge. */
    public function test_the_client_side_url_guard_decodes_entities_as_well(): void
    {
        $header = $this->jsx('SiteHeader.jsx');

        $this->assertStringContainsString('function decodeEntities', $header);
        $this->assertStringNotContainsString("replace(/&#x?[0-9a-f]+;?/gi, '')", $header,
            'the client guard still deletes entities instead of decoding them');
    }

    /**
     * RENDER SIDE. The server passes may be right and still not save us: the
     * chrome renders whatever the payload holds, including a payload cached
     * before this fix, and React 18 does not block a `javascript:` href on its
     * own. SiteHeader/SiteFooter had no check at all.
     */
    public function test_the_site_chrome_scheme_checks_every_href_it_emits(): void
    {
        $header = $this->jsx('SiteHeader.jsx');
        $footer = $this->jsx('SiteFooter.jsx');

        $this->assertStringContainsString('export function safeHref', $header,
            'SiteHeader emits nav hrefs with no URL check.');
        $this->assertStringContainsString("'http', 'https', 'mailto', 'tel'", $header,
            'SiteHeader has no scheme allow-list.');

        // Nothing may reach an href without going through it.
        $this->assertStringNotContainsString('<a href={url}', $header);
        $this->assertStringNotContainsString('<Link href={url}', $header);
        $this->assertStringNotContainsString('<a href={url}', $footer);
        $this->assertStringNotContainsString('<Link href={url}', $footer);
        $this->assertStringNotContainsString('href={socials[s.key]}', $footer,
            'the footer social icons still emit the stored URL unchecked');

        $this->assertStringContainsString('safeHref', $footer, 'SiteFooter emits hrefs with no URL check.');
    }

    // ================================================================
    // 2. JSON-LD injected into a <script> block.
    // ================================================================

    /**
     * `JSON.stringify` escapes quotes and backslashes; it escapes neither `<`
     * nor `/`. So an operator-authored FAQ answer containing `</script>` lands
     * verbatim inside `<script type="application/ld+json">`.
     *
     * It does not execute today — assigning .innerHTML on a <script> parses the
     * value in the script-data tokenizer state with no start tag emitted, so
     * `</script>` is not an "appropriate end tag" and no element is created.
     * That accident disappears the moment the page is server-rendered, because
     * the same bytes are then written into the HTML SOURCE. Escaping for the
     * script context costs four replacements; discovering it after SSR ships
     * costs a stored XSS on every marketing page.
     */
    public function test_site_shell_escapes_the_json_ld_for_the_script_context(): void
    {
        $shell = $this->jsx('SiteShell.jsx');

        $this->assertStringNotContainsString(
            '__html: JSON.stringify(jsonLd)',
            $shell,
            'The JSON-LD graph is still injected with a bare JSON.stringify.'
        );

        foreach (['u003c', 'u003e', 'u0026', 'u002f'] as $escape) {
            $this->assertStringContainsString($escape, $shell,
                "The JSON-LD serialiser does not escape \\$escape for the script context.");
        }
    }

    /**
     * …and the escaping has to be LOSSLESS: `<`, `>`, `&` and `/` never appear
     * in JSON outside a string literal, and `\uXXXX` is valid JSON inside one,
     * so a consumer (Google's parser) must read back the identical graph. This
     * mirrors SiteShell's jsonLdText() in PHP and round-trips it.
     */
    public function test_the_json_ld_escaping_round_trips_to_the_same_graph(): void
    {
        $graph = [[
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [[
                '@type' => 'Question',
                'name' => '</script><img src=x onerror=alert(1)>',
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Q&A: 5 < 6 & 7 > 6'],
            ]],
        ]];

        $encoded = str_replace(
            ['&', '<', '>', '/'],
            ['\\u0026', '\\u003c', '\\u003e', '\\u002f'],
            json_encode($graph, JSON_UNESCAPED_SLASHES)
        );

        $this->assertStringNotContainsString('</script>', $encoded);
        $this->assertStringNotContainsString('<', $encoded);
        $this->assertSame($graph, json_decode($encoded, true), 'the escaping changed the graph');
    }

    // ================================================================
    // 3. The dead regex that ate honest copy.
    // ================================================================

    /**
     * COLLATERAL FIX. Platform\BlogController::sanitize() ran
     * `preg_replace('#javascript:#i', '', $body)` before the model's real
     * sanitiser saw it — a blind substring delete across the whole document. A
     * security post or a how-to that names the scheme lost the word, silently,
     * in the operator's own saved draft.
     */
    public function test_a_post_may_write_about_the_javascript_scheme(): void
    {
        $body = '<p>Never put a <code>javascript:</code> URL in a link.</p>'
            .'<p>The <code>onerror=</code> attribute is the other classic.</p>';

        $this->actingAs($this->operator('blog@platform.test'), 'platform')
            ->post(route('platform.blog.store'), [
                'title' => 'URL schemes', 'slug' => 'url-schemes', 'body' => $body, 'publish' => true,
            ])->assertSessionHasNoErrors();

        $post = BlogPost::where('slug', 'url-schemes')->firstOrFail();

        $this->assertStringContainsString('javascript:', $post->body, 'the regex pass still eats legitimate copy');
        $this->assertStringContainsString('onerror=', $post->body);
        $this->assertStringContainsString('javascript:', $post->publicPayload()['body']);
    }

    /** …while the actual attack in the same field is still killed by the parser. */
    public function test_removing_the_regex_did_not_weaken_the_blog_body(): void
    {
        $this->actingAs($this->operator('blogxss@platform.test'), 'platform')
            ->post(route('platform.blog.store'), [
                'title' => 'Attack', 'slug' => 'attack-body', 'publish' => true,
                'body' => '<p>ok</p><script>alert(1)</script><a href="javascript:alert(2)">x</a>'
                    .'<img src=x onerror=alert(3)><scr<script>ipt>alert(4)</script>',
            ])->assertSessionHasNoErrors();

        $body = BlogPost::where('slug', 'attack-body')->firstOrFail()->publicPayload()['body'];

        $this->assertStringContainsString('<p>ok</p>', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringNotContainsString('href="javascript:', $body);
    }

    // ================================================================
    // 4. Scale — the anonymous read path and the save that feeds it.
    // ================================================================

    /**
     * The cache used to hold the RAW model, so the six-hour entry saved one
     * indexed SELECT while the expensive half — a DOM parse of every block —
     * still ran on every anonymous request. The sanitised payload has to be in
     * the cache entry, not rebuilt from it.
     */
    public function test_the_published_read_path_caches_the_sanitised_payload(): void
    {
        DefaultSiteContent::ensure();
        SitePage::where('slug', 'home')->firstOrFail()->publish();

        $payload = SitePage::published('home')->publicPayload();

        $cached = Cache::get('site_page.home');
        $this->assertInstanceOf(SitePage::class, $cached, 'the home page is no longer cached at all');

        $property = new \ReflectionProperty(SitePage::class, 'preparedPayload');
        $property->setAccessible(true);

        $this->assertIsArray(
            $property->getValue($cached),
            'the cache entry carries the raw model only — the sanitiser still runs per request'
        );
        $this->assertSame($payload, $property->getValue($cached));
    }

    /**
     * PRODUCTION-DRIVER GUARD. The suite runs on the `array` cache store, which
     * hands the very same object back and would hide a payload that does not
     * survive serialisation. Production runs the FILE driver, which serialises.
     * If the memo were ever moved onto something serialize() drops, the fix
     * would quietly evaporate on the live site and every test here would still
     * be green — so assert the round trip directly.
     */
    public function test_the_cached_payload_survives_a_serialising_cache_driver(): void
    {
        DefaultSiteContent::ensure();
        SitePage::where('slug', 'home')->firstOrFail()->publish();

        $payload = SitePage::published('home')->publicPayload();
        $roundTripped = unserialize(serialize(Cache::get('site_page.home')));

        $property = new \ReflectionProperty(SitePage::class, 'preparedPayload');
        $property->setAccessible(true);

        $this->assertSame(
            $payload,
            $property->getValue($roundTripped),
            'the sanitised payload does not survive a serialising cache store — production would still re-sanitise'
        );
        $this->assertSame($payload, $roundTripped->publicPayload());
    }

    /**
     * The measured shape of the amplifier: a big page cost 0.6–3 ms realistic,
     * 60 ms at 200 blocks and 2,280 ms at 40 maximum-size blocks — of CPU, per
     * anonymous visitor, on a VPS that co-hosts other live businesses. A warm
     * view must not pay it again.
     *
     * Inserted straight into the table because that is the case that matters:
     * the new save caps stop a page like this being CREATED, they do nothing
     * about the ones already stored.
     */
    public function test_a_warm_view_of_a_heavy_page_does_not_re_run_the_sanitiser(): void
    {
        $unit = '<p>Lorem <strong>ipsum</strong> dolor sit <em>amet</em>.</p><div><span>consectetur</span></div>';
        $blocks = [];
        for ($i = 0; $i < 40; $i++) {
            $blocks[] = ['id' => 'b'.$i, 'type' => 'rich_text', 'data' => ['html' => str_repeat($unit, 200)]];
        }

        DB::table('site_pages')->insert([
            'slug' => 'heavy', 'title' => 'Heavy', 'status' => 'published',
            'blocks' => json_encode($blocks), 'published_blocks' => json_encode($blocks),
            'sort' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $cold = microtime(true);
        $first = SitePage::published('heavy')->publicPayload();
        $cold = microtime(true) - $cold;

        $warm = microtime(true);
        $second = SitePage::published('heavy')->publicPayload();
        $warm = microtime(true) - $warm;

        $this->assertSame($first, $second, 'the cached payload differs from the freshly built one');
        $this->assertGreaterThan(0.005, $cold, 'the corpus is too small for this test to mean anything');
        $this->assertLessThan(
            $cold / 10,
            $warm,
            sprintf('a warm view still re-sanitises: cold %.1f ms vs warm %.1f ms', $cold * 1000, $warm * 1000)
        );
    }

    /** Publishing must still replace what the public sees — the memo cannot go stale. */
    public function test_publishing_still_changes_what_the_public_page_serves(): void
    {
        DefaultSiteContent::ensure();
        $page = SitePage::where('slug', 'home')->firstOrFail();
        $operator = $this->operator('publish@platform.test');

        $this->actingAs($operator, 'platform')->put(route('platform.site.save', $page), [
            'title' => 'Home',
            'blocks' => [['id' => 'b1', 'type' => 'rich_text', 'data' => ['html' => '<p>First copy.</p>']]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($operator, 'platform')->post(route('platform.site.publish', $page));

        $this->assertStringContainsString('First copy.', json_encode(SitePage::published('home')->publicPayload()));

        $this->actingAs($operator, 'platform')->put(route('platform.site.save', $page), [
            'title' => 'Home',
            'blocks' => [['id' => 'b1', 'type' => 'rich_text', 'data' => ['html' => '<p>Second copy.</p>']]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($operator, 'platform')->post(route('platform.site.publish', $page));

        $live = json_encode(SitePage::published('home')->publicPayload());

        $this->assertStringContainsString('Second copy.', $live);
        $this->assertStringNotContainsString('First copy.', $live);
    }

    // ---- the save-side ceilings -------------------------------------

    public function test_the_builder_refuses_an_absurd_number_of_blocks(): void
    {
        DefaultSiteContent::ensure();
        $page = SitePage::where('slug', 'home')->firstOrFail();
        $before = $page->blocks;

        $blocks = [];
        for ($i = 0; $i < 101; $i++) {
            $blocks[] = ['id' => 'b'.$i, 'type' => 'rich_text', 'data' => ['html' => '<p>x</p>']];
        }

        $this->actingAs($this->operator('count@platform.test'), 'platform')
            ->put(route('platform.site.save', $page), ['title' => 'Home', 'blocks' => $blocks])
            ->assertSessionHasErrors('blocks');

        $this->assertSame($before, $page->fresh()->blocks, 'a rejected save must not have written anything');
    }

    /**
     * A count cap alone is not a budget: HtmlSanitizer allows 512 KB in a single
     * `html` string, so a handful of blocks under any sane count limit is still
     * megabytes of DOM parsing.
     */
    public function test_the_builder_refuses_a_page_over_the_total_size_budget(): void
    {
        DefaultSiteContent::ensure();
        $page = SitePage::where('slug', 'home')->firstOrFail();
        $before = $page->blocks;

        $blocks = [];
        for ($i = 0; $i < 4; $i++) {
            $blocks[] = ['id' => 'b'.$i, 'type' => 'rich_text', 'data' => ['html' => str_repeat('<p>abcdefghij</p>', 20000)]];
        }

        $this->actingAs($this->operator('bytes@platform.test'), 'platform')
            ->put(route('platform.site.save', $page), ['title' => 'Home', 'blocks' => $blocks])
            ->assertSessionHasErrors('blocks');

        $this->assertSame($before, $page->fresh()->blocks);
    }

    /**
     * COLLATERAL. The caps have to be invisible to the people who actually use
     * the builder: every page the site ships, saved through the real route,
     * with room to spare.
     */
    public function test_every_real_marketing_page_still_saves(): void
    {
        DefaultSiteContent::ensure();
        $operator = $this->operator('real@platform.test');

        $pages = SitePage::all();
        $this->assertGreaterThan(5, $pages->count(), 'the corpus is missing — this test would be vacuous');

        foreach ($pages as $page) {
            $blocks = $page->blocks ?? [];

            $this->assertLessThanOrEqual(100, count($blocks), "page [{$page->slug}] is over the block cap");
            $this->assertLessThanOrEqual(512_000, strlen((string) json_encode($blocks)), "page [{$page->slug}] is over the size cap");

            $this->actingAs($operator, 'platform')
                ->put(route('platform.site.save', $page), [
                    'title' => $page->title ?: 'Page',
                    'blocks' => $blocks,
                ])->assertSessionHasNoErrors();
        }
    }

    private function jsx(string $name): string
    {
        $path = resource_path('js/Components/Site/'.$name);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
