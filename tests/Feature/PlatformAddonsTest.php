<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Services\TenantProvisioner;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADD-ONS — sell a capability without moving anyone between plans, and grant it
 * to ONE company from the operator panel (the "Machine Compliance is not welded
 * to a plan" requirement).
 *
 * Role gate under test: `platform.role:admin` for the catalogue and for
 * per-company attach/detach.
 */
class PlatformAddonsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The new operator pages are not in the committed Vite manifest until
        // the next `npm run build`; stub Vite so a page render under test is
        // about the CONTROLLER, not the asset pipeline.
        $this->withoutVite();
        Entitlements::flushMemo();
    }

    private function operator(string $role = 'admin', ?string $email = null): PlatformUser
    {
        return PlatformUser::create([
            'name' => ucfirst($role),
            'email' => $email ?: $role.'@platform.test',
            'password' => 'operator-secret-pw',
            'role' => $role,
        ]);
    }

    private function tenant(string $name = 'Acme', string $email = 'owner@acme.test'): Organization
    {
        return app(TenantProvisioner::class)
            ->provision($name, 'Owner', $email, 'super-secret-pw')['organization'];
    }

    // -----------------------------------------------------------------------
    // Catalogue
    // -----------------------------------------------------------------------

    public function test_admin_can_create_an_addon_with_monthly_and_yearly_prices(): void
    {
        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.addons.store'), [
                'name' => 'Compliance pack',
                'description' => 'Machine compliance for regulated teams.',
                'price_cents' => 600,
                'price_cents_yearly' => 6000,
                'visibility' => 'public',
                'entitlements' => ['machine_compliance' => true],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $addon = Addon::where('key', 'compliance_pack')->firstOrFail();

        $this->assertSame(600, $addon->price_cents);
        $this->assertSame(6000, $addon->price_cents_yearly);
        $this->assertSame('public', $addon->visibility);
        $this->assertTrue($addon->entitlement('machine_compliance'));

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => PlatformAuditLog::ACTION_ADDON_CREATED,
            'target_id' => $addon->id,
        ]);
    }

    public function test_an_addon_never_stores_a_flag_that_is_off(): void
    {
        // THE important regression. An add-on resolves ABOVE the plan, so if a
        // flag the operator left off were stored as `false`, buying the add-on
        // would silently REVOKE what the customer's plan already grants.
        $org = $this->tenant();
        $plan = $org->activePlan();
        $plan->forceFill(['entitlements' => array_merge($plan->entitlements, ['ai' => true, 'monitoring' => true])])->save();
        Entitlements::flushMemo();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.addons.store'), [
                'name' => 'Compliance pack',
                'price_cents' => 600,
                'entitlements' => [
                    'machine_compliance' => true,
                    'ai' => false,          // left off in the editor
                    'monitoring' => '0',    // left off in the editor
                    'max_users' => '',      // blank = says nothing
                ],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $addon = Addon::where('key', 'compliance_pack')->firstOrFail();

        $this->assertSame(['machine_compliance' => true], $addon->entitlements);
        $this->assertArrayNotHasKey('ai', $addon->entitlements);
        $this->assertArrayNotHasKey('monitoring', $addon->entitlements);
        $this->assertArrayNotHasKey('max_users', $addon->entitlements);

        // And granting it leaves the plan's own grants untouched.
        $org->grantAddon($addon);
        $org->refresh();
        Entitlements::flushMemo();

        $this->assertTrue($org->can('ai'), 'An add-on must never revoke a plan grant.');
        $this->assertTrue($org->can('monitoring'));
        $this->assertTrue($org->can('machine_compliance'));
    }

    public function test_an_addon_key_cannot_be_changed(): void
    {
        $addon = Addon::firstOrFail();

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.addons.update', $addon->id), [
                'key' => 'renamed_key',
                'name' => 'Renamed',
                'price_cents' => 100,
            ])
            ->assertSessionHasErrors('key');

        $this->assertNotSame('renamed_key', $addon->fresh()->key);
    }

    // -----------------------------------------------------------------------
    // Per-company grants
    // -----------------------------------------------------------------------

    public function test_granting_an_addon_changes_only_that_company(): void
    {
        $lucky = $this->tenant('Lucky', 'owner@lucky.test');
        $other = $this->tenant('Other', 'owner@other.test');
        $addon = Addon::where('key', 'agency_toolkit')->firstOrFail();

        $this->assertFalse($lucky->can('machine_compliance'));

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.tenant.addons.attach', $lucky->id), [
                'addon_id' => $addon->id,
                'reason' => 'Paid for Q3',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        Entitlements::flushMemo();

        $this->assertTrue($lucky->fresh()->can('machine_compliance'));
        $this->assertTrue($lucky->fresh()->can('upwork_profiles'));
        $this->assertFalse($other->fresh()->can('machine_compliance'), 'Nobody else may be affected.');

        // Provenance is reported by the engine, not re-derived by the panel.
        $this->assertSame('addon', $lucky->fresh()->entitlementSource('machine_compliance'));

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => PlatformAuditLog::ACTION_ADDON_GRANTED,
            'organization_id' => $lucky->id,
            'target_id' => $addon->id,
            'reason' => 'Paid for Q3',
        ]);
    }

    public function test_revoking_an_addon_takes_the_capability_away(): void
    {
        $org = $this->tenant();
        $addon = Addon::where('key', 'agency_toolkit')->firstOrFail();
        $org->grantAddon($addon);
        Entitlements::flushMemo();
        $this->assertTrue($org->fresh()->can('machine_compliance'));

        $this->actingAs($this->operator(), 'platform')
            ->delete(route('platform.tenant.addons.detach', [$org->id, $addon->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        Entitlements::flushMemo();

        $this->assertFalse($org->fresh()->can('machine_compliance'));
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => PlatformAuditLog::ACTION_ADDON_REVOKED,
            'organization_id' => $org->id,
        ]);
    }

    public function test_a_time_boxed_grant_expires_on_its_own(): void
    {
        $org = $this->tenant();
        $addon = Addon::where('key', 'agency_toolkit')->firstOrFail();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.tenant.addons.attach', $org->id), [
                'addon_id' => $addon->id,
                'ends_at' => now()->subDay()->toDateString(),
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        Entitlements::flushMemo();

        // The row is attached but outside its window, so it contributes nothing.
        $this->assertSame(1, $org->addons()->count());
        $this->assertFalse($org->fresh()->can('machine_compliance'));
    }

    public function test_an_archived_addon_cannot_be_granted(): void
    {
        $org = $this->tenant();
        $addon = Addon::where('key', 'agency_toolkit')->firstOrFail();
        $addon->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.tenant.addons.attach', $org->id), ['addon_id' => $addon->id])
            ->assertSessionHasErrors('addon_id');

        $this->assertSame(0, $org->addons()->count());
    }

    public function test_archiving_an_addon_stops_it_granting_to_existing_holders(): void
    {
        $org = $this->tenant();
        $addon = Addon::where('key', 'agency_toolkit')->firstOrFail();
        $org->grantAddon($addon);
        Entitlements::flushMemo();
        $this->assertTrue($org->fresh()->can('machine_compliance'));

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.addons.archive', $addon->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        Entitlements::flushMemo();

        $this->assertFalse($org->fresh()->can('machine_compliance'));
    }

    public function test_a_nonexistent_addon_id_is_refused(): void
    {
        $org = $this->tenant();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.tenant.addons.attach', $org->id), ['addon_id' => 999999])
            ->assertSessionHasErrors('addon_id');
    }

    public function test_the_addon_page_reports_how_many_companies_hold_each_one(): void
    {
        $org = $this->tenant();
        $addon = Addon::where('key', 'agency_toolkit')->firstOrFail();
        $org->grantAddon($addon);

        $this->actingAs($this->operator(), 'platform')
            ->get(route('platform.addons'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Addons')
                ->has('registry')
                ->where('addons.0.key', 'agency_toolkit')
                ->where('addons.0.holders', 1));
    }

    // -----------------------------------------------------------------------
    // Role gate
    // -----------------------------------------------------------------------

    public function test_support_cannot_change_what_a_customer_bought(): void
    {
        $support = $this->operator('support');
        $org = $this->tenant();
        $addon = Addon::where('key', 'agency_toolkit')->firstOrFail();

        $this->actingAs($support, 'platform')->get(route('platform.addons'))->assertForbidden();
        $this->actingAs($support, 'platform')->post(route('platform.addons.store'), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($support, 'platform')->put(route('platform.addons.update', $addon->id), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($support, 'platform')->post(route('platform.addons.archive', $addon->id))->assertForbidden();
        $this->actingAs($support, 'platform')
            ->post(route('platform.tenant.addons.attach', $org->id), ['addon_id' => $addon->id])
            ->assertForbidden();
        $this->actingAs($support, 'platform')
            ->delete(route('platform.tenant.addons.detach', [$org->id, $addon->id]))
            ->assertForbidden();

        $this->assertSame(0, $org->addons()->count());
        $this->assertDatabaseMissing('addons', ['name' => 'Nope']);
    }

    public function test_a_customer_cannot_reach_the_addon_catalogue(): void
    {
        $owner = app(TenantProvisioner::class)
            ->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw')['owner'];

        $this->actingAs($owner)->get(route('platform.addons'))->assertRedirect(route('platform.login'));
    }
}
