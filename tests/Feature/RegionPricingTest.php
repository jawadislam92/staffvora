<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Models\PricingRegion;
use App\Models\PricingRegionPlan;
use App\Services\RegionPricing;
use App\Services\TenantProvisioner;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * REGION-BASED PRICING RULES.
 *
 * Three things are under test, in order of how much they matter:
 *
 *  1. MONEY — the price a customer is quoted is re-derived server-side from the
 *     plan row and the region row. Blank ≠ 0. A request can never name a price.
 *  2. ACCESS — a region may only narrow / re-price the PUBLIC catalogue. It can
 *     never grant an entitlement and can never surface an unpublished plan, so
 *     the attacker-controlled country claim is worth nothing.
 *  3. DETERMINISM + FAIL-OPEN — the same country always resolves to the same
 *     rule, and nothing can ever leave a visitor with no plans at all.
 */
class RegionPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The operator page is not in the committed Vite manifest until the next
        // `npm run build`; a render under test is about the CONTROLLER.
        $this->withoutVite();
        Entitlements::flushMemo();
        RegionPricing::flushMemo();
        Plan::ensureSeeded();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function operator(string $role = 'admin', ?string $email = null): PlatformUser
    {
        return PlatformUser::create([
            'name' => ucfirst($role),
            'email' => $email ?: $role.'@platform.test',
            'password' => 'operator-secret-pw',
            'role' => $role,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function region(array $attributes = []): PricingRegion
    {
        return PricingRegion::create(array_merge([
            'key' => 'pk_test',
            'name' => 'Pakistan test',
            'country_codes' => ['PK'],
            'currency' => 'PKR',
            'offer_mode' => 'all_except',
            'priority' => 10,
            'is_active' => true,
        ], $attributes));
    }

    private function rule(PricingRegion $region, string $planKey, array $values = []): PricingRegionPlan
    {
        return PricingRegionPlan::create(array_merge([
            'pricing_region_id' => $region->id,
            'plan_id' => Plan::where('key', $planKey)->value('id'),
            'is_offered' => true,
        ], $values));
    }

    private function entry(array $offer, string $planKey): ?array
    {
        foreach ($offer['plans'] as $plan) {
            if ($plan['key'] === $planKey) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * The operator routes belong in `routes/web.php`, which this work does not
     * own. Until they are registered there, register EXACTLY the lines we are
     * asking for — same host, same guard, same `platform.role:admin` gate — so
     * the controller and its role gate are genuinely under test now, and the
     * real registration is under test the moment it lands.
     *
     * Keep this in lock-step with the route lines in the handoff notes.
     */
    private function ensureRoutes(): void
    {
        if (Route::has('platform.regions')) {
            return;
        }

        // ORDER MATTERS, and this is the whole point of the exercise: the
        // marketing `/{slug}` catch-all at the END of routes/web.php matches one
        // or two lowercase segments on ANY host, so a GET registered after it is
        // swallowed and 404s. These routes must sit INSIDE the existing platform
        // group, well above it. Here we reproduce that by building a fresh
        // collection with ours FIRST and the app's routes after.
        $original = Route::getRoutes();

        $rebuilt = new \Illuminate\Routing\RouteCollection();
        Route::setRoutes($rebuilt);

        Route::domain(config('platform.host'))
            ->middleware(['web', 'auth:platform', \App\Http\Middleware\SharePlatformUser::class, 'platform.role:admin'])
            ->group(function () {
                $c = \App\Http\Controllers\Platform\RegionController::class;

                Route::get('/regions', [$c, 'index'])->name('platform.regions');
                // Literal path — must stay ABOVE any `/regions/{pricingRegion}`.
                Route::get('/regions/resolve', [$c, 'resolve'])->name('platform.regions.resolve');
                Route::post('/regions', [$c, 'store'])->name('platform.regions.store');
                Route::put('/regions/{pricingRegion}', [$c, 'update'])->name('platform.regions.update');
                Route::post('/regions/{pricingRegion}/archive', [$c, 'archive'])->name('platform.regions.archive');
                Route::post('/regions/{pricingRegion}/restore', [$c, 'restore'])->name('platform.regions.restore');
            });

        foreach ($original as $route) {
            $rebuilt->add($route);
        }

        // The framework refreshes these once, after booting; routes added later
        // have to ask for it themselves or route() cannot find them.
        $rebuilt->refreshNameLookups();
        $rebuilt->refreshActionLookups();

        Route::setRoutes($rebuilt);
        app('url')->setRoutes($rebuilt);
    }

    // -----------------------------------------------------------------------
    // 1. Money — server-side price derivation
    // -----------------------------------------------------------------------

    public function test_a_region_price_override_replaces_the_catalogue_price(): void
    {
        // The migration ships a live PK region, so start from a clean slate to
        // test the mechanism rather than the seed.
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $region = $this->region();
        $this->rule($region, 'starter', ['price_cents' => 90000, 'price_cents_yearly' => 900000]);

        $offer = RegionPricing::offer('PK');
        $starter = $this->entry($offer, 'starter');

        $this->assertSame(90000, $starter['price_cents']);
        $this->assertSame(900000, $starter['price_cents_yearly']);
        $this->assertSame('PKR', $starter['currency']);
        $this->assertSame('region', $starter['price_source']);

        // A plan with NO rule keeps its own list price AND its own currency —
        // a region may never relabel a USD price as PKR.
        $growth = $this->entry($offer, 'pro');
        $this->assertSame(900, $growth['price_cents']);
        $this->assertSame('USD', $growth['currency']);
        $this->assertSame('catalogue', $growth['price_source']);
    }

    public function test_a_blank_local_price_is_not_zero(): void
    {
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $region = $this->region();
        // Offered here, but no local price stated.
        $this->rule($region, 'starter', ['price_cents' => null, 'price_cents_yearly' => null]);

        $starter = $this->entry(RegionPricing::offer('PK'), 'starter');

        $this->assertSame(500, $starter['price_cents'], 'A blank override must inherit the list price, not become free.');
        $this->assertSame('catalogue', $starter['price_source']);

        // 0 is a real decision and must survive as 0.
        PricingRegionPlan::where('pricing_region_id', $region->id)->update(['price_cents' => 0]);
        RegionPricing::bump();

        $this->assertSame(0, $this->entry(RegionPricing::offer('PK'), 'starter')['price_cents']);
    }

    public function test_checkout_quote_is_derived_from_the_region_not_the_request(): void
    {
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $region = $this->region();
        $starter = Plan::where('key', 'starter')->firstOrFail();
        $this->rule($region, 'starter', ['price_cents' => 90000, 'price_cents_yearly' => 900000]);

        $monthly = RegionPricing::quote($starter, 'PK', 'monthly');
        $yearly = RegionPricing::quote($starter, 'PK', 'yearly');

        $this->assertTrue($monthly['offered']);
        $this->assertSame(90000, $monthly['price_cents']);
        $this->assertSame(900000, $yearly['price_cents']);
        $this->assertSame('PKR', $yearly['currency']);

        // A visitor with no region gets the catalogue price for the same plan.
        $this->assertSame(500, RegionPricing::quote($starter, null)['price_cents']);
        $this->assertSame('USD', RegionPricing::quote($starter, null)['currency']);

        // An unknown interval can never be used to smuggle a cheaper number in.
        $this->assertSame(90000, RegionPricing::quote($starter, 'PK', 'weekly')['price_cents']);
    }

    // -----------------------------------------------------------------------
    // 2. Access — a region may only narrow, never grant
    // -----------------------------------------------------------------------

    public function test_a_region_can_remove_a_plan_from_sale(): void
    {
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $region = $this->region();
        $this->rule($region, 'enterprise', ['is_offered' => false]);

        $offer = RegionPricing::offer('PK');

        $this->assertNull($this->entry($offer, 'enterprise'));
        $this->assertNotNull($this->entry($offer, 'starter'));
        $this->assertFalse(RegionPricing::planIsOffered(Plan::where('key', 'enterprise')->value('id'), 'PK'));
    }

    public function test_a_region_can_never_surface_a_plan_that_is_not_public(): void
    {
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $secret = Plan::create([
            'key' => 'secret_deal', 'name' => 'Secret deal', 'sort' => 99,
            'price_cents' => 1, 'price_cents_yearly' => 10, 'currency' => 'USD',
            'max_users' => 999, 'is_public' => false, 'visibility' => 'hidden',
            'entitlements' => ['max_users' => 999, 'ai' => true],
        ]);

        $region = $this->region(['offer_mode' => 'only_listed']);
        // Even an explicit, offered rule pointing at a non-public plan must not
        // put it on sale — the base catalogue is Plan::visible(), full stop.
        PricingRegionPlan::create([
            'pricing_region_id' => $region->id,
            'plan_id' => $secret->id,
            'is_offered' => true,
            'price_cents' => 1,
        ]);

        $offer = RegionPricing::offer('PK');

        $this->assertNull($this->entry($offer, 'secret_deal'));
        $this->assertFalse(RegionPricing::planIsOffered($secret, 'PK'));
        // …and because that left the region offering nothing, it failed open.
        $this->assertTrue($offer['fell_back']);
    }

    public function test_a_region_never_grants_an_entitlement(): void
    {
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $region = $this->region();
        $this->rule($region, 'free', ['price_cents' => 0]);

        // The region table has no entitlements column, and could not have one.
        $this->assertNotContains(
            'entitlements',
            array_keys($region->getAttributes()),
            'A region rule must never carry entitlements — that would be a second access path.'
        );

        $free = Plan::where('key', 'free')->firstOrFail();

        $paid = app(TenantProvisioner::class)
            ->provision('Nowhere Co', 'Owner', 'nowhere@region.test', 'super-secret-pw', country: 'ZZ')['organization'];
        $local = app(TenantProvisioner::class)
            ->provision('Local Co', 'Owner', 'local@region.test', 'super-secret-pw', country: 'PK')['organization'];

        // Whatever region they claim, capability comes from the plan alone.
        $this->assertSame($paid->can('ai'), $local->can('ai'));
        $this->assertSame($paid->entitlement('max_users'), $local->entitlement('max_users'));
        $this->assertFalse($free->entitlement('ai'));
    }

    public function test_assert_plan_offered_blocks_a_plan_the_region_does_not_sell(): void
    {
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $region = $this->region();
        $this->rule($region, 'enterprise', ['is_offered' => false]);

        $enterprise = Plan::where('key', 'enterprise')->firstOrFail();

        $this->expectException(ValidationException::class);
        RegionPricing::assertPlanOffered($enterprise, 'PK');
    }

    // -----------------------------------------------------------------------
    // 3. Determinism, normalisation and the documented fail-open default
    // -----------------------------------------------------------------------

    public function test_country_normalisation_drops_anything_that_is_not_alpha_2(): void
    {
        $this->assertSame('PK', RegionPricing::normalizeCountry('pk'));
        $this->assertSame('PK', RegionPricing::normalizeCountry(' Pk '));
        $this->assertNull(RegionPricing::normalizeCountry('PAK'));
        $this->assertNull(RegionPricing::normalizeCountry('P1'));
        $this->assertNull(RegionPricing::normalizeCountry(''));
        $this->assertNull(RegionPricing::normalizeCountry(null));
        $this->assertNull(RegionPricing::normalizeCountry(['PK']));
        $this->assertSame(['BD', 'PK'], RegionPricing::normalizeCountryList(['pk', 'PK', 'bd', 'nope', '']));
    }

    public function test_the_most_specific_rule_wins_deterministically(): void
    {
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $group = $this->region([
            'key' => 'south_asia', 'name' => 'South Asia',
            'country_codes' => ['PK', 'IN', 'BD'], 'currency' => 'USD', 'priority' => 50,
        ]);
        $this->rule($group, 'starter', ['price_cents' => 300]);

        $single = $this->region([
            'key' => 'pakistan_only', 'name' => 'Pakistan only',
            'country_codes' => ['PK'], 'currency' => 'PKR', 'priority' => 50,
        ]);
        $this->rule($single, 'starter', ['price_cents' => 90000]);

        // Same priority → the more specific (fewer countries) rule wins.
        $offer = RegionPricing::offer('PK');
        $this->assertSame('pakistan_only', $offer['region']['key']);
        $this->assertSame(90000, $this->entry($offer, 'starter')['price_cents']);

        // …and it is stable across calls.
        RegionPricing::flushMemo();
        $this->assertSame('pakistan_only', RegionPricing::offer('PK')['region']['key']);

        // Priority still beats specificity when the operator sets it.
        $group->forceFill(['priority' => 1])->save();
        RegionPricing::flushMemo();
        $this->assertSame('south_asia', RegionPricing::offer('PK')['region']['key']);

        // A country only the group covers still resolves to the group.
        $this->assertSame('south_asia', RegionPricing::offer('IN')['region']['key']);
    }

    public function test_nothing_matching_fails_open_to_the_public_catalogue(): void
    {
        $publicPlans = Plan::visible()->count();

        foreach ([null, 'ZZ', 'not-a-country'] as $country) {
            $offer = RegionPricing::offer($country);

            $this->assertNull($offer['region']);
            $this->assertCount($publicPlans, $offer['plans'], 'A visitor must never be left with no plans.');
            $this->assertNotSame('', $offer['reason']);
        }
    }

    public function test_a_region_that_would_offer_nothing_fails_open(): void
    {
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        // only_listed with no listed plan = a rule that sells nothing.
        $this->region(['offer_mode' => 'only_listed']);

        $offer = RegionPricing::offer('PK');

        $this->assertTrue($offer['fell_back']);
        $this->assertCount(Plan::visible()->count(), $offer['plans']);
        $this->assertStringContainsString('standard public catalogue', $offer['reason']);
    }

    public function test_an_inactive_or_archived_rule_takes_no_part(): void
    {
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $region = $this->region(['is_active' => false]);
        $this->rule($region, 'starter', ['price_cents' => 1]);

        $this->assertNull(RegionPricing::offer('PK')['region']);

        $region->forceFill(['is_active' => true])->save();
        $this->assertSame('pk_test', RegionPricing::offer('PK')['region']['key']);

        $region->forceFill(['archived_at' => now()])->save();
        $this->assertNull(RegionPricing::offer('PK')['region']);
    }

    public function test_every_resolution_explains_itself(): void
    {
        $offer = RegionPricing::offer('PK');

        $this->assertNotEmpty($offer['reason']);
        $this->assertStringContainsString('PK', $offer['reason']);
    }

    // -----------------------------------------------------------------------
    // 4. Country detection — explicit choice first, header only as a hint
    // -----------------------------------------------------------------------

    public function test_an_explicit_country_beats_an_edge_header(): void
    {
        $request = \Illuminate\Http\Request::create('/register', 'POST', ['country' => 'pk']);
        $request->headers->set('CF-IPCountry', 'US');

        $this->assertSame('PK', RegionPricing::countryFromRequest($request));
    }

    public function test_an_edge_header_is_used_only_when_nothing_was_stated(): void
    {
        $request = \Illuminate\Http\Request::create('/register', 'POST');
        $request->headers->set('CF-IPCountry', 'us');
        $this->assertSame('US', RegionPricing::countryFromRequest($request));

        // Cloudflare's "cannot tell" placeholder must not become a region.
        $unknown = \Illuminate\Http\Request::create('/register', 'POST');
        $unknown->headers->set('CF-IPCountry', 'XX');
        $this->assertNull(RegionPricing::countryFromRequest($unknown));

        $none = \Illuminate\Http\Request::create('/register', 'POST');
        $this->assertNull(RegionPricing::countryFromRequest($none));
    }

    // -----------------------------------------------------------------------
    // 5. Signup enforcement
    // -----------------------------------------------------------------------

    public function test_signup_stores_the_country_and_keeps_the_default_trial_plan(): void
    {
        $default = Plan::where('is_default_trial', true)->firstOrFail();

        $response = $this->post('/register', [
            'company' => 'Karachi Co',
            'name' => 'Owner',
            'email' => 'owner@karachi.test',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
            'country' => 'pk',
        ]);

        $response->assertRedirect(route('onboarding.show'));

        $org = Organization::where('slug', 'karachi-co')->firstOrFail();
        $this->assertSame('PK', $org->country);
        // The default-trial FLAG still decides the plan — never a literal key.
        $this->assertSame($default->id, $org->subscription->plan_id);
    }

    public function test_a_junk_country_is_rejected_at_signup_and_never_stored(): void
    {
        $this->from('/register')->post('/register', [
            'company' => 'Junk Co',
            'name' => 'Owner',
            'email' => 'owner@junk.test',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
            'country' => 'PAKISTAN',
        ])->assertSessionHasErrors('country');

        $this->assertNull(Organization::where('slug', 'junk-co')->first());
    }

    public function test_signup_lands_on_a_plan_the_region_actually_offers(): void
    {
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $default = Plan::where('is_default_trial', true)->firstOrFail();
        $free = Plan::where('key', 'free')->firstOrFail();
        $this->assertNotSame($default->id, $free->id);

        // A region that sells ONLY the Free plan.
        $region = $this->region(['offer_mode' => 'only_listed']);
        $this->rule($region, 'free', ['price_cents' => 0]);

        $org = app(TenantProvisioner::class)
            ->provision('Lahore Co', 'Owner', 'owner@lahore.test', 'super-secret-pw', country: 'PK')['organization'];

        $this->assertSame($free->id, $org->subscription->plan_id);

        // Elsewhere, the operator's default-trial plan is untouched.
        $other = app(TenantProvisioner::class)
            ->provision('Berlin Co', 'Owner', 'owner@berlin.test', 'super-secret-pw', country: 'DE')['organization'];

        $this->assertSame($default->id, $other->subscription->plan_id);
    }

    public function test_a_region_offering_nothing_cannot_block_a_signup(): void
    {
        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $this->region(['offer_mode' => 'only_listed']); // sells nothing

        $default = Plan::where('is_default_trial', true)->firstOrFail();

        $org = app(TenantProvisioner::class)
            ->provision('Blocked Co', 'Owner', 'owner@blocked.test', 'super-secret-pw', country: 'PK')['organization'];

        $this->assertSame($default->id, $org->subscription->plan_id);
        $this->assertSame(Organization::STATUS_TRIAL, $org->status);
    }

    public function test_country_is_not_mass_assignable(): void
    {
        $org = Organization::create([
            'name' => 'Sneaky Co',
            'slug' => 'sneaky-co',
            'status' => Organization::STATUS_TRIAL,
            'country' => 'PK',
        ]);

        $this->assertNull(
            $org->fresh()->country,
            'country must not be mass-assignable — a cheaper price list is not something a stray form may set.'
        );
    }

    // -----------------------------------------------------------------------
    // 6. Operator surface (role gate + audit)
    // -----------------------------------------------------------------------

    public function test_support_cannot_reach_the_region_pages(): void
    {
        $this->ensureRoutes();

        $this->actingAs($this->operator('support'), 'platform')
            ->get(route('platform.regions'))
            ->assertForbidden();

        $this->actingAs($this->operator('support', 'support2@platform.test'), 'platform')
            ->post(route('platform.regions.store'), ['name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_a_customer_host_cannot_reach_the_region_pages(): void
    {
        $this->ensureRoutes();

        // The operator portal is bound to its own host; a tenant user must not
        // be able to reach it at all.
        $this->get('/regions')->assertNotFound();
    }

    public function test_admin_can_create_a_region_and_it_starts_inactive_and_audited(): void
    {
        $this->ensureRoutes();

        $starter = Plan::where('key', 'starter')->firstOrFail();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.regions.store'), [
                'name' => 'South Asia',
                'description' => 'Regional pricing.',
                'countries' => ['pk', 'IN', 'zzz', 'PK'],
                'currency' => 'pkr',
                'offer_mode' => 'only_listed',
                'priority' => 20,
                'plans' => [
                    ['plan_id' => $starter->id, 'is_offered' => true, 'price_cents' => 90000, 'price_cents_yearly' => 900000],
                ],
                'reason' => 'Launch PKR pricing',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $region = PricingRegion::where('key', 'south_asia')->firstOrFail();

        $this->assertSame(['IN', 'PK'], $region->countries(), 'Codes are normalised, de-duplicated and sorted.');
        $this->assertSame('PKR', $region->currency);
        $this->assertFalse($region->is_active, 'A new rule must not re-price a country before the operator switches it on.');

        $rule = PricingRegionPlan::where('pricing_region_id', $region->id)->firstOrFail();
        $this->assertSame(90000, $rule->price_cents);

        $this->assertTrue(
            PlatformAuditLog::where('action', PricingRegion::AUDIT_CREATED)
                ->where('target_id', $region->id)
                ->exists()
        );
    }

    public function test_a_plan_rule_for_a_non_public_plan_is_dropped_on_write(): void
    {
        $this->ensureRoutes();

        $hidden = Plan::create([
            'key' => 'hidden_deal', 'name' => 'Hidden deal', 'sort' => 98,
            'price_cents' => 1, 'currency' => 'USD', 'is_public' => false,
            'visibility' => 'hidden', 'entitlements' => [],
        ]);

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.regions.store'), [
                'name' => 'Sneaky region',
                'countries' => ['FR'],
                'plans' => [['plan_id' => $hidden->id, 'is_offered' => true, 'price_cents' => 1]],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $region = PricingRegion::where('key', 'sneaky_region')->firstOrFail();

        $this->assertSame(
            0,
            PricingRegionPlan::where('pricing_region_id', $region->id)->count(),
            'A region may never carry a rule for a plan the operator has not published.'
        );
    }

    public function test_a_region_key_cannot_be_changed(): void
    {
        $this->ensureRoutes();

        $region = $this->region(['key' => 'immutable', 'name' => 'Immutable']);

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.regions.update', $region->id), [
                'name' => 'Immutable',
                'key' => 'something_else',
            ])
            ->assertSessionHasErrors('key');

        $this->assertSame('immutable', $region->fresh()->key);
    }

    public function test_archiving_a_region_returns_its_countries_to_the_public_catalogue(): void
    {
        $this->ensureRoutes();

        PricingRegionPlan::query()->delete();
        PricingRegion::query()->delete();
        RegionPricing::bump();

        $region = $this->region();
        $this->rule($region, 'starter', ['price_cents' => 90000]);

        $this->assertSame(90000, $this->entry(RegionPricing::offer('PK'), 'starter')['price_cents']);

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.regions.archive', $region->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        RegionPricing::flushMemo();
        $offer = RegionPricing::offer('PK');

        $this->assertNull($offer['region']);
        $this->assertSame(500, $this->entry($offer, 'starter')['price_cents']);
    }

    public function test_the_simulator_returns_the_same_answer_the_resolver_does(): void
    {
        $this->ensureRoutes();

        $response = $this->actingAs($this->operator(), 'platform')
            ->getJson(route('platform.regions.resolve', ['country' => 'PK']))
            ->assertOk();

        $this->assertSame(RegionPricing::offer('PK')['reason'], $response->json('reason'));
    }

    public function test_the_seeded_pkr_region_prices_the_catalogue_locally(): void
    {
        $offer = RegionPricing::offer('PK');

        $this->assertNotNull($offer['region'], 'The migration seeds a live Pakistan rule.');
        $this->assertSame('PKR', $offer['region']['currency']);
        $this->assertSame(90000, $this->entry($offer, 'starter')['price_cents']);
        $this->assertSame(160000, $this->entry($offer, 'pro')['price_cents']);
        $this->assertNull($this->entry($offer, 'enterprise')['price_cents_yearly'], 'Enterprise yearly stays negotiated.');
    }
}
