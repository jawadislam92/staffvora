<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Services\TenantProvisioner;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * THE OPERATOR AUDIT TRAIL + the per-company override editor.
 *
 * The owner's requirement is "see WHO changed a company's access and WHEN", so
 * every mutating operator action has to leave a row — including impersonation,
 * which was completely unlogged even though any operator role can sign in as a
 * customer's super-admin and read their employees' monitoring data.
 */
class PlatformAuditTrailTest extends TestCase
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
            'name' => ucfirst($role).' Operator',
            'email' => $email ?: $role.'@platform.test',
            'password' => 'operator-secret-pw',
            'role' => $role,
        ]);
    }

    private function tenant(): Organization
    {
        return app(TenantProvisioner::class)
            ->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['organization'];
    }

    // -----------------------------------------------------------------------
    // Every mutation is recorded
    // -----------------------------------------------------------------------

    public function test_lifecycle_actions_are_all_recorded_with_the_actor(): void
    {
        $org = $this->tenant();
        $op = $this->operator();

        $this->actingAs($op, 'platform')->post(route('platform.suspend', $org->id), ['reason' => 'Chargeback'])->assertRedirect();
        $this->actingAs($op, 'platform')->post(route('platform.resume', $org->id))->assertRedirect();
        $this->actingAs($op, 'platform')->post(route('platform.trial', $org->id), ['action' => 'extend', 'days' => 7])->assertRedirect();
        $this->actingAs($op, 'platform')->post(route('platform.payment', $org->id), ['amount' => '80', 'months' => 1])->assertRedirect();

        $actions = PlatformAuditLog::forOrganization($org->id)->pluck('action')->all();

        $this->assertContains(PlatformAuditLog::ACTION_TENANT_SUSPENDED, $actions);
        $this->assertContains(PlatformAuditLog::ACTION_TENANT_RESUMED, $actions);
        $this->assertContains(PlatformAuditLog::ACTION_TENANT_TRIAL_CHANGED, $actions);
        $this->assertContains(PlatformAuditLog::ACTION_TENANT_PAYMENT_RECORDED, $actions);

        $suspend = PlatformAuditLog::where('action', PlatformAuditLog::ACTION_TENANT_SUSPENDED)->firstOrFail();
        $this->assertSame($op->id, $suspend->platform_user_id);
        // The name is denormalised so deleting the staff account cannot erase who did it.
        $this->assertSame('Admin Operator', $suspend->actor_name);
        $this->assertSame('Chargeback', $suspend->reason);
        $this->assertNotNull($suspend->ip);
        $this->assertSame(Organization::STATUS_SUSPENDED, $suspend->after['status']);
    }

    public function test_plan_switch_access_change_and_price_change_are_recorded_separately(): void
    {
        $org = $this->tenant();
        $org->subscription->update(['plan_id' => Plan::where('key', 'free')->firstOrFail()->id]);

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.tenant.update', $org->id), [
                'plan' => 'starter',
                'overrides' => ['max_screenshots_per_hour' => 25],
                'custom_price_cents' => 350,
                'discount_percent' => 10,
                'reason' => 'Renewal negotiation',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $byAction = PlatformAuditLog::forOrganization($org->id)->get()->keyBy('action');

        $this->assertTrue($byAction->has(PlatformAuditLog::ACTION_TENANT_PLAN_CHANGED));
        $this->assertTrue($byAction->has(PlatformAuditLog::ACTION_TENANT_OVERRIDES_CHANGED));
        $this->assertTrue($byAction->has(PlatformAuditLog::ACTION_TENANT_PRICE_CHANGED));

        $plan = $byAction[PlatformAuditLog::ACTION_TENANT_PLAN_CHANGED];
        $this->assertSame('free', $plan->before['plan']);
        $this->assertSame('starter', $plan->after['plan']);

        $price = $byAction[PlatformAuditLog::ACTION_TENANT_PRICE_CHANGED];
        $this->assertNull($price->before['custom_price_cents']);
        $this->assertSame(350, $price->after['custom_price_cents']);
        $this->assertSame('Renewal negotiation', $price->reason);
    }

    public function test_an_unchanged_save_does_not_spam_the_history(): void
    {
        $org = $this->tenant();

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.tenant.update', $org->id), ['overrides' => []])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, PlatformAuditLog::forOrganization($org->id)->count());
    }

    // -----------------------------------------------------------------------
    // Impersonation — the gap this build closes
    // -----------------------------------------------------------------------

    public function test_impersonation_is_recorded_at_both_ends(): void
    {
        $provision = app(TenantProvisioner::class)
            ->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw');
        $org = $provision['organization'];
        $target = $provision['owner'];

        // Support — the lowest operator role — can still do this, which is
        // precisely why it has to be recorded.
        $support = $this->operator('support');

        $response = $this->actingAs($support, 'platform')->post(route('platform.impersonate', $org->id));
        $response->assertStatus(302);

        $requested = PlatformAuditLog::where('action', PlatformAuditLog::ACTION_TENANT_IMPERSONATED)->firstOrFail();
        $this->assertSame($support->id, $requested->platform_user_id);
        $this->assertSame('Support Operator', $requested->actor_name);
        $this->assertSame($org->id, $requested->organization_id);
        $this->assertSame($target->id, $requested->target_id);
        $this->assertSame('requested', $requested->after['stage']);

        // Follow the signed hand-off onto the customer host.
        $this->get($response->headers->get('Location'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($target, 'web');

        $entered = PlatformAuditLog::where('action', PlatformAuditLog::ACTION_TENANT_IMPERSONATED)
            ->orderByDesc('id')->firstOrFail();

        $this->assertSame('entered', $entered->after['stage']);
        // The operator id rides inside the SIGNED url, so it survives the host
        // hop even though there is no platform session on the customer host.
        $this->assertSame($support->id, $entered->platform_user_id);
        $this->assertSame($org->id, $entered->organization_id);
    }

    // -----------------------------------------------------------------------
    // The trail itself
    // -----------------------------------------------------------------------

    public function test_the_audit_trail_is_append_only(): void
    {
        $log = PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_TENANT_SUSPENDED,
            organizationId: null,
        );

        try {
            $log->update(['reason' => 'rewritten']);
            $this->fail('An audit row must not be updatable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $log->delete();
            $this->fail('An audit row must not be deletable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertDatabaseHas('platform_audit_logs', ['id' => $log->id]);
    }

    public function test_the_tenant_page_serves_the_history_and_the_global_log_is_admin_only(): void
    {
        $org = $this->tenant();
        $admin = $this->operator();

        $this->actingAs($admin, 'platform')->post(route('platform.suspend', $org->id))->assertRedirect();

        $this->actingAs($admin, 'platform')
            ->get(route('platform.show', $org->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('history.data', 1)
                ->where('history.data.0.action', PlatformAuditLog::ACTION_TENANT_SUSPENDED)
                ->where('history.data.0.actor', 'Admin Operator'));

        $this->actingAs($admin, 'platform')->get(route('platform.audit'))->assertOk();

        // Support may help a customer, but may not read the staff activity log.
        $this->actingAs($this->operator('support'), 'platform')
            ->get(route('platform.audit'))
            ->assertForbidden();
    }

    public function test_a_customer_can_never_read_operator_activity(): void
    {
        $owner = app(TenantProvisioner::class)
            ->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw')['owner'];

        $this->actingAs($owner)->get(route('platform.audit'))->assertRedirect(route('platform.login'));

        // Structural guarantee, not just a route one: the model is landlord-side.
        $this->assertNotContains(
            \App\Models\Concerns\BelongsToOrganization::class,
            class_uses_recursive(PlatformAuditLog::class),
            'platform_audit_logs must never be tenant-scoped.'
        );
    }

    // -----------------------------------------------------------------------
    // Per-company overrides: unlimited, expiry, reason, revoke
    // -----------------------------------------------------------------------

    public function test_an_override_can_grant_unlimited_seats_to_one_company(): void
    {
        $org = $this->tenant();
        $org->subscription->update(['plan_id' => Plan::where('key', 'free')->firstOrFail()->id]);
        $org->refresh();
        $this->assertNotNull($org->seatLimit());

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.tenant.update', $org->id), [
                'overrides' => ['max_users' => ['value' => 'unlimited', 'reason' => 'Enterprise pilot']],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $org->refresh();
        Entitlements::flushMemo();

        $this->assertNull($org->seatLimit(), 'Unlimited must be expressible per company.');
        $this->assertSame('override', $org->entitlementSource('max_users'));
    }

    public function test_an_override_can_carry_an_expiry_that_switches_itself_off(): void
    {
        $org = $this->tenant();

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.tenant.update', $org->id), [
                'overrides' => [
                    'machine_compliance' => [
                        'value' => '1',
                        'expires_at' => now()->addDays(7)->toDateString(),
                        'reason' => 'Sales trial',
                    ],
                ],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $org->refresh();
        Entitlements::flushMemo();
        $this->assertTrue($org->can('machine_compliance'));

        $stored = $org->entitlement_overrides['machine_compliance'];
        $this->assertSame('Sales trial', $stored['reason']);
        $this->assertNotEmpty($stored['expires_at']);

        // Once it lapses the grant simply stops contributing.
        $this->travel(8)->days();
        $org->refresh();
        $this->assertFalse($org->can('machine_compliance'));
        $this->travelBack();
    }

    public function test_an_override_can_revoke_something_the_plan_grants(): void
    {
        $org = $this->tenant();
        $plan = $org->activePlan();
        $plan->forceFill(['entitlements' => array_merge($plan->entitlements, ['monitoring' => true])])->save();
        Entitlements::flushMemo();
        $this->assertTrue($org->fresh()->can('monitoring'));

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.tenant.update', $org->id), [
                'overrides' => ['monitoring' => ['value' => '0', 'reason' => 'Customer asked us to stop']],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $org->refresh();
        Entitlements::flushMemo();

        $this->assertFalse($org->can('monitoring'));
        $this->assertSame('override', $org->entitlementSource('monitoring'));
    }

    public function test_a_blank_override_on_a_floor_key_never_becomes_zero(): void
    {
        // 0 is the most permissive floor there is; clearing a box must mean
        // "inherit", never "grant the tightest possible setting".
        $org = $this->tenant();

        $this->actingAs($this->operator(), 'platform')
            ->put(route('platform.tenant.update', $org->id), [
                'overrides' => ['min_auto_pause_minutes' => ['value' => '']],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $org->refresh();

        $this->assertArrayNotHasKey('min_auto_pause_minutes', $org->entitlement_overrides ?? []);
        $this->assertNotSame(0, $org->entitlement('min_auto_pause_minutes'));
    }

    public function test_the_access_panel_reports_where_every_value_came_from(): void
    {
        $org = $this->tenant();
        $addon = Addon::where('key', 'agency_toolkit')->firstOrFail();
        $org->grantAddon($addon);
        $org->update(['entitlement_overrides' => ['max_users' => 99]]);
        Entitlements::flushMemo();

        $this->actingAs($this->operator(), 'platform')
            ->get(route('platform.show', $org->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('entitlements.effective.max_users.source', 'override')
                ->where('entitlements.effective.max_users.value', 99)
                ->where('entitlements.effective.machine_compliance.source', 'addon')
                ->where('entitlements.layers.machine_compliance.addons.0.name', 'Agency toolkit')
                ->has('entitlements.overrides.max_users')
                ->has('addons.attached', 1));
    }
}
