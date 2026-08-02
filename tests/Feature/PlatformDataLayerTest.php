<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Models\Scopes\OrganizationScope;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plans/entitlements DATA LAYER: the editable feature catalogue, plan
 * lifecycle columns, add-ons, and the landlord-side operator audit log.
 *
 * The audit-log assertions here are security-critical: that table must be
 * append-only in practice and must never be tenant-scoped.
 */
class PlatformDataLayerTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $role = 'owner'): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Operator',
            'email' => 'op@platform.test',
            'password' => 'operator-secret-pw',
            'role' => $role,
        ]);
    }

    // --- Feature catalogue -------------------------------------------------

    public function test_feature_catalogue_carries_every_key_with_default_deny_values(): void
    {
        $catalogue = Feature::catalogue();

        // The 13 original registry keys must survive the move to the table
        // unchanged, plus the three new ones.
        foreach (\App\Tenancy\Entitlements::keys() as $key) {
            $this->assertArrayHasKey($key, $catalogue, "catalogue lost the `{$key}` feature");
            $this->assertSame(\App\Tenancy\Entitlements::kind($key), $catalogue[$key]['kind']);
            $this->assertSame(\App\Tenancy\Entitlements::default($key), $catalogue[$key]['default']);
        }

        foreach (['max_storage_gb', 'upwork_profiles', 'machine_compliance'] as $key) {
            $this->assertArrayHasKey($key, $catalogue);
        }

        // Default-deny: every flag defaults off, every ceiling defaults closed.
        $this->assertFalse($catalogue['machine_compliance']['default']);
        $this->assertFalse($catalogue['upwork_profiles']['default']);
        $this->assertSame(0, $catalogue['max_storage_gb']['default']);
    }

    public function test_an_unknown_feature_key_defaults_to_deny_not_unlimited(): void
    {
        // A `max` feature resolving to null would mean UNLIMITED, so an unknown
        // key must never return null.
        $this->assertFalse(Feature::defaultFor('totally_made_up_key'));
        $this->assertNull(Feature::kindOf('totally_made_up_key'));
    }

    public function test_catalogue_cache_is_busted_when_a_feature_changes(): void
    {
        $this->assertArrayNotHasKey('brand_new_thing', Feature::catalogue());

        Feature::create([
            'key' => 'brand_new_thing', 'label' => 'Brand new thing', 'kind' => 'flag',
            'group' => 'Features', 'default_value' => false, 'sort' => 999, 'is_active' => true,
        ]);

        $this->assertArrayHasKey('brand_new_thing', Feature::catalogue());

        // Deactivating drops it from the catalogue again, with no manual flush.
        Feature::where('key', 'brand_new_thing')->first()->update(['is_active' => false]);
        $this->assertArrayNotHasKey('brand_new_thing', Feature::catalogue());
    }

    // --- Plan lifecycle ----------------------------------------------------

    public function test_plans_seed_with_monthly_and_yearly_prices_and_visibility(): void
    {
        $plans = Plan::ordered()->get()->keyBy('key');

        $this->assertSame(0, $plans['free']->price_cents);
        $this->assertSame(500, $plans['starter']->price_cents);
        $this->assertSame(5000, $plans['starter']->price_cents_yearly);
        $this->assertSame(900, $plans['pro']->price_cents);
        $this->assertSame(9000, $plans['pro']->price_cents_yearly);
        $this->assertSame('Growth', $plans['pro']->name); // display name only
        $this->assertSame(1800, $plans['enterprise']->price_cents);
        $this->assertNull($plans['enterprise']->price_cents_yearly); // negotiated, not free

        $this->assertSame(['free', 'starter', 'pro', 'enterprise'], Plan::ordered()->pluck('key')->all());
        $this->assertCount(4, Plan::visible()->get());
    }

    public function test_exactly_one_plan_is_the_default_trial_plan(): void
    {
        $this->assertSame(1, Plan::where('is_default_trial', true)->count());
        $this->assertSame('pro', Plan::defaultTrial()->key);
    }

    public function test_archiving_a_plan_hides_it_but_signup_still_finds_a_trial_plan(): void
    {
        Plan::where('key', 'pro')->update(['archived_at' => now()]);

        $this->assertCount(3, Plan::visible()->get());
        $this->assertNotNull(Plan::defaultTrial());
        $this->assertNull(Plan::defaultTrial()->archived_at);
    }

    // --- Add-ons -----------------------------------------------------------

    public function test_agency_toolkit_addon_is_seeded_and_grants_the_agency_features(): void
    {
        $addon = Addon::where('key', 'agency_toolkit')->firstOrFail();

        $this->assertTrue($addon->entitlement('upwork_profiles'));
        $this->assertTrue($addon->entitlement('machine_compliance'));
        $this->assertSame(400, $addon->price_cents);
        $this->assertSame(4000, $addon->price_cents_yearly);
        $this->assertCount(1, Addon::visible()->get());
    }

    public function test_an_expired_addon_grant_drops_out_of_active_addons(): void
    {
        $org = Organization::create(['name' => 'Addon Co', 'slug' => 'addon-co']);
        $addon = Addon::where('key', 'agency_toolkit')->firstOrFail();

        $org->addons()->attach($addon->id, ['starts_at' => now()->subDay(), 'ends_at' => now()->subHour()]);
        $this->assertCount(1, $org->addons()->get());
        $this->assertCount(0, $org->activeAddons()->get());

        $org->addons()->updateExistingPivot($addon->id, ['ends_at' => null]);
        $this->assertCount(1, $org->activeAddons()->get());
    }

    // --- Operator audit log (security-critical) -----------------------------

    public function test_record_captures_the_actor_and_survives_the_actor_being_deleted(): void
    {
        $operator = $this->operator();
        $this->actingAs($operator, 'platform');

        $log = PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_TENANT_OVERRIDES_CHANGED,
            organizationId: 42,
            targetType: 'organization',
            targetId: 42,
            before: ['ai' => false],
            after: ['ai' => true],
            reason: 'Sales agreed a trial',
        );

        $this->assertSame($operator->id, $log->platform_user_id);
        $this->assertSame('Operator', $log->actor_name);
        $this->assertSame(['ai' => true], $log->after);

        $operator->delete();

        // The denormalised name is the whole point: deleting staff must not
        // erase who did what.
        $this->assertSame('Operator', $log->fresh()->actor_name);
    }

    public function test_audit_rows_cannot_be_edited_or_deleted(): void
    {
        $log = PlatformAuditLog::record(action: PlatformAuditLog::ACTION_TENANT_SUSPENDED, organizationId: 7);

        try {
            $log->update(['action' => 'something.else']);
            $this->fail('an audit row was allowed to be updated');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $log->delete();
            $this->fail('an audit row was allowed to be deleted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(PlatformAuditLog::ACTION_TENANT_SUSPENDED, $log->fresh()->action);
        $this->assertSame(1, PlatformAuditLog::count());
    }

    public function test_the_audit_log_is_landlord_side_and_never_tenant_scoped(): void
    {
        $orgA = app(TenantProvisioner::class)->provision('Alpha', 'A', 'a@alpha.test', 'super-secret-pw')['organization'];
        $orgB = app(TenantProvisioner::class)->provision('Beta', 'B', 'b@beta.test', 'super-secret-pw')['organization'];

        PlatformAuditLog::record(action: PlatformAuditLog::ACTION_TENANT_IMPERSONATED, organizationId: $orgA->id);
        PlatformAuditLog::record(action: PlatformAuditLog::ACTION_TENANT_IMPERSONATED, organizationId: $orgB->id);

        // The model must NOT carry the tenant global scope: binding a tenant
        // context must not silently filter (or, worse, stamp) audit rows.
        $this->assertArrayNotHasKey(
            OrganizationScope::class,
            (new PlatformAuditLog)->getGlobalScopes(),
            'platform_audit_logs must never be tenant-scoped'
        );
        $this->assertNotContains(
            \App\Models\Concerns\BelongsToOrganization::class,
            class_uses_recursive(PlatformAuditLog::class),
            'platform_audit_logs must not use BelongsToOrganization'
        );

        app(CurrentOrganization::class)->runFor($orgA, function () {
            $this->assertSame(2, PlatformAuditLog::count());
        });

        // Filtering by target org is an explicit, deliberate act.
        $this->assertSame(1, PlatformAuditLog::forOrganization($orgA->id)->count());
    }
}
