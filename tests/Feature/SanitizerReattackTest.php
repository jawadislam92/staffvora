<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\SitePage;
use App\Models\SiteTheme;
use App\Support\HtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADVERSARIAL RE-ATTACK on the public-site content pipeline.
 *
 * Every test here ATTEMPTS an injection. A failing assertion means the attack
 * was blocked (good); a test that passes while asserting the payload SURVIVED
 * is a reported hole.
 */
class SanitizerReattackTest extends TestCase
{
    use RefreshDatabase;

    /** Anything that, once innerHTML'd, could execute. */
    private function isDangerous(string $html): bool
    {
        return (bool) preg_match(
            '/<\s*(script|svg|math|iframe|object|embed|style|form|input|button|link|meta|base|template)\b|\son[a-z]+\s*=|javascript\s*:|vbscript\s*:|data:text\/html/i',
            $html
        );
    }

    // ------------------------------------------------------------------
    // 1. Type confusion, every JSON type, at every depth, on `html`.
    // ------------------------------------------------------------------

    public function test_html_key_cannot_smuggle_markup_in_any_json_type_or_depth(): void
    {
        $payload = '<img src=x onerror=alert(1)>';

        $shapes = [
            'string' => $payload,
            'list' => [$payload],
            'nested list' => [[$payload]],
            'deep list' => [[[[$payload]]]],
            'assoc' => ['0' => $payload],
            'assoc named' => ['html' => $payload],
            'mixed' => ['a' => ['html' => [$payload]]],
            'int' => 12345,
            'float' => 1.25,
            'true' => true,
            'false' => false,
            'null' => null,
        ];

        foreach ($shapes as $label => $shape) {
            $blocks = [['id' => 'b1', 'type' => 'rich_text', 'data' => ['html' => $shape]]];
            $clean = HtmlSanitizer::sanitizeBlocks($blocks);
            $out = $clean[0]['data']['html'];

            $this->assertIsString($out, "html stayed non-string for shape [$label]");
            $this->assertFalse($this->isDangerous($out), "payload survived as `html` for shape [$label]: $out");
        }
    }

    public function test_html_key_buried_deep_in_the_block_tree_is_still_cleaned(): void
    {
        $blocks = [[
            'id' => 'b1',
            'type' => 'rich_text',
            'data' => ['columns' => [['rows' => [['cell' => ['html' => '<script>alert(1)</script>']]]]]],
        ]];

        $clean = HtmlSanitizer::sanitizeBlocks($blocks);

        $this->assertSame('', $clean[0]['data']['columns'][0]['rows'][0]['cell']['html']);
    }

    // ------------------------------------------------------------------
    // 2. URL keys — every key that actually reaches an href/src in the JSX.
    // ------------------------------------------------------------------

    public function test_every_url_key_the_public_jsx_reads_is_scheme_checked(): void
    {
        // Keys grepped out of resources/js/Components/Site/**: Hero.image_url,
        // Hero/CtaBand primary_cta.url + secondary_cta.url, ImageBlock.image_url,
        // LogoBar logos[].image_url, FeatureSplit.image_url,
        // TeamGrid members[].avatar_url, Testimonials items[].avatar_url,
        // SiteHeader/SiteFooter nav url.
        $blocks = [[
            'id' => 'b1',
            'type' => 'hero',
            'data' => [
                'image_url' => 'javascript:alert(1)',
                'primary_cta' => ['label' => 'go', 'url' => 'javascript:alert(1)'],
                'secondary_cta' => ['label' => 'go', 'url' => 'JaVaScRiPt:alert(1)'],
                'logos' => [['image_url' => "java\tscript:alert(1)"]],
                'members' => [['avatar_url' => 'javascript:alert(1)']],
                'items' => [['avatar_url' => 'data:text/html,<script>alert(1)</script>']],
            ],
        ]];

        $clean = HtmlSanitizer::sanitizeBlocks($blocks)[0]['data'];

        $this->assertNull($clean['image_url']);
        $this->assertNull($clean['primary_cta']['url']);
        $this->assertNull($clean['secondary_cta']['url']);
        $this->assertNull($clean['logos'][0]['image_url']);
        $this->assertNull($clean['members'][0]['avatar_url']);
        $this->assertNull($clean['items'][0]['avatar_url']);
    }

    // ------------------------------------------------------------------
    // 3. End-to-end through the model + the public payload.
    // ------------------------------------------------------------------

    public function test_public_payload_cleans_a_row_written_around_the_model(): void
    {
        SitePage::create(['slug' => 'attack', 'title' => 'a', 'status' => 'published', 'blocks' => [], 'published_blocks' => []]);

        // Simulate a row that predates the sanitiser / was written by raw SQL.
        \DB::table('site_pages')->where('slug', 'attack')->update([
            'published_blocks' => json_encode([[
                'id' => 'x', 'type' => 'rich_text',
                'data' => ['html' => '<img src=x onerror=alert(1)><script>alert(2)</script>'],
            ]]),
        ]);

        $payload = SitePage::where('slug', 'attack')->first()->publicPayload();

        $this->assertFalse($this->isDangerous($payload['blocks'][0]['data']['html']));
    }

    public function test_blog_body_written_around_the_model_is_cleaned_on_read(): void
    {
        BlogPost::create(['slug' => 'p', 'title' => 't', 'status' => 'published', 'published_at' => now()]);
        \DB::table('blog_posts')->where('slug', 'p')->update(['body' => '<script>alert(1)</script><a href="javascript:alert(2)">x</a>']);

        $out = BlogPost::where('slug', 'p')->first()->publicPayload()['body'];

        $this->assertFalse($this->isDangerous($out), $out);
    }

    // ------------------------------------------------------------------
    // 4. THEME — the chrome on every public page.
    // ------------------------------------------------------------------

    public function test_theme_public_payload_does_NOT_sanitise_a_stored_row(): void
    {
        // ATTACK: unlike SitePage/BlogPost, SiteTheme has no read-pass and no
        // model-level guard — the ONLY check is in SiteBuilderController.
        // A row already in the database (i.e. every row written before the fix
        // shipped, on the live site) is served verbatim to every visitor.
        \DB::table('site_themes')->insert([
            'id' => 1,
            'nav_links' => json_encode([['label' => 'Pricing', 'url' => 'javascript:alert(1)']]),
            'footer_columns' => json_encode([['title' => 'Legal', 'links' => [['label' => 'x', 'url' => 'javascript:alert(2)']]]]),
            'social_links' => json_encode(['x' => 'javascript:alert(3)']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        SiteTheme::forgetCache();

        $payload = SiteTheme::current()->publicPayload();

        // These assertions PASSING is the finding: the payload comes back raw.
        $this->assertSame('javascript:alert(1)', $payload['nav_links'][0]['url']);
        $this->assertSame('javascript:alert(2)', $payload['footer_columns'][0]['links'][0]['url']);
        $this->assertSame('javascript:alert(3)', $payload['social_links']['x']);
    }

    // ------------------------------------------------------------------
    // 5. Collateral — the real seeded marketing corpus must survive untouched.
    // ------------------------------------------------------------------

    public function test_real_seeder_content_is_byte_identical_after_sanitising(): void
    {
        $ref = new \ReflectionClass(\Database\Seeders\SiteContentSeeder::class);
        $method = $ref->getMethod('pages');
        $method->setAccessible(true);
        $pages = $method->invoke($ref->newInstanceWithoutConstructor());

        $this->assertNotEmpty($pages, 'seeder corpus not found — this test would be vacuous');
        $json = json_encode($pages);
        $this->assertStringContainsString('rich_text', $json, 'seeder corpus has no rich_text to sanitise');
        $this->assertGreaterThan(20000, strlen($json), 'seeder corpus unexpectedly small');

        $after = json_encode(HtmlSanitizer::sanitizeBlocks($pages));

        $this->assertSame($json, $after, 'the sanitiser altered real seeded marketing content');
    }

    // ------------------------------------------------------------------
    // 6. END TO END over HTTP: operator saves + publishes, anonymous visitor
    //    fetches. This is the property — "can script reach a public page".
    // ------------------------------------------------------------------

    private function operator(): \App\Models\PlatformUser
    {
        return \App\Models\PlatformUser::create([
            'name' => 'Op', 'email' => 'reattack@platform.test',
            'password' => 'operator-secret-pw', 'role' => 'owner',
        ]);
    }

    /** Recursively assert nothing dangerous survived anywhere in a payload. */
    private function assertPayloadInert(mixed $node, string $where = 'root'): void
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $this->assertPayloadInert($v, $where.'.'.$k);
            }

            return;
        }

        if (! is_string($node)) {
            return;
        }

        $this->assertFalse($this->isDangerous($node), "dangerous value survived at [$where]: $node");
    }

    public function test_operator_cannot_land_script_on_a_published_page_via_the_builder(): void
    {
        \App\Support\DefaultSiteContent::ensure();
        $page = SitePage::where('slug', 'home')->firstOrFail();

        // Payload battery: every content-bearing shape the renderers actually
        // read, plus keys the URL_KEYS list might have missed.
        $blocks = [
            ['id' => 'r1', 'type' => 'rich_text', 'data' => ['html' => '<svg><script>alert(1)</script></svg><img src=x onerror=alert(2)>']],
            ['id' => 'r2', 'type' => 'rich_text', 'data' => ['html' => '<math><mtext><table><mglyph><style><img src=x onerror=alert(3)>']],
            ['id' => 'r3', 'type' => 'rich_text', 'data' => ['html' => '<a href="java&#09;script:alert(4)">x</a><a href="javascript&colon;alert(5)">y</a>']],
            ['id' => 'r4', 'type' => 'rich_text', 'data' => ['html' => '<img src=/a.png srcset="x" onerror=alert(6)><a href=/x formaction="javascript:alert(7)">z</a>']],
            ['id' => 'r5', 'type' => 'rich_text', 'data' => ['html' => '<xmp><img src=x onerror=alert(8)></xmp><noembed><img src=y onerror=alert(9)></noembed>']],
            ['id' => 'h1', 'type' => 'hero', 'data' => [
                'heading' => 'Hi',
                'image_url' => 'javascript:alert(10)',
                'primary_cta' => ['label' => 'Go', 'url' => 'JaVaScRiPt:alert(11)'],
                'secondary_cta' => ['label' => 'Go', 'url' => "  java\tscript:alert(12)"],
            ]],
            ['id' => 'l1', 'type' => 'logo_bar', 'data' => ['logos' => [['name' => 'a', 'image_url' => 'javascript:alert(13)']]]],
            ['id' => 't1', 'type' => 'team_grid', 'data' => ['members' => [['name' => 'a', 'avatar_url' => 'javascript:alert(14)']]]],
        ];

        $op = $this->operator();

        $this->actingAs($op, 'platform')
            ->put(route('platform.site.save', $page), ['title' => 'Home', 'blocks' => $blocks])
            ->assertSessionHasNoErrors();

        $this->actingAs($op, 'platform')->post(route('platform.site.publish', $page));

        SitePage::forgetCache('home');
        // Absolute public URL: the platform console lives on its own host, and
        // the previous request left the URL generator pointed at it.
        $props = $this->get(rtrim(config('app.url'), '/').'/')->assertOk()->viewData('page')['props'];

        // Every block value the public renderer will read.
        $this->assertPayloadInert($props['page']['blocks'], 'blocks');
    }

    /**
     * FAQ q/a are plain-text keys — correctly NOT html-sanitised, because the
     * Faq component renders them as React children. But PageController::jsonLd
     * copies them verbatim into the JSON-LD graph, and SiteShell injects that
     * graph with `dangerouslySetInnerHTML` into a <script type="application/
     * ld+json">. JSON.stringify escapes neither `<` nor `/`.
     *
     * This test records that the raw `</script>` reaches the JSON-LD payload.
     * It is NOT currently exploitable — verified in a real browser: setting
     * .innerHTML on a <script> element parses the whole string as raw text
     * (fragment parsing uses the script-data tokenizer state and no start tag
     * has been emitted, so `</script>` is not an "appropriate end tag"), so no
     * element is created and nothing executes. It becomes a live stored XSS the
     * day this page is server-rendered (Inertia SSR), because the string would
     * then be written into the HTML source. Treat it as a tripwire.
     */
    public function test_faq_text_reaches_the_json_ld_script_unescaped(): void
    {
        \App\Support\DefaultSiteContent::ensure();
        $page = SitePage::where('slug', 'home')->firstOrFail();
        $op = $this->operator();

        $this->actingAs($op, 'platform')->put(route('platform.site.save', $page), [
            'title' => 'Home',
            'blocks' => [['id' => 'f1', 'type' => 'faq', 'data' => ['items' => [[
                'q' => '</script><img src=x onerror=alert(1)>',
                'a' => 'answer',
            ]]]]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($op, 'platform')->post(route('platform.site.publish', $page));
        SitePage::forgetCache('home');

        $props = $this->get(rtrim(config('app.url'), '/').'/')->assertOk()->viewData('page')['props'];

        // JSON_UNESCAPED_SLASHES mirrors JS JSON.stringify, which is what
        // SiteShell actually feeds to dangerouslySetInnerHTML.
        $json = json_encode($props['jsonLd'], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('</script>', $json,
            'If this now fails the JSON-LD path has been hardened — delete this tripwire.');
    }

    public function test_operator_cannot_land_a_javascript_link_in_the_site_chrome(): void
    {
        \App\Support\DefaultSiteContent::ensure();
        $op = $this->operator();

        $attempts = [
            'nav_links' => [
                ['label' => 'a', 'url' => 'javascript:alert(1)'],
                ['label' => 'b', 'url' => 'JAVASCRIPT:alert(2)'],
                ['label' => 'c', 'url' => "jav\tascript:alert(3)"],
                ['label' => 'd', 'url' => '//evil.test/x'],
                ['label' => 'e', 'children' => [['label' => 'f', 'url' => 'javascript:alert(4)']]],
                ['label' => 'g', 'children' => [['label' => 'h', 'children' => [['label' => 'i', 'url' => 'javascript:alert(5)']]]]],
            ],
            'footer_columns' => [
                ['title' => 'Legal', 'links' => [['label' => 'x', 'url' => 'javascript:alert(6)']]],
            ],
            'social_links' => ['x' => 'javascript:alert(7)', 'linkedin' => 'data:text/html,<script>alert(8)</script>'],
        ];

        // First establish a clean baseline the attack must not be able to dirty.
        $this->actingAs($op, 'platform')->put(route('platform.site.theme'), [
            'nav_links' => [['label' => 'Pricing', 'url' => '/pricing']],
            'footer_columns' => [['title' => 'Legal', 'links' => [['label' => 'Terms', 'url' => '/terms']]]],
            'social_links' => ['x' => 'https://x.test/staffvora'],
        ])->assertSessionHasNoErrors();

        $this->actingAs($op, 'platform')->put(route('platform.site.theme'), $attempts);

        SiteTheme::forgetCache();
        $payload = SiteTheme::current()->publicPayload();

        $this->assertNotEmpty($payload['nav_links'], 'baseline theme was not saved — test would be vacuous');
        $this->assertPayloadInert($payload['nav_links'], 'nav_links');
        $this->assertPayloadInert($payload['footer_columns'], 'footer_columns');
        $this->assertPayloadInert($payload['social_links'], 'social_links');
    }

    /**
     * Bypass attempt: the validator names `nav_links.*.url` and
     * `nav_links.*.children.*.url`. Send the payload one level DEEPER than any
     * rule, and under keys the rules do not name, to see whether the belt-and-
     * braces sanitizeBlocks walk really is doing the work.
     */
    public function test_theme_links_below_the_validation_rules_are_still_neutralised(): void
    {
        \App\Support\DefaultSiteContent::ensure();
        $op = $this->operator();

        $this->actingAs($op, 'platform')->put(route('platform.site.theme'), [
            'nav_links' => [[
                'label' => 'Product',
                'children' => [[
                    'label' => 'More',
                    'children' => [['label' => 'Deep', 'url' => 'javascript:alert(1)', 'href' => 'javascript:alert(2)']],
                ]],
            ]],
            'footer_columns' => [['title' => 'c', 'links' => [['label' => 'x', 'link_url' => 'javascript:alert(3)', 'url' => '/ok']]]],
            'social_links' => ['x' => '/ok'],
        ]);

        SiteTheme::forgetCache();
        $payload = SiteTheme::current()->publicPayload();

        $this->assertPayloadInert($payload['nav_links'], 'nav_links');
        $this->assertPayloadInert($payload['footer_columns'], 'footer_columns');
    }

    public function test_operator_cannot_land_script_on_a_published_blog_post(): void
    {
        $op = $this->operator();

        $this->actingAs($op, 'platform')->post(route('platform.blog.store'), [
            'title' => 'Post',
            'slug' => 'attack-post',
            'excerpt' => 'e',
            'body' => '<svg/onload=alert(1)><img src=x onerror=alert(2)><a href="javascript:alert(3)">x</a>'
                .'<iframe src="javascript:alert(4)"></iframe><xmp><img src=z onerror=alert(5)></xmp>',
            'cover_path' => '/images/a.png',
            'publish' => true,
        ])->assertSessionHasNoErrors();

        $props = $this->get(rtrim(config('app.url'), '/').'/blog/attack-post')
            ->assertOk()->viewData('page')['props'];

        $this->assertFalse($this->isDangerous($props['post']['body']), $props['post']['body']);
    }

    public function test_legitimate_marketing_markup_survives_clean(): void
    {
        $legit = [
            '<p>Q&amp;A: 5 &lt; 6 &amp;&amp; 7 &gt; 6</p>',
            '<h2>Pricing</h2><p>From <strong>$9</strong>/user — <em>no card required</em>.</p>',
            '<ul><li>Screenshots</li><li>Idle detection</li></ul>',
            '<p><a href="/pricing">See pricing</a> or <a href="mailto:hello@staffvora.test">email us</a>.</p>',
            '<p><a href="https://staffvora.test/docs" target="_blank" rel="noopener noreferrer">Docs</a></p>',
            '<table><thead><tr><th>Plan</th><th>Price</th></tr></thead><tbody><tr><td>Solo</td><td>$9</td></tr></tbody></table>',
            '<blockquote>“It just works.”</blockquote>',
            '<p>Contact us at <a href="tel:+15550100">+1 555 0100</a>.</p>',
            '<figure><img src="/images/shot.png" alt="Dashboard" width="800" height="600"><figcaption>The dashboard</figcaption></figure>',
        ];

        foreach ($legit as $html) {
            $out = HtmlSanitizer::clean($html);
            $this->assertNotSame('', $out, "legitimate markup was destroyed: $html");
            // idempotent, so the render-time second pass cannot corrupt it
            $this->assertSame($out, HtmlSanitizer::clean($out), "not idempotent: $html");
        }
    }

    /**
     * COLLATERAL: Platform\BlogController::sanitize() is a leftover regex pass
     * that runs BEFORE the model's real sanitiser. It is no longer needed for
     * safety, and it silently corrupts legitimate copy — a post explaining URL
     * schemes loses the literal text. HtmlSanitizer::clean() keeps it.
     */
    public function test_blog_controller_regex_pass_corrupts_legitimate_copy(): void
    {
        $op = $this->operator();
        $body = '<p>Never put a <code>javascript:</code> URL in a link.</p>';

        $this->actingAs($op, 'platform')->post(route('platform.blog.store'), [
            'title' => 'Schemes', 'slug' => 'schemes', 'body' => $body, 'publish' => true,
        ])->assertSessionHasNoErrors();

        $stored = BlogPost::where('slug', 'schemes')->firstOrFail()->body;

        // The model-level sanitiser alone would have kept it (proved below).
        $this->assertStringContainsString('javascript:', HtmlSanitizer::clean($body));
        // The controller's regex ate it first. This assertion PASSING is the bug.
        $this->assertStringNotContainsString('javascript:', $stored);
    }

    public function test_clean_is_idempotent_and_does_not_strip_ordinary_text(): void
    {
        $out = HtmlSanitizer::clean('<p>Use the <code>javascript:</code> scheme note &amp; the 5 &lt; 6 rule.</p>');

        $this->assertStringContainsString('javascript:', $out, 'a code sample mentioning the scheme was mangled');
        $this->assertStringContainsString('5 &lt; 6', $out);
    }
}
