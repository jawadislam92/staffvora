<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureEntitlement;
use App\Models\Addon;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * THE "SHOWN, NOT HIDDEN" GATE — what a customer meets when a capability is not
 * in their plan.
 *
 * Three things must hold at once, and they pull against each other:
 *
 *   1. IT IS STILL A REFUSAL. The guarded controller must not run and the status
 *      must stay 403. A friendlier body is not a softer gate.
 *   2. IT MUST SELL. The response has to name the capability, say what it does,
 *      and show the cheapest published way to get it.
 *   3. THE PRICE MUST BE THE OPERATOR'S. Every figure is re-derived from the
 *      plan / add-on ROW on the server. Nothing a client sends may name a plan,
 *      alter a price, or make a refusal advertise something unpublished.
 */
class UpgradeGatePresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Entitlements::flushMemo();
        $this->withoutVite();
    }

    /** A brand-new workspace with its owner, on the default trial plan. */
    private function owner(string $company, string $email): \App\Models\User
    {
        return app(TenantProvisioner::class)
            ->provision($company, 'Owner', $email, 'super-secret-pw')['owner'];
    }

    private function onPlan(Organization $org, string $planKey): Organization
    {
        $plan = Plan::where('key', $planKey)->firstOrFail();

        app(CurrentOrganization::class)->runFor($org, fn () => Subscription::updateOrCreate(
            ['organization_id' => $org->id],
            ['plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addMonth()],
        ));

        return $org->refresh();
    }

    private function revoke(Organization $org, string $key): void
    {
        Organization::withoutGlobalScopes()->whereKey($org->id)->update([
            'entitlement_overrides' => [$key => false],
        ]);
    }

    // -----------------------------------------------------------------------
    // It is still a refusal
    // -----------------------------------------------------------------------

    public function test_a_denied_browser_request_is_403_and_renders_the_upgrade_page(): void
    {
        $owner = $this->owner('Locked Co', 'owner@locked.test');
        $this->revoke($owner->organization, 'ai');

        $this->actingAs($owner->fresh())
            ->get(route('ai.assistant'))
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Upgrade/Locked')
                ->where('feature', 'ai')
                ->where('name', Entitlements::entry('ai')['label'])
                ->has('offers')
            );
    }

    public function test_the_guarded_controller_never_runs(): void
    {
        $owner = $this->owner('No Run Co', 'owner@norun.test');
        $this->revoke($owner->organization, 'ai');

        // The refusal carries the upgrade page and nothing the real screen would
        // have produced: no feature payload leaks through the friendlier body.
        $this->actingAs($owner->fresh())
            ->get(route('ai.assistant'))
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Upgrade/Locked')
                ->missing('conversations')
                ->missing('messages')
            );
    }

    public function test_a_machine_caller_still_gets_the_compact_json_refusal(): void
    {
        $owner = $this->owner('Api Co', 'owner@apico.test');
        $this->revoke($owner->organization, 'ai');

        $this->actingAs($owner->fresh())
            ->getJson(route('ai.assistant'))
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_not_in_plan')
            ->assertJsonPath('feature', 'ai')
            ->assertJsonPath('upgrade_url', route('billing.index'));
    }

    public function test_an_entitled_workspace_is_untouched(): void
    {
        $owner = $this->owner('Open Co', 'owner@openco.test');

        // The default trial plan includes AI — the gate must stay invisible.
        $this->actingAs($owner->fresh())->get(route('ai.assistant'))->assertOk();
    }

    // -----------------------------------------------------------------------
    // The money: derived server-side, from the catalogue row
    // -----------------------------------------------------------------------

    public function test_the_offer_names_the_cheapest_public_plan_that_grants_the_key(): void
    {
        $owner = $this->owner('Offer Co', 'owner@offerco.test');
        $this->revoke($owner->organization, 'ai');

        $expected = Plan::query()->visible()->get()
            ->filter(fn (Plan $p) => ($p->entitlements['ai'] ?? null) === true)
            ->sortBy('price_cents')
            ->first();

        $this->assertNotNull($expected, 'the seeded catalogue must publish at least one plan with AI');

        $this->actingAs($owner->fresh())
            ->get(route('ai.assistant'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Upgrade/Locked')
                ->where('offers.plan.name', $expected->name)
                ->where('offers.plan.price_cents', (int) $expected->price_cents)
                ->where('offers.plan.kind', 'plan')
            );
    }

    public function test_prices_are_integer_minor_units_taken_from_the_row(): void
    {
        $owner = $this->owner('Cents Co', 'owner@centsco.test');
        $this->revoke($owner->organization, 'ai');

        $response = $this->actingAs($owner->fresh())->get(route('ai.assistant'));
        $offers = $response->viewData('page')['props']['offers'];

        $this->assertIsInt($offers['plan']['price_cents']);
        $this->assertTrue(
            $offers['plan']['price_cents_yearly'] === null || is_int($offers['plan']['price_cents_yearly']),
            'a yearly price is either an integer or a meaningful null — never a float',
        );
    }

    public function test_a_client_cannot_influence_the_advertised_plan_or_price(): void
    {
        $owner = $this->owner('Crafted Co', 'owner@crafted.test');
        $this->revoke($owner->organization, 'ai');

        $real = Plan::query()->visible()->get()
            ->filter(fn (Plan $p) => ($p->entitlements['ai'] ?? null) === true)
            ->sortBy('price_cents')
            ->first();

        // Everything an attacker controls, thrown at the refusal path at once.
        $this->actingAs($owner->fresh())
            ->get(route('ai.assistant').'?price_cents=1&plan=free&offers[plan][price_cents]=1&feature=white_label')
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('feature', 'ai')
                ->where('offers.plan.price_cents', (int) $real->price_cents)
                ->where('offers.plan.name', $real->name)
            );
    }

    public function test_an_addon_route_is_offered_with_its_own_catalogue_price(): void
    {
        $owner = $this->owner('Agency Co', 'owner@agency-upsell.test');
        $org = $owner->organization;

        // A workspace with no add-on and a plan that does not grant profiles.
        $this->onPlan($org, 'free');

        $addon = Addon::where('key', 'agency_toolkit')->firstOrFail();
        $this->assertTrue(($addon->entitlements['upwork_profiles'] ?? null) === true);

        $this->actingAs($owner->fresh())
            ->get(route('upwork-profiles.index'))
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Upgrade/Locked')
                ->where('feature', 'upwork_profiles')
                ->where('offers.addon.kind', 'addon')
                ->where('offers.addon.name', $addon->name)
                ->where('offers.addon.price_cents', (int) $addon->price_cents)
            );
    }

    // -----------------------------------------------------------------------
    // The catalogue is the only source of the offer
    // -----------------------------------------------------------------------

    public function test_a_retired_feature_key_advertises_nothing(): void
    {
        $this->assertSame(
            ['plan' => null, 'addon' => null],
            EnsureEntitlement::offersFor('not_a_real_feature_key'),
        );
    }

    public function test_a_numeric_ceiling_is_never_advertised_as_purchasable(): void
    {
        // `max_users` is a cap, not something a customer "unlocks"; a plan whose
        // map stores a number must not be paraded as the way to buy a flag.
        $this->assertSame(
            ['plan' => null, 'addon' => null],
            EnsureEntitlement::offersFor('max_users'),
        );
    }

    public function test_archived_or_hidden_plans_are_never_offered(): void
    {
        $owner = $this->owner('Hidden Co', 'owner@hiddenco.test');
        $this->revoke($owner->organization, 'ai');

        // Retire every public plan that grants AI: the page must stop promising
        // a route to it rather than advertise something nobody can buy.
        Plan::query()->visible()->get()
            ->filter(fn (Plan $p) => ($p->entitlements['ai'] ?? null) === true)
            ->each(fn (Plan $p) => $p->forceFill(['archived_at' => now()])->save());

        Entitlements::bump();

        $this->actingAs($owner->fresh())
            ->get(route('ai.assistant'))
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('offers.plan', null));
    }

    public function test_the_offer_cache_is_invalidated_by_a_catalogue_change(): void
    {
        $owner = $this->owner('Repriced Co', 'owner@repriced.test');
        $this->revoke($owner->organization, 'ai');

        $read = fn () => $this->actingAs($owner->fresh())
            ->get(route('ai.assistant'))
            ->viewData('page')['props']['offers']['plan']['price_cents'] ?? null;

        $first = $read();
        $this->assertNotNull($first);

        Plan::query()->visible()->get()
            ->filter(fn (Plan $p) => ($p->entitlements['ai'] ?? null) === true)
            ->each(fn (Plan $p) => $p->forceFill(['price_cents' => $p->price_cents + 100])->save());

        Entitlements::bump();

        $this->assertSame($first + 100, $read(), 'a repriced plan must show immediately, not when a TTL lapses');
    }

    // -----------------------------------------------------------------------
    // Tenancy
    // -----------------------------------------------------------------------

    public function test_the_refusal_carries_no_other_workspace_data(): void
    {
        $mine = $this->owner('Mine Co', 'owner@mineco.test');
        $this->owner('Theirs Co', 'owner@theirsco.test');

        $this->revoke($mine->organization, 'ai');

        $body = $this->actingAs($mine->fresh())->get(route('ai.assistant'))->getContent();

        $this->assertStringNotContainsString('Theirs Co', $body);
        $this->assertStringNotContainsString('owner@theirsco.test', $body);
    }
}
