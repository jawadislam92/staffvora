<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Plan;
use App\Models\SitePage;
use App\Support\DefaultSiteContent;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * THE LAUNCH GUARD FOR THE PUBLIC MARKETING SITE.
 *
 * Staffvora shipped for weeks with six empty pages (pricing, privacy, terms,
 * security, about, download) and NOT ONE page published — a draft-only site
 * serves 404s where a customer expects a privacy policy. This test exists so
 * that can never happen again:
 *
 *   - every page a customer can reach is published, non-empty and returns 200
 *   - no page carries scaffold/placeholder copy
 *   - nothing in the footer or header nav points at an unpublished page
 *   - the site never advertises a capability we do not ship (SSO/SAML, a public
 *     API) and never claims a compliance regime nobody has assessed
 *   - the published prices still match the owner-approved catalogue
 */
class PublicSiteContentTest extends TestCase
{
    use RefreshDatabase;

    /** Every slug a visitor is expected to be able to open. */
    private const PUBLIC_SLUGS = [
        'home', 'features', 'how-it-works', 'pricing', 'solutions',
        'solutions/agencies', 'solutions/bpos', 'solutions/software-houses',
        'solutions/remote-teams', 'solutions/freelancers',
        'about', 'contact', 'security', 'download', 'privacy', 'terms',
    ];

    /** Copy that means "nobody finished this page". */
    private const PLACEHOLDER_MARKERS = [
        '[REPLACE', 'Lorem ipsum', 'TODO', 'TBD', 'Your happy customer',
        'Add a real review', 'Your name', 'Team member', 'coming soon',
    ];

    /**
     * Capabilities the product does not have, and compliance regimes nobody has
     * assessed. The security page is allowed to name them because it names them
     * to DENY them — that page is asserted separately below.
     */
    private const FORBIDDEN_CLAIMS = [
        'SSO', 'SAML', 'single sign-on', 'GDPR', 'CCPA', 'SOC 2', 'ISO 27001', 'HIPAA',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DefaultSiteContent::ensure();
        $this->seed(SiteContentSeeder::class);
    }

    public function test_every_public_page_is_published_and_not_empty(): void
    {
        foreach (self::PUBLIC_SLUGS as $slug) {
            $page = SitePage::where('slug', $slug)->first();

            $this->assertNotNull($page, "The '{$slug}' page does not exist.");
            $this->assertSame('published', $page->status, "The '{$slug}' page is not published.");
            $this->assertNotEmpty($page->published_blocks, "The '{$slug}' page has no published blocks.");
            $this->assertNotEmpty($page->meta_description, "The '{$slug}' page has no meta description.");
        }
    }

    public function test_every_public_page_renders(): void
    {
        foreach (self::PUBLIC_SLUGS as $slug) {
            $url = $slug === 'home' ? '/' : '/'.$slug;
            $this->get($url)->assertOk();
        }
    }

    public function test_no_public_page_ships_placeholder_copy(): void
    {
        foreach (self::PUBLIC_SLUGS as $slug) {
            $json = $this->liveJson($slug);

            foreach (self::PLACEHOLDER_MARKERS as $marker) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $marker,
                    $json,
                    "The '{$slug}' page still contains placeholder copy: {$marker}",
                );
            }
        }
    }

    public function test_no_public_page_advertises_a_capability_we_do_not_ship(): void
    {
        foreach (self::PUBLIC_SLUGS as $slug) {
            if ($slug === 'security') {
                continue; // asserted below — it names these terms only to deny them
            }

            $json = $this->liveJson($slug);

            foreach (self::FORBIDDEN_CLAIMS as $claim) {
                $this->assertStringNotContainsString(
                    $claim,
                    $json,
                    "The '{$slug}' page mentions '{$claim}', which the product does not have or has not been assessed for.",
                );
            }
        }
    }

    public function test_the_security_page_denies_certification_rather_than_implying_it(): void
    {
        $json = $this->liveJson('security');

        $this->assertStringContainsString('have not been audited', $json);
        $this->assertStringContainsString('do not claim compliance', $json);
        $this->assertStringContainsString('no single sign-on', $json);
        $this->assertStringContainsString('no public API', $json);
    }

    public function test_the_legal_pages_are_substantial_and_dated(): void
    {
        foreach (['privacy', 'terms'] as $slug) {
            $json = $this->liveJson($slug);

            $this->assertGreaterThan(3000, strlen($json), "The '{$slug}' page is too thin to be a real policy.");
            $this->assertStringContainsString('Last updated', $json);
            $this->assertStringContainsString('hello@staffvora.com', $json);
        }

        // The specifics that make an employee-monitoring privacy policy honest.
        $privacy = $this->liveJson('privacy');
        foreach (['screenshot', 'activity', 'retention', 'delete', 'employer'] as $topic) {
            $this->assertStringContainsStringIgnoringCase($topic, $privacy, "The privacy policy never mentions '{$topic}'.");
        }
    }

    public function test_navigation_never_points_at_an_unpublished_page(): void
    {
        $published = SitePage::where('status', 'published')->pluck('slug')->all();
        $missing = [];

        $walk = function (array $items) use (&$walk, $published, &$missing) {
            foreach ($items as $item) {
                if (isset($item['page']) && ! in_array($item['page'], $published, true)) {
                    $missing[] = $item['page'];
                }
                foreach (['children', 'links'] as $key) {
                    if (! empty($item[$key])) {
                        $walk($item[$key]);
                    }
                }
            }
        };

        $walk(config('navigation.header', []));
        $walk(config('navigation.footer', []));

        $this->assertSame([], array_values(array_unique($missing)), 'Navigation references pages that are not published.');
    }

    public function test_the_pricing_page_carries_the_approved_catalogue(): void
    {
        $blocks = SitePage::where('slug', 'pricing')->firstOrFail()->published_blocks;
        $table = collect($blocks)->firstWhere('type', 'pricing_table');

        $this->assertNotNull($table, 'The pricing page has no pricing table.');

        // Approved local (PKR) prices, and the Agency toolkit add-on.
        $this->assertSame(
            ['free' => 0, 'starter' => 900, 'pro' => 1600, 'enterprise' => 3200],
            $table['data']['pkr'],
        );
        $this->assertSame('Agency toolkit', $table['data']['addon']['name']);
        $this->assertSame(4, $table['data']['addon']['price_usd']);
        $this->assertSame(40, $table['data']['addon']['price_usd_yearly']);
        $this->assertSame(700, $table['data']['addon']['price_pkr']);

        // The USD prices and seat caps the block renders come from the live
        // catalogue, so they are asserted at the source.
        Plan::ensureSeeded();
        $plans = Plan::whereIn('key', ['free', 'starter', 'pro', 'enterprise'])->get()->keyBy('key');
        $this->assertSame([0, 500, 900, 1800], [
            (int) $plans['free']->price_cents,
            (int) $plans['starter']->price_cents,
            (int) $plans['pro']->price_cents,
            (int) $plans['enterprise']->price_cents,
        ]);
        $this->assertSame([2, 10, 50, null], [
            $plans['free']->max_users,
            $plans['starter']->max_users,
            $plans['pro']->max_users,
            $plans['enterprise']->max_users,
        ]);
    }

    /**
     * THE ADD-ON PRICE MUST BE LIVE, NOT AUTHORED.
     *
     * The Agency toolkit price used to exist only inside the pricing page's
     * content, because BlockRenderer forwarded `plans` and nothing else. An
     * operator changing `agency_toolkit` in the panel therefore left the public
     * page advertising the old figure — a price a customer could reasonably hold
     * us to. The catalogue is now forwarded the same way plans are.
     */
    public function test_the_pricing_page_is_served_the_live_addon_catalogue(): void
    {
        $this->get('/pricing')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $p) {
                $toolkit = collect($p->toArray()['props']['addons'])->firstWhere('key', 'agency_toolkit');

                $this->assertNotNull($toolkit, 'the pricing page must be served the add-on catalogue');
                $this->assertSame(400, $toolkit['price_cents']);
                $this->assertSame(4000, $toolkit['price_cents_yearly']);
            });

        // Move the price the way an operator would, and the page must follow.
        Addon::where('key', 'agency_toolkit')->update([
            'price_cents' => 650,
            'price_cents_yearly' => 6500,
        ]);

        $this->get('/pricing')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $p) {
                $toolkit = collect($p->toArray()['props']['addons'])->firstWhere('key', 'agency_toolkit');

                $this->assertSame(650, $toolkit['price_cents'], 'the public page still quotes the old price');
                $this->assertSame(6500, $toolkit['price_cents_yearly']);
            });
    }

    /**
     * Hidden, custom-negotiated, legacy and archived add-ons are commercial
     * terms belonging to individual companies. None of them may ever reach an
     * anonymous visitor, and neither may the entitlements they grant.
     */
    public function test_only_public_unarchived_addons_reach_the_public_page(): void
    {
        foreach (['hidden', 'custom', 'legacy'] as $i => $visibility) {
            Addon::create([
                'key' => 'secret_'.$visibility,
                'name' => 'Secret '.$visibility,
                'price_cents' => 9900,
                'currency' => 'USD',
                'entitlements' => ['machine_compliance' => true],
                'visibility' => $visibility,
                'sort' => 50 + $i,
            ]);
        }

        Addon::create([
            'key' => 'retired_toolkit',
            'name' => 'Retired toolkit',
            'price_cents' => 100,
            'currency' => 'USD',
            'visibility' => 'public',
            'sort' => 60,
            'archived_at' => now(),
        ]);

        $this->get('/pricing')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $p) {
                $props = $p->toArray()['props'];
                $keys = array_column($props['addons'], 'key');

                $this->assertContains('agency_toolkit', $keys);
                foreach (['secret_hidden', 'secret_custom', 'secret_legacy', 'retired_toolkit'] as $forbidden) {
                    $this->assertNotContains($forbidden, $keys, "'{$forbidden}' must never reach a public page");
                }

                // A price list, not an access-control document.
                foreach ($props['addons'] as $addon) {
                    $this->assertArrayNotHasKey('entitlements', $addon);
                }
            });
    }

    public function test_the_pricing_page_does_not_promise_card_checkout(): void
    {
        $json = $this->liveJson('pricing');

        $this->assertStringNotContainsStringIgnoringCase('secure checkout', $json);
        $this->assertStringContainsString('bank transfer', $json);
    }

    public function test_the_sitemap_lists_every_public_page(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach (self::PUBLIC_SLUGS as $slug) {
            $needle = $slug === 'home' ? url('/').'<' : url('/'.$slug).'<';
            $this->assertStringContainsString($needle, $xml, "The sitemap is missing '{$slug}'.");
        }
    }

    public function test_re_running_the_seeder_does_not_overwrite_an_operator_edit(): void
    {
        $page = SitePage::where('slug', 'about')->firstOrFail();
        $edited = [['id' => 'operator-1', 'type' => 'rich_text', 'data' => ['html' => '<p>Our own words.</p>']]];
        $page->update(['blocks' => $edited, 'published_blocks' => $edited]);

        $this->seed(SiteContentSeeder::class);

        $this->assertSame($edited, $page->fresh()->published_blocks);
    }

    /** The live (published) content of a page as a JSON string, for copy assertions. */
    private function liveJson(string $slug): string
    {
        $page = SitePage::where('slug', $slug)->firstOrFail();

        return (string) json_encode($page->published_blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
