<?php

namespace Tests\Feature;

use App\Models\SitePage;
use App\Support\DefaultSiteContent;
use App\Support\SiteNavigation;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REGRESSION GUARD for the public marketing site's navigation, contact form,
 * share metadata and copy fixes (audit: public-marketing-site.md).
 *
 * The header dropdown was a dead control on touch and to the keyboard; the
 * contact form had no accessible labels; two pages were orphaned; the <title>
 * doubled the brand; the theme persisted under the old product name; and several
 * copy bugs contradicted the rest of the site. Each was verified failing against
 * the code before this round.
 */
class PublicSiteNavFormAndMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DefaultSiteContent::ensure();
        $this->seed(SiteContentSeeder::class);
    }

    // ---- B3/B4: the two orphan pages are now reachable from the nav ----------

    public function test_how_it_works_is_linked_from_the_header_nav(): void
    {
        $header = SiteNavigation::resolve()['header'];
        $urls = array_column($header, 'url');

        $this->assertContains('/how-it-works', $urls, '/how-it-works has no inbound header link — it is an orphan again.');
    }

    public function test_the_solutions_parent_carries_its_own_navigable_url(): void
    {
        $header = SiteNavigation::resolve()['header'];
        $solutions = collect($header)->firstWhere('label', 'Solutions');

        $this->assertNotNull($solutions, 'the Solutions dropdown vanished from the header');
        $this->assertSame('/solutions', $solutions['url'], 'the Solutions parent url is discarded, so /solutions is unreachable');
        $this->assertNotEmpty($solutions['children']);
    }

    public function test_the_header_dropdown_has_real_open_state_and_aria(): void
    {
        $header = $this->jsx('SiteHeader.jsx');

        $this->assertStringContainsString('aria-haspopup="true"', $header, 'the dropdown trigger declares no popup');
        $this->assertStringContainsString('aria-expanded={open}', $header, 'the dropdown never exposes its open state');
        $this->assertStringContainsString("=== 'Escape'", $header, 'Escape does not close the dropdown');
        $this->assertStringContainsString('group-focus-within:visible', $header, 'the panel never opens for keyboard focus');
        // The old dead trigger was a <button> with no handler; the panel now has a click toggle.
        $this->assertStringContainsString('setOpen', $header);
    }

    // ---- B2: the contact form is now accessible ------------------------------

    public function test_the_contact_form_associates_labels_and_wires_errors(): void
    {
        $contact = $this->jsx('blocks/ContactBlock.jsx');

        $this->assertStringContainsString('useId', $contact, 'fields have no generated id to associate a label with');
        $this->assertStringContainsString('htmlFor={id}', $contact, 'the label is not programmatically associated');
        $this->assertStringContainsString('autoComplete', $contact, 'no autocomplete — mobile autofill is blocked');
        $this->assertStringContainsString("aria-invalid", $contact);
        $this->assertStringContainsString('aria-describedby', $contact, 'errors are not associated with their field');
        $this->assertStringContainsString('role="alert"', $contact, 'errors are not announced');
        $this->assertStringNotContainsString('text-rose-500', $contact, 'errors still use a raw palette colour, not the danger token');
    }

    // ---- W1: the <title> no longer doubles the brand -------------------------

    public function test_the_title_callback_is_idempotent(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.jsx'));

        $this->assertStringContainsString('includes(brandName)', $app, 'the title callback still appends the brand unconditionally');
    }

    // ---- W2: share metadata no longer claims a large image it lacks ----------

    public function test_twitter_card_is_conditional_on_a_real_image(): void
    {
        $shell = $this->jsx('SiteShell.jsx');

        $this->assertStringContainsString("meta.image ? 'summary_large_image' : 'summary'", $shell,
            'twitter:card unconditionally claims summary_large_image while no page sets an image');
    }

    // ---- W4: the theme is persisted under the current product name -----------

    public function test_the_theme_storage_key_is_renamed_with_a_migration(): void
    {
        $shell = $this->jsx('SiteShell.jsx');

        $this->assertStringContainsString("STORAGE_KEY = 'staffvora-site-theme'", $shell, 'the theme key still uses the old product name');
        $this->assertStringContainsString("LEGACY_STORAGE_KEY = 'tracksuite-site-theme'", $shell, 'no migration of the old key — returning visitors lose their choice');
    }

    // ---- W3: British spelling site-wide --------------------------------------

    public function test_the_nav_uses_british_call_centres(): void
    {
        $labels = [];
        $walk = function (array $items) use (&$walk, &$labels) {
            foreach ($items as $item) {
                if (isset($item['label'])) {
                    $labels[] = $item['label'];
                }
                foreach (['children', 'links'] as $k) {
                    if (! empty($item[$k])) {
                        $walk($item[$k]);
                    }
                }
            }
        };
        $walk(config('navigation.header', []));
        $walk(config('navigation.footer', []));

        $this->assertContains('BPOs & Call Centres', $labels);
        $this->assertNotContains('BPOs & Call Centers', $labels, 'the nav still mixes American spelling with the British body copy');
    }

    // ---- W5/W7/W8: the copy corrections in the live content ------------------

    public function test_the_download_promise_has_a_subject(): void
    {
        $json = $this->liveJson('download');

        $this->assertStringContainsString('We will never ask anyone to disable their security software', $json);
        $this->assertStringNotContainsString('>Never ask anyone to disable', $json);
    }

    public function test_the_about_page_matches_the_one_product_model(): void
    {
        $json = $this->liveJson('about');

        $this->assertStringContainsString('One product', $json);
        $this->assertStringNotContainsString('Two editions', $json, '/about still implies two SKUs, contradicting the pricing page');
    }

    public function test_the_home_growth_stat_names_the_plan(): void
    {
        $json = $this->liveJson('home');

        $this->assertStringContainsString('of the Growth plan on every new workspace', $json);
        $this->assertStringNotContainsString('of Growth on every new workspace', $json);
    }

    // ---- W6: the hardcoded keylogging mock is gone ---------------------------

    public function test_feature_split_no_longer_hardcodes_the_screenshots_mock(): void
    {
        $split = $this->jsx('blocks/FeatureSplit.jsx');

        $this->assertStringNotContainsString('No keylogging, ever', $split, 'the hardcoded keylogging card still renders on image-less blocks');
        $this->assertStringNotContainsString('Activity &amp; screenshots', $split);
    }

    // ---- U6/U9/W9: token + a11y polish on the owned blocks -------------------

    public function test_image_block_and_rich_text_use_tokens_and_style_tables(): void
    {
        $image = $this->jsx('blocks/ImageBlock.jsx');
        $this->assertStringNotContainsString('zinc-', $image, 'ImageBlock still hardcodes zinc greys');

        $rich = $this->jsx('blocks/RichText.jsx');
        $this->assertStringContainsString('[&_th]:text-left', $rich, 'operator tables still render with centred <th>');
        $this->assertStringContainsString('overflow-x-auto', $rich, 'a wide table has no horizontal scroll container');
    }

    public function test_pricing_toggles_expose_pressed_state(): void
    {
        $pricing = $this->jsx('blocks/PricingTable.jsx');

        $this->assertStringContainsString('aria-pressed={annual === val}', $pricing);
        $this->assertStringContainsString('aria-pressed={cur === c}', $pricing);
        $this->assertStringContainsString('aria-label="Billing period"', $pricing);
        $this->assertStringContainsString('aria-label="Currency"', $pricing);
    }

    private function jsx(string $name): string
    {
        $path = resource_path('js/Components/Site/'.$name);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function liveJson(string $slug): string
    {
        $page = SitePage::where('slug', $slug)->firstOrFail();

        return (string) json_encode($page->published_blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
