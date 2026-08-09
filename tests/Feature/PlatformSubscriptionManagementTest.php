<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionManager;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PER-COMPANY SUBSCRIPTION MANAGEMENT — the operator can finally move a company
 * from one plan to another, flip its billing cycle and set its state.
 *
 * The things these tests exist to keep true:
 *   • entitlements resolve correctly the INSTANT the plan changes (a gated route
 *     flips behaviour on the very next request — no stale cache);
 *   • a per-company override still beats the plan afterwards, because the plan
 *     switch changed the SUBSCRIPTION and not the access engine;
 *   • every change writes an append-only audit row with before → after;
 *   • a downgrade below the seat count is refused unless acknowledged, and when
 *     it is acknowledged nobody is deactivated;
 *   • customers and under-privileged operators cannot reach any of it.
 */
class PlatformSubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    private int $operatorSeq = 0;

    private function operator(string $role = 'admin'): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Operator',
            'email' => $role.(++$this->operatorSeq).'@platform.test',
            'password' => 'operator-secret-pw',
            'role' => $role,
        ]);
    }

    /** @return array{organization: Organization, owner: User} */
    private function tenant(string $name = 'Acme'): array
    {
        return app(TenantProvisioner::class)
            ->provision($name, 'Owner', 'owner@'.strtolower($name).'.test', 'super-secret-pw');
    }

    private function plan(string $key): Plan
    {
        return Plan::where('key', $key)->firstOrFail();
    }

    private function putSubscription(Organization $org, array $payload, ?PlatformUser $as = null)
    {
        return $this->actingAs($as ?: $this->operator(), 'platform')
            ->put(route('platform.subscription.update', $org->id), $payload);
    }

    // -----------------------------------------------------------------------
    // The headline: a plan change re-resolves access immediately
    // -----------------------------------------------------------------------

    public function test_changing_the_plan_flips_a_gated_routes_behaviour_on_the_very_next_request(): void
    {
        ['organization' => $org, 'owner' => $owner] = $this->tenant();

        // Free grants no AI, Growth does. Start on Free.
        $org->subscription->update(['plan_id' => $this->plan('free')->id]);
        $org->refresh();

        // The customer is refused the AI-gated route. This request also POPULATES
        // the cached shared entitlement map with `ai => false`, which is what
        // makes the assertion after the switch meaningful rather than trivial.
        $this->actingAs($owner->fresh())->get(route('ai.assistant'))->assertStatus(403);

        // ...the operator moves them to Growth...
        $this->putSubscription($org, ['plan' => 'pro', 'reason' => 'Upgraded at renewal'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // ...and the very next customer request is allowed. Nothing is warmed,
        // slept on or manually flushed by the test: if the plan switch left a
        // stale entitlement map anywhere, these lines fail.
        $this->actingAs($owner->fresh())
            ->get(route('ai.assistant'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('entitlements.ai', true));

        $this->assertSame('pro', $org->fresh()->activePlan()->key);
    }

    public function test_downgrading_closes_a_gated_route_again(): void
    {
        ['organization' => $org, 'owner' => $owner] = $this->tenant();

        $org->subscription->update(['plan_id' => $this->plan('pro')->id]);
        $org->refresh();

        $this->actingAs($owner->fresh())
            ->get(route('ai.assistant'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('entitlements.ai', true));

        $this->putSubscription($org, ['plan' => 'free'])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($owner->fresh())->get(route('ai.assistant'))->assertStatus(403);

        // The UI's own copy of the map must move with the gate — a lock that is
        // still drawn "unlocked" is the same bug wearing a different hat.
        $this->actingAs($owner->fresh())
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('entitlements.ai', false));
    }

    public function test_a_per_company_override_still_beats_the_new_plan(): void
    {
        // The whole point of "do not create a second access path": moving the
        // company between plans must not disturb the resolution ORDER.
        ['organization' => $org, 'owner' => $owner] = $this->tenant();

        $org->update(['entitlement_overrides' => ['ai' => true]]);
        $org->subscription->update(['plan_id' => $this->plan('pro')->id]);

        // Down to Free, whose plan map says ai = false.
        $this->putSubscription($org->fresh(), ['plan' => 'free'])->assertRedirect()->assertSessionHasNoErrors();

        $org->refresh();
        $this->assertTrue($org->can('ai'), 'the per-company override must outrank the plan');
        $this->assertSame('override', $org->entitlementSource('ai'));
        $this->actingAs($owner)->get(route('ai.assistant'))->assertOk();
    }

    // -----------------------------------------------------------------------
    // Seat safety
    // -----------------------------------------------------------------------

    public function test_downgrading_below_the_seat_count_is_refused_without_an_acknowledgement(): void
    {
        ['organization' => $org] = $this->tenant();
        $org->subscription->update(['plan_id' => $this->plan('pro')->id]); // 50 seats
        $org->refresh();

        $this->seedUsers($org, 4); // 5 seat-consuming users with the owner

        // Free allows 2.
        $this->putSubscription($org, ['plan' => 'free'])
            ->assertSessionHasErrors('plan');

        $this->assertSame('pro', $org->fresh()->activePlan()->key, 'the plan must not have moved');
        $this->assertSame(0, PlatformAuditLog::where('action', PlatformAuditLog::ACTION_TENANT_PLAN_CHANGED)->count());
    }

    public function test_an_acknowledged_downgrade_applies_and_never_deactivates_anyone(): void
    {
        ['organization' => $org] = $this->tenant();
        $org->subscription->update(['plan_id' => $this->plan('pro')->id]);
        $org->refresh();

        $this->seedUsers($org, 4);
        $before = User::withoutOrganizationScope()->where('organization_id', $org->id)->where('is_active', true)->count();

        $this->putSubscription($org, ['plan' => 'free', 'acknowledge_seat_overage' => true, 'reason' => 'Customer chose to downgrade'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $org->refresh();
        $this->assertSame('free', $org->activePlan()->key);

        // The customer keeps every person they had — a seat ceiling is enforced
        // where a seat is CLAIMED, never by switching users off.
        $after = User::withoutOrganizationScope()->where('organization_id', $org->id)->where('is_active', true)->count();
        $this->assertSame($before, $after, 'a downgrade must never deactivate a customer\'s own users');

        // ...and the overage is on the record.
        $log = PlatformAuditLog::where('action', PlatformAuditLog::ACTION_TENANT_PLAN_CHANGED)->latest('id')->first();
        $this->assertNotNull($log->after['seat_overage_acknowledged'] ?? null);
        $this->assertSame(2, $log->after['seat_overage_acknowledged']['limit']);
    }

    public function test_an_override_that_lifts_the_ceiling_means_no_seat_warning_at_all(): void
    {
        // The guard reads the EFFECTIVE ceiling, not the plan's raw number, so a
        // company whose override lifts the ceiling can be moved freely.
        ['organization' => $org] = $this->tenant();
        $org->subscription->update(['plan_id' => $this->plan('pro')->id]);
        $org->update(['entitlement_overrides' => ['max_users' => Entitlements::UNLIMITED]]);
        $org->refresh();

        $this->seedUsers($org, 9);

        $this->putSubscription($org, ['plan' => 'free'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('free', $org->fresh()->activePlan()->key);
    }

    // -----------------------------------------------------------------------
    // Cycle + status
    // -----------------------------------------------------------------------

    public function test_operator_can_flip_the_billing_cycle(): void
    {
        ['organization' => $org] = $this->tenant();

        $this->putSubscription($org, ['billing_cycle' => 'yearly'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('yearly', $org->fresh()->subscription->cycle());
        $this->assertDatabaseHas('platform_audit_logs', ['action' => SubscriptionManager::ACTION_CYCLE_CHANGED]);
    }

    public function test_operator_can_set_the_subscription_status_and_the_org_follows(): void
    {
        ['organization' => $org] = $this->tenant();

        $this->putSubscription($org, ['status' => 'past_due', 'reason' => 'Invoice 12 unpaid'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $org->refresh();
        $this->assertSame('past_due', $org->subscription->status);
        $this->assertSame(Organization::STATUS_PAST_DUE, $org->status);

        $log = PlatformAuditLog::where('action', SubscriptionManager::ACTION_STATUS_CHANGED)->latest('id')->first();
        $this->assertSame('trialing', $log->before['status']);
        $this->assertSame('past_due', $log->after['status']);
        $this->assertSame('Invoice 12 unpaid', $log->reason);
    }

    public function test_cancelling_refuses_the_workspace_without_pretending_it_is_past_due(): void
    {
        ['organization' => $org, 'owner' => $owner] = $this->tenant();

        $this->putSubscription($org, ['status' => 'canceled'])->assertRedirect()->assertSessionHasNoErrors();

        $org->refresh();
        $this->assertSame('canceled', $org->subscription->status);
        $this->assertFalse($org->subscription->isUsable());

        // `canceled` has no organization equivalent, so the org row is left
        // alone rather than mislabelled — access is refused by the subscription.
        $this->assertNotSame(Organization::STATUS_PAST_DUE, $org->status);

        // A browser is sent to billing (EnsureOrganizationIsUsable); an API
        // caller gets the 402 "subscription required" refusal.
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertRedirect(route('billing.index'));
        $this->actingAs($owner->fresh())
            ->getJson(route('time-entries.today'))
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required');
    }

    public function test_trialing_cannot_be_set_from_the_status_control(): void
    {
        // A trial is a DATE as well as a state; offering it in two places is how
        // a status and a trial date drift apart.
        ['organization' => $org] = $this->tenant();

        $this->putSubscription($org, ['status' => 'trialing'])->assertSessionHasErrors('status');
    }

    public function test_a_billing_change_never_un_suspends_a_workspace(): void
    {
        ['organization' => $org] = $this->tenant();
        $org->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->putSubscription($org->fresh(), ['status' => 'active'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(Organization::STATUS_SUSPENDED, $org->fresh()->status);
        $this->assertSame('active', $org->fresh()->subscription->status);
    }

    // -----------------------------------------------------------------------
    // Trial coherence — one concept, not two
    // -----------------------------------------------------------------------

    public function test_extending_a_trial_moves_the_subscription_state_too(): void
    {
        ['organization' => $org] = $this->tenant();
        $org->subscription->update(['status' => 'active']);

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.trial', $org->id), ['action' => 'extend', 'days' => 7])
            ->assertRedirect()->assertSessionHasNoErrors();

        $org->refresh();
        $this->assertSame('trialing', $org->subscription->status);
        $this->assertSame(Organization::STATUS_TRIAL, $org->status);
        $this->assertTrue($org->trial_ends_at->isFuture());
    }

    public function test_ending_a_trial_converts_the_subscription_to_active(): void
    {
        ['organization' => $org] = $this->tenant();

        $this->actingAs($this->operator(), 'platform')
            ->post(route('platform.trial', $org->id), ['action' => 'end'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $org->refresh();
        $this->assertSame('active', $org->subscription->status);
        $this->assertSame(Organization::STATUS_ACTIVE, $org->status);
        $this->assertFalse($org->subscription->onTrial());
    }

    // -----------------------------------------------------------------------
    // The preview
    // -----------------------------------------------------------------------

    public function test_the_preview_names_what_is_gained_and_lost_without_a_second_rule_set(): void
    {
        ['organization' => $org] = $this->tenant();
        $org->subscription->update(['plan_id' => $this->plan('pro')->id]);
        $org->refresh();

        $impact = app(SubscriptionManager::class)->impactOf($org, $this->plan('free'));

        $this->assertContains('ai', array_column($impact['losses'], 'key'));
        $this->assertContains('monitoring', array_column($impact['losses'], 'key'));
        $this->assertSame(2, $impact['seat_limit']);
        $this->assertSame(50, $impact['seat_limit_now']);
        $this->assertSame(7, $impact['retention_days']);
        $this->assertSame(365, $impact['retention_days_now']);
    }

    public function test_a_capability_an_override_grants_is_not_reported_as_a_loss(): void
    {
        ['organization' => $org] = $this->tenant();
        $org->subscription->update(['plan_id' => $this->plan('pro')->id]);
        $org->update(['entitlement_overrides' => ['ai' => true]]);
        $org->refresh();

        $impact = app(SubscriptionManager::class)->impactOf($org, $this->plan('free'));

        $this->assertNotContains('ai', array_column($impact['losses'], 'key'), 'the override keeps granting it, so nothing is lost');
    }

    public function test_a_capability_an_addon_grants_is_not_reported_as_a_loss(): void
    {
        ['organization' => $org] = $this->tenant();
        $org->subscription->update(['plan_id' => $this->plan('pro')->id]);

        $addon = Addon::create([
            'key' => 'ai_pack', 'name' => 'AI pack', 'price_cents' => 300, 'currency' => 'USD',
            'entitlements' => ['ai' => true], 'sort' => 1,
        ]);
        $org->grantAddon($addon);
        $org->refresh();

        $impact = app(SubscriptionManager::class)->impactOf($org, $this->plan('free'));

        $this->assertNotContains('ai', array_column($impact['losses'], 'key'));
    }

    public function test_the_tenant_page_ships_every_plan_including_archived_ones_with_their_impact(): void
    {
        ['organization' => $org] = $this->tenant();
        $this->plan('starter')->update(['archived_at' => now(), 'visibility' => 'legacy']);

        $this->actingAs($this->operator(), 'platform')
            ->get(route('platform.show', $org->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('plans', 4)
                ->where('plans.1.archived', true)
                ->where('plans.1.visibility', 'legacy')
                ->has('planImpact.free.losses')
                ->has('planImpact.starter')
                ->has('subscription.settable_statuses')
            );
    }

    public function test_a_legacy_archived_plan_can_still_be_assigned(): void
    {
        // A customer may legitimately sit on a retired plan; a console that
        // cannot put them back on it cannot correct its own mistakes.
        ['organization' => $org] = $this->tenant();
        $this->plan('starter')->update(['archived_at' => now(), 'visibility' => 'legacy']);

        $this->putSubscription($org, ['plan' => 'starter'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('starter', $org->fresh()->activePlan()->key);
    }

    // -----------------------------------------------------------------------
    // Audit
    // -----------------------------------------------------------------------

    public function test_every_change_is_recorded_with_who_what_when_and_before_after(): void
    {
        ['organization' => $org] = $this->tenant();
        $operator = $this->operator();

        $this->putSubscription($org, [
            'plan' => 'starter',
            'billing_cycle' => 'yearly',
            'status' => 'active',
            'reason' => 'Signed the annual deal',
        ], $operator)->assertRedirect()->assertSessionHasNoErrors();

        $logs = PlatformAuditLog::where('organization_id', $org->id)->get();

        // Three distinct rows, so the History tab reads like a story.
        foreach ([
            PlatformAuditLog::ACTION_TENANT_PLAN_CHANGED,
            SubscriptionManager::ACTION_CYCLE_CHANGED,
            SubscriptionManager::ACTION_STATUS_CHANGED,
        ] as $action) {
            $row = $logs->firstWhere('action', $action);
            $this->assertNotNull($row, "missing audit row for {$action}");
            $this->assertSame($operator->id, $row->platform_user_id);
            $this->assertSame('Operator', $row->actor_name);
            $this->assertSame('Signed the annual deal', $row->reason);
            $this->assertNotNull($row->before);
            $this->assertNotNull($row->after);
            $this->assertNotNull($row->created_at);
        }

        $plan = $logs->firstWhere('action', PlatformAuditLog::ACTION_TENANT_PLAN_CHANGED);
        $this->assertSame('pro', $plan->before['plan']);
        $this->assertSame('starter', $plan->after['plan']);
        $this->assertSame(50, $plan->before['seat_limit']);
        $this->assertSame(10, $plan->after['seat_limit']);
    }

    public function test_a_no_op_change_writes_nothing(): void
    {
        ['organization' => $org] = $this->tenant();
        $current = $org->activePlan()->key;

        $this->putSubscription($org, ['plan' => $current, 'billing_cycle' => 'monthly'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, PlatformAuditLog::where('organization_id', $org->id)->count());
    }

    // -----------------------------------------------------------------------
    // Who may reach it
    // -----------------------------------------------------------------------

    public function test_a_customer_session_can_never_reach_the_subscription_routes(): void
    {
        ['organization' => $org, 'owner' => $owner] = $this->tenant();
        $owner->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($owner) // web guard, even with the legacy flag
            ->put(route('platform.subscription.update', $org->id), ['plan' => 'enterprise'])
            ->assertRedirect(route('platform.login'));

        $this->assertSame('pro', $org->fresh()->activePlan()->key);
    }

    public function test_a_guest_cannot_reach_the_subscription_routes(): void
    {
        ['organization' => $org] = $this->tenant();

        $this->put(route('platform.subscription.update', $org->id), ['plan' => 'free'])
            ->assertRedirect(route('platform.login'));
    }

    public function test_support_operators_cannot_change_a_subscription(): void
    {
        ['organization' => $org] = $this->tenant();

        $this->putSubscription($org, ['plan' => 'free'], $this->operator('support'))->assertStatus(403);

        $this->assertSame('pro', $org->fresh()->activePlan()->key);
    }

    public function test_an_unknown_plan_key_is_rejected(): void
    {
        ['organization' => $org] = $this->tenant();

        $this->putSubscription($org, ['plan' => 'gold-tier'])->assertSessionHasErrors('plan');
        $this->putSubscription($org, ['billing_cycle' => 'weekly'])->assertSessionHasErrors('billing_cycle');
        $this->putSubscription($org, ['status' => 'exploded'])->assertSessionHasErrors('status');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Add N ordinary, seat-consuming users to a workspace. */
    private function seedUsers(Organization $org, int $count): void
    {
        app(CurrentOrganization::class)->runFor($org, function () use ($count) {
            for ($i = 1; $i <= $count; $i++) {
                User::create([
                    'name' => "Person {$i}",
                    'email' => "person{$i}@seatcount.test",
                    'password' => Hash::make('a-very-secret-password'),
                    'role' => 'employee',
                ]);
            }
        });
    }
}
