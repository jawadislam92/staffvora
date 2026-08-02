<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The entitlement ENGINE: the single resolution path
 *
 *     per-company override → active add-ons → plan → catalogue default (deny)
 *
 * These are security tests, not feature tests. Every assertion here is about
 * something being REFUSED when it should be, or a grant expiring on time.
 */
class EntitlementResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The catalogue is memoised for the life of a PHP process; each test
        // re-migrates the `features` table, so start from a clean read.
        Entitlements::flushMemo();

        // The entitlement refusal renders the Upgrade/Locked page, a new page
        // component absent from the committed Vite manifest until the next build.
        $this->withoutVite();
    }

    /** Put an org on a named plan with an active subscription. */
    private function onPlan(Organization $org, string $planKey): Organization
    {
        $plan = Plan::where('key', $planKey)->firstOrFail();

        app(CurrentOrganization::class)->runFor($org, fn () => Subscription::updateOrCreate(
            ['organization_id' => $org->id],
            ['plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addMonth()],
        ));

        return $org->refresh();
    }

    private function org(string $name = 'Resolve Co'): Organization
    {
        return Organization::create(['name' => $name, 'slug' => Str::slug($name)]);
    }

    private function agencyToolkit(): Addon
    {
        return Addon::where('key', 'agency_toolkit')->firstOrFail();
    }

    // --- Add-ons -----------------------------------------------------------

    public function test_an_addon_grants_a_feature_the_plan_does_not_include(): void
    {
        $org = $this->onPlan($this->org('Addon Grant Co'), 'pro');

        // No plan includes the agency features — they are sold as an add-on.
        $this->assertFalse($org->can('machine_compliance'));
        $this->assertFalse($org->can('upwork_profiles'));
        $this->assertSame('plan', $org->entitlementSource('machine_compliance'));

        $org->grantAddon($this->agencyToolkit());
        $org->refresh();

        $this->assertTrue($org->can('machine_compliance'));
        $this->assertTrue($org->can('upwork_profiles'));
        $this->assertSame('addon', $org->entitlementSource('machine_compliance'));

        // …and it only speaks about its own keys: everything else still resolves
        // from the plan.
        $this->assertSame('plan', $org->entitlementSource('ai'));
        $this->assertSame(50, $org->seatLimit());
    }

    public function test_a_company_override_revokes_a_feature_the_addon_grants(): void
    {
        $org = $this->onPlan($this->org('Revoke Co'), 'pro');
        $org->grantAddon($this->agencyToolkit());
        $org->refresh();
        $this->assertTrue($org->can('machine_compliance'));

        // The override layer sits ABOVE add-ons: a stored `false` is a revoke.
        $org->update(['entitlement_overrides' => ['machine_compliance' => false]]);
        $org->refresh();

        $this->assertFalse($org->can('machine_compliance'));
        $this->assertSame('override', $org->entitlementSource('machine_compliance'));
        // The rest of the add-on is untouched.
        $this->assertTrue($org->can('upwork_profiles'));
    }

    public function test_an_expired_addon_grant_stops_granting(): void
    {
        $org = $this->onPlan($this->org('Lapsed Addon Co'), 'pro');

        $org->grantAddon($this->agencyToolkit(), now()->subMonth(), now()->subDay());
        $org->refresh();

        $this->assertFalse($org->can('machine_compliance'));
        $this->assertSame('plan', $org->entitlementSource('machine_compliance'));
    }

    public function test_an_addon_may_only_ever_add_when_several_speak_about_one_key(): void
    {
        $org = $this->onPlan($this->org('Stacked Co'), 'starter');

        $small = Addon::create([
            'key' => 'extra_seats_small', 'name' => 'Extra seats (small)',
            'price_cents' => 100, 'currency' => 'USD', 'visibility' => 'hidden',
            'entitlements' => ['max_users' => 20],
        ]);
        $large = Addon::create([
            'key' => 'extra_seats_large', 'name' => 'Extra seats (large)',
            'price_cents' => 300, 'currency' => 'USD', 'visibility' => 'hidden',
            'entitlements' => ['max_users' => 75],
        ]);

        $org->grantAddon($small);
        $org->grantAddon($large);
        $org->refresh();

        // Two add-ons speak; the customer bought both, so the most permissive wins.
        $this->assertSame(75, $org->seatLimit());
    }

    // --- Temporary (expiring) grants ---------------------------------------

    public function test_an_expired_override_is_ignored_and_resolution_falls_through(): void
    {
        $org = $this->onPlan($this->org('Temp Grant Co'), 'free');
        $this->assertFalse($org->can('ai'));

        // A live temporary grant works…
        $org->update(['entitlement_overrides' => [
            'ai' => ['value' => true, 'expires_at' => now()->addWeek()->toIso8601String(), 'reason' => 'Sales trial'],
        ]]);
        $org->refresh();
        $this->assertTrue($org->can('ai'));
        $this->assertSame('override', $org->entitlementSource('ai'));

        // …and switches itself off when it lapses, with no cron and no cleanup.
        $org->update(['entitlement_overrides' => [
            'ai' => ['value' => true, 'expires_at' => now()->subMinute()->toIso8601String(), 'reason' => 'Sales trial'],
        ]]);
        $org->refresh();
        $this->assertFalse($org->can('ai'));
        $this->assertSame('plan', $org->entitlementSource('ai'));
    }

    public function test_an_expired_revoke_also_lapses_back_to_the_plan(): void
    {
        $org = $this->onPlan($this->org('Temp Revoke Co'), 'pro');

        $org->update(['entitlement_overrides' => [
            'ai' => ['value' => false, 'expires_at' => now()->subDay()->toIso8601String(), 'reason' => 'Abuse hold'],
        ]]);
        $org->refresh();

        $this->assertTrue($org->can('ai'));

        // An unparseable expiry is treated as expired — fail closed on the grant,
        // never leave a malformed record granting forever.
        $org->update(['entitlement_overrides' => ['machine_compliance' => ['value' => true, 'expires_at' => 'not-a-date']]]);
        $org->refresh();
        $this->assertFalse($org->can('machine_compliance'));
    }

    public function test_an_expiring_grant_keeps_its_reason_for_the_operator(): void
    {
        $clean = Entitlements::sanitizeOverrides([
            'ai' => ['value' => '1', 'expires_at' => '2099-01-01 00:00:00', 'reason' => '  Pilot for ACME  '],
        ]);

        $this->assertTrue($clean['ai']['value']);
        $this->assertSame('Pilot for ACME', $clean['ai']['reason']);
        $this->assertFalse(Entitlements::describeOverride($clean['ai'])['expired']);
    }

    // --- The floor-field bug (blank must mean inherit, never 0) -------------

    public function test_blanking_a_floor_field_inherits_instead_of_granting_zero(): void
    {
        // A `min` is a FLOOR: 0 is the most permissive value there is, so a
        // cleared box must never be written as 0.
        $planValues = Entitlements::sanitizePlanValues([
            'min_auto_pause_minutes' => '',
            'max_screenshots_per_hour' => 12,
            'monitoring' => true,
        ]);

        $this->assertArrayNotHasKey('min_auto_pause_minutes', $planValues);

        $plan = Plan::create([
            'key' => 'floor_test', 'name' => 'Floor test', 'price_cents' => 0,
            'currency' => 'USD', 'sort' => 99, 'is_public' => false, 'visibility' => 'hidden',
            'entitlements' => $planValues,
        ]);

        $org = $this->org('Floor Co');
        app(CurrentOrganization::class)->runFor($org, fn () => Subscription::create([
            'plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addMonth(),
        ]));
        $org->refresh();

        // Inherited the catalogue's strict floor, NOT the wide-open 0.
        $this->assertSame(10, $org->entitlement('min_auto_pause_minutes'));
        $this->assertFalse($org->entitlementAllows('min_auto_pause_minutes', 1));

        // Same rule on a per-company override: blank drops out entirely.
        $this->assertSame([], Entitlements::sanitizeOverrides(['min_auto_pause_minutes' => '']));
        $this->assertSame([], Entitlements::sanitizeOverrides(['min_auto_pause_minutes' => null]));

        $org->update(['entitlement_overrides' => Entitlements::sanitizeOverrides(['min_auto_pause_minutes' => ''])]);
        $org->refresh();
        $this->assertSame(10, $org->entitlement('min_auto_pause_minutes'));
    }

    public function test_a_deliberate_zero_floor_is_still_possible(): void
    {
        // Blank means inherit; typing 0 means the operator really meant 0.
        $clean = Entitlements::sanitizeOverrides(['min_auto_pause_minutes' => 0]);
        $this->assertSame(['min_auto_pause_minutes' => 0], $clean);
    }

    public function test_sanitiser_keeps_a_revoke_and_drops_unknown_keys(): void
    {
        $clean = Entitlements::sanitizeOverrides([
            'ai' => false,                       // deliberate revoke — must survive
            'not_a_real_feature' => true,        // never trust the request body
            'max_users' => '',                   // inherit
            'max_screenshots_per_hour' => '25',
        ]);

        $this->assertSame(false, $clean['ai']);
        $this->assertArrayNotHasKey('not_a_real_feature', $clean);
        $this->assertArrayNotHasKey('max_users', $clean);
        $this->assertSame(25, $clean['max_screenshots_per_hour']);
    }

    // --- "Unlimited" on a per-company override ------------------------------

    public function test_unlimited_is_expressible_per_company_and_differs_from_blank(): void
    {
        $org = $this->onPlan($this->org('Unlimited Co'), 'starter');
        $this->assertSame(10, $org->seatLimit());

        $org->update(['entitlement_overrides' => Entitlements::sanitizeOverrides(['max_users' => 'unlimited'])]);
        $org->refresh();

        $this->assertNull($org->seatLimit(), 'an override of "unlimited" must lift the ceiling entirely');
        $this->assertSame('override', $org->entitlementSource('max_users'));
        $this->assertTrue($org->entitlementAllows('max_users', 100000));

        // Blank is NOT unlimited — it inherits the plan's 10 seats again.
        $org->update(['entitlement_overrides' => Entitlements::sanitizeOverrides(['max_users' => ''])]);
        $org->refresh();
        $this->assertSame(10, $org->seatLimit());
    }

    // --- Default deny -------------------------------------------------------

    public function test_default_deny_still_holds_with_no_plan_at_all(): void
    {
        $org = $this->org('Bare Co');

        foreach (Entitlements::keys() as $key) {
            if (Entitlements::isFlag($key)) {
                $this->assertFalse($org->can($key), "`{$key}` is granted to a company with no plan");
            }
        }

        $this->assertSame(0, $org->entitlement('max_screenshots_per_hour'));
        $this->assertSame(0, $org->entitlement('max_storage_gb'));
        $this->assertSame(1, $org->seatLimit());          // not unlimited
        $this->assertSame(10, $org->entitlement('min_auto_pause_minutes'));
        $this->assertSame('default', $org->entitlementSource('ai'));
    }

    public function test_an_unknown_key_denies_and_never_resolves_to_unlimited(): void
    {
        $org = $this->onPlan($this->org('Unknown Key Co'), 'enterprise');

        $this->assertFalse($org->can('totally_made_up_key'));
        $this->assertNotNull($org->entitlement('totally_made_up_key'), 'null would mean UNLIMITED');
        $this->assertSame('unknown', $org->entitlementSource('totally_made_up_key'));
    }

    public function test_retiring_a_feature_revokes_it_even_where_a_plan_still_grants_it(): void
    {
        $org = $this->onPlan($this->org('Retired Feature Co'), 'pro');
        $this->assertTrue($org->can('ai'));

        Feature::where('key', 'ai')->firstOrFail()->update(['is_active' => false]);
        Entitlements::flushMemo();

        // The plan's stored JSON still says true; the catalogue is the authority.
        $this->assertTrue(Plan::where('key', 'pro')->first()->entitlement('ai'));
        $this->assertFalse($org->refresh()->can('ai'));
        $this->assertArrayNotHasKey('ai', $org->entitlements());
    }

    // --- Signup no longer hardcodes a plan key ------------------------------

    public function test_signup_follows_the_default_trial_flag_not_a_plan_key(): void
    {
        // Move the flag to Starter and archive the plan signups used to be
        // hardwired to. Nothing in the code may notice.
        Plan::query()->update(['is_default_trial' => false]);
        Plan::where('key', 'starter')->update(['is_default_trial' => true, 'trial_days' => 21]);
        Plan::where('key', 'pro')->update(['archived_at' => now()]);

        $org = app(TenantProvisioner::class)
            ->provision('Flagged Co', 'Owner', 'owner@flagged.test', 'super-secret-pw')['organization'];

        $this->assertSame('starter', $org->subscription->plan->key);
        $this->assertSame(10, $org->seatLimit());
        $this->assertTrue($org->trial_ends_at->greaterThan(now()->addDays(20)));
    }

    public function test_signup_survives_the_flagged_plan_being_archived(): void
    {
        Plan::where('is_default_trial', true)->update(['archived_at' => now()]);

        $org = app(TenantProvisioner::class)
            ->provision('Fallback Co', 'Owner', 'owner@fallback.test', 'super-secret-pw')['organization'];

        $plan = $org->subscription->plan;
        $this->assertNotNull($plan);
        $this->assertNull($plan->archived_at);
        $this->assertSame('public', $plan->visibility);
    }

    // --- The shared UI prop mirrors the same resolution ---------------------

    public function test_the_shared_entitlements_prop_reflects_addons_and_overrides_immediately(): void
    {
        $owner = app(TenantProvisioner::class)
            ->provision('Prop Co', 'Owner', 'owner@prop.test', 'super-secret-pw')['owner'];
        $org = Organization::find($owner->organization_id);

        $read = fn (string $key) => $this->actingAs($owner)
            ->get(route('billing.index'))
            ->viewData('page')['props']['entitlements'][$key] ?? null;

        $this->assertFalse($read('machine_compliance'));

        // Granting an add-on must be visible on the very next request, not when
        // a 60-second cache happens to lapse.
        $org->grantAddon($this->agencyToolkit());
        $this->assertTrue($read('machine_compliance'));

        // …and a per-company revoke likewise.
        $org->update(['entitlement_overrides' => ['machine_compliance' => false]]);
        $this->assertFalse($read('machine_compliance'));
    }

    public function test_the_shared_prop_is_rebuilt_when_the_plan_catalogue_changes(): void
    {
        $owner = app(TenantProvisioner::class)
            ->provision('Catalogue Co', 'Owner', 'owner@catalogue.test', 'super-secret-pw')['owner'];

        $read = fn (string $key) => $this->actingAs($owner)
            ->get(route('billing.index'))
            ->viewData('page')['props']['entitlements'][$key] ?? null;

        $this->assertTrue($read('ai'));

        $plan = Plan::where('is_default_trial', true)->firstOrFail();
        $plan->update(['entitlements' => array_merge($plan->entitlements, ['ai' => false])]);
        Entitlements::bump();

        $this->assertFalse($read('ai'), 'a plan edit must invalidate every cached tenant map');
    }

    // --- The HTTP gate uses the same answer ---------------------------------

    public function test_the_http_gate_follows_the_addon_and_then_the_revoke(): void
    {
        $owner = app(TenantProvisioner::class)
            ->provision('Gate Co', 'Owner', 'owner@gate.test', 'super-secret-pw')['owner'];
        $org = Organization::find($owner->organization_id);

        // Each call signs in with a FRESH user model: a real request never
        // reuses a User instance whose relations were loaded before the change.
        $visit = fn () => $this->actingAs($owner->fresh())->get(route('ai.assistant'));

        // The default trial plan includes AI, so the route is open…
        $visit()->assertOk();

        // …and a per-company revoke closes it, without a second gate anywhere.
        $org->update(['entitlement_overrides' => ['ai' => false]]);
        $visit()->assertForbidden();

        // Move the company to a plan that does NOT include AI, so the only way
        // the route can open is the override layer itself.
        $this->onPlan($org, 'free');
        $org->update(['entitlement_overrides' => null]);
        $visit()->assertForbidden();

        // A temporary grant opens it…
        $org->update(['entitlement_overrides' => ['ai' => ['value' => true, 'expires_at' => now()->addDay()->toIso8601String(), 'reason' => 'Two-week pilot']]]);
        $visit()->assertOk();

        // …and closes it again the moment it lapses — no cron, no cleanup job.
        $org->update(['entitlement_overrides' => ['ai' => ['value' => true, 'expires_at' => now()->subSecond()->toIso8601String(), 'reason' => 'Two-week pilot']]]);
        $visit()->assertForbidden();
    }

    // --- Catalogue plumbing -------------------------------------------------

    public function test_the_engine_reads_the_catalogue_from_the_features_table(): void
    {
        $this->assertContains('machine_compliance', Entitlements::keys());
        $this->assertSame('flag', Entitlements::kind('machine_compliance'));

        Feature::create([
            'key' => 'quarterly_review', 'label' => 'Quarterly review', 'kind' => 'flag',
            'group' => 'Features', 'default_value' => false, 'sort' => 500, 'is_active' => true,
        ]);
        Entitlements::flushMemo();

        // A feature added from the panel appears everywhere with no code change…
        $this->assertContains('quarterly_review', Entitlements::keys());

        // …and is DENIED by default, on every plan, until somebody grants it.
        $org = $this->onPlan($this->org('New Feature Co'), 'enterprise');
        $this->assertFalse($org->can('quarterly_review'));

        $org->update(['entitlement_overrides' => ['quarterly_review' => true]]);
        $this->assertTrue($org->refresh()->can('quarterly_review'));
    }

    public function test_plan_cards_are_described_from_the_live_catalogue(): void
    {
        $described = Entitlements::describe(Plan::where('key', 'enterprise')->firstOrFail()->entitlementMap());

        $labels = collect($described['flags'])->pluck('label')->all();
        $this->assertContains('Machine compliance', $labels);

        $seats = collect($described['caps'])->firstWhere('label', 'User seats');
        $this->assertSame('Unlimited', $seats['value']);

        $free = Entitlements::describe(Plan::where('key', 'free')->firstOrFail()->entitlementMap());
        $shots = collect($free['caps'])->firstWhere('label', 'Screenshots per hour (max)');
        $this->assertSame('Not included', $shots['value']);
    }
}
