<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    /** Put an org on a named plan (active subscription). */
    private function onPlan(Organization $org, string $planKey): void
    {
        $plan = Plan::where('key', $planKey)->first();
        app(CurrentOrganization::class)->runFor($org, fn () => Subscription::updateOrCreate(
            ['organization_id' => $org->id],
            ['plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addMonth()],
        ));
        $org->refresh();
    }

    public function test_plan_caps_resolve_per_tier(): void
    {
        Plan::ensureSeeded();
        $org = Organization::create(['name' => 'Cap Co', 'slug' => 'cap-co']);

        $this->onPlan($org, 'free');
        $this->assertSame(6, $org->entitlement('max_screenshots_per_hour'));
        $this->assertSame(10, $org->entitlement('min_auto_pause_minutes'));
        $this->assertTrue($org->can('monitoring'));   // free now gets capped monitoring
        $this->assertFalse($org->can('activity_tracking'));
        $this->assertFalse($org->can('ai'));
        $this->assertSame(3, $org->seatLimit());

        $this->onPlan($org, 'pro');
        $this->assertSame(30, $org->entitlement('max_screenshots_per_hour'));
        $this->assertSame(1, $org->entitlement('min_auto_pause_minutes'));
        $this->assertTrue($org->can('ai'));
        $this->assertTrue($org->can('white_label'));
        $this->assertSame(50, $org->seatLimit());

        $this->onPlan($org, 'enterprise');
        $this->assertNull($org->seatLimit()); // unlimited (explicit null, not deny-default)
    }

    public function test_no_plan_is_default_deny(): void
    {
        Plan::ensureSeeded();
        $org = Organization::create(['name' => 'Bare Co', 'slug' => 'bare-co']);

        $this->assertFalse($org->can('monitoring'));
        $this->assertFalse($org->can('ai'));
        $this->assertFalse($org->can('white_label'));
        $this->assertSame(0, $org->entitlement('max_screenshots_per_hour'));
        $this->assertSame(1, $org->seatLimit()); // registry default, not unlimited
    }

    public function test_per_tenant_override_raises_or_grants(): void
    {
        Plan::ensureSeeded();
        $org = Organization::create(['name' => 'Special Co', 'slug' => 'special-co']);
        $this->onPlan($org, 'free');

        // The platform grants this single tenant a higher cap + a flag the plan lacks.
        $org->update(['entitlement_overrides' => [
            'max_screenshots_per_hour' => 50,
            'ai' => true,
        ]]);
        $org->refresh();

        $this->assertSame(50, $org->entitlement('max_screenshots_per_hour'));
        $this->assertTrue($org->can('ai'));
        // Untouched keys still follow the plan.
        $this->assertSame(3, $org->seatLimit());
        $this->assertFalse($org->can('white_label'));
    }

    public function test_per_tenant_override_can_lower_a_cap(): void
    {
        Plan::ensureSeeded();
        $org = Organization::create(['name' => 'Trim Co', 'slug' => 'trim-co']);
        $this->onPlan($org, 'pro');

        $org->update(['entitlement_overrides' => ['max_users' => 5]]);
        $org->refresh();

        $this->assertSame(5, $org->seatLimit()); // lowered below the Pro default of 50
    }

    public function test_custom_price_wins_over_plan_price(): void
    {
        Plan::ensureSeeded();
        $org = Organization::create(['name' => 'Deal Co', 'slug' => 'deal-co']);
        $this->onPlan($org, 'pro');

        $this->assertSame(800, $org->priceCents());

        $org->update(['custom_price_cents' => 500]);
        $org->refresh();
        $this->assertSame(500, $org->priceCents());
    }

    // --- Server-side enforcement (default-deny, not just a hidden UI) ---------

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'screenshots_per_hour' => 6,
            'blur_screenshots' => false,
            'capture_enabled' => true,
            'activity_tracking_enabled' => false,
            'app_url_tracking_enabled' => false,
            'weekly_time_limit_hours' => null,
            'auto_pause_minutes' => 10,
            'allow_offline_time' => false,
            'notify_on_screenshot' => false,
            'week_starts_on' => 'monday',
            'currency_symbol' => '$',
            'desktop_auto_start' => true,
            'desktop_force_quit_on_idle' => false,
            'display_timezone' => 'Asia/Karachi',
            'time_format' => '12',
            'slack_clockin_enabled' => false,
            'slack_clockout_enabled' => false,
            'slack_attendance_channel' => null,
        ], $overrides);
    }

    private function freeOwner(): User
    {
        $owner = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];
        $this->onPlan($owner->organization, 'free');

        return $owner;
    }

    public function test_free_org_can_save_within_plan(): void
    {
        $owner = $this->freeOwner();

        $this->actingAs($owner)
            ->put(route('settings.team.update'), $this->basePayload())
            ->assertSessionHasNoErrors();
    }

    public function test_free_org_rejected_over_screenshot_cap(): void
    {
        $owner = $this->freeOwner();

        $this->actingAs($owner)
            ->put(route('settings.team.update'), $this->basePayload(['screenshots_per_hour' => 20]))
            ->assertSessionHasErrors('screenshots_per_hour');
    }

    public function test_free_org_rejected_for_gated_flags_and_pause_floor(): void
    {
        $owner = $this->freeOwner();

        $this->actingAs($owner)
            ->put(route('settings.team.update'), $this->basePayload(['activity_tracking_enabled' => true]))
            ->assertSessionHasErrors('activity_tracking_enabled');

        $this->actingAs($owner)
            ->put(route('settings.team.update'), $this->basePayload(['auto_pause_minutes' => 3]))
            ->assertSessionHasErrors('auto_pause_minutes');

        $this->actingAs($owner)
            ->put(route('settings.team.update'), $this->basePayload(['slack_clockin_enabled' => true]))
            ->assertSessionHasErrors('slack_clockin_enabled');
    }

    public function test_pro_org_allows_higher_rate_but_still_capped(): void
    {
        $owner = app(TenantProvisioner::class)->provision('Pro Co', 'Owner', 'owner@proco.test', 'super-secret-pw')['owner'];
        $this->onPlan($owner->organization, 'pro');

        $this->actingAs($owner)
            ->put(route('settings.team.update'), $this->basePayload(['screenshots_per_hour' => 30, 'activity_tracking_enabled' => true, 'auto_pause_minutes' => 2]))
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->put(route('settings.team.update'), $this->basePayload(['screenshots_per_hour' => 45]))
            ->assertSessionHasErrors('screenshots_per_hour');
    }
}
