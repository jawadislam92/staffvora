<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The entitlement refusal now renders the Upgrade/Locked Inertia page, a NEW
     * page component that is not in the committed Vite manifest until the next
     * asset build. These tests assert the SERVER decision, not the bundle, so
     * stub Vite out rather than making the suite depend on a build step.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

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

        // Free is time tracking only — no monitoring at all.
        $this->onPlan($org, 'free');
        $this->assertSame(0, $org->entitlement('max_screenshots_per_hour'));
        $this->assertSame(10, $org->entitlement('min_auto_pause_minutes'));
        $this->assertFalse($org->can('monitoring'));
        $this->assertFalse($org->can('activity_tracking'));
        $this->assertFalse($org->can('ai'));
        $this->assertSame(2, $org->seatLimit());

        // Screenshots + activity start at Starter, but app/URL tracking does not.
        $this->onPlan($org, 'starter');
        $this->assertSame(12, $org->entitlement('max_screenshots_per_hour'));
        $this->assertTrue($org->can('monitoring'));
        $this->assertTrue($org->can('activity_tracking'));
        $this->assertFalse($org->can('app_url_tracking'));
        $this->assertFalse($org->can('white_label'));
        $this->assertSame(10, $org->seatLimit());

        // Growth (key `pro` — nothing may branch on the key).
        $this->onPlan($org, 'pro');
        $this->assertSame(30, $org->entitlement('max_screenshots_per_hour'));
        $this->assertSame(1, $org->entitlement('min_auto_pause_minutes'));
        $this->assertTrue($org->can('app_url_tracking'));
        $this->assertTrue($org->can('ai'));
        $this->assertTrue($org->can('white_label'));
        $this->assertSame(50, $org->seatLimit());

        $this->onPlan($org, 'enterprise');
        $this->assertNull($org->seatLimit()); // unlimited (explicit null, not deny-default)
        $this->assertNull($org->entitlement('max_retention_days'));
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
        $this->assertSame(2, $org->seatLimit());
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

        $this->assertSame(900, $org->priceCents());

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

    /** Starter is the cheapest plan that includes monitoring at all (capped at 12/hr). */
    private function starterOwner(): User
    {
        $owner = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];
        $this->onPlan($owner->organization, 'starter');

        return $owner;
    }

    public function test_starter_org_can_save_within_plan(): void
    {
        $owner = $this->starterOwner();

        $this->actingAs($owner)
            ->put(route('settings.team.update'), $this->basePayload())
            ->assertSessionHasNoErrors();
    }

    public function test_free_org_cannot_turn_on_monitoring_at_all(): void
    {
        $owner = $this->freeOwner();

        // Free is time tracking only — asking for ANY capture is rejected.
        $this->actingAs($owner)
            ->put(route('settings.team.update'), $this->basePayload())
            ->assertSessionHasErrors('screenshots_per_hour');
    }

    public function test_starter_org_rejected_over_screenshot_cap(): void
    {
        $owner = $this->starterOwner();

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

    public function test_free_org_cannot_call_paid_ai_or_activity_endpoints(): void
    {
        $owner = $this->freeOwner();

        $this->actingAs($owner)
            ->get(route('ai.assistant'))
            ->assertForbidden();

        Sanctum::actingAs($owner, ['desktop-tracker']);
        $this->postJson(route('desktop.activity.batch'), [])
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_not_in_plan')
            ->assertJsonPath('feature', 'activity_tracking');
    }

    public function test_desktop_settings_are_clamped_to_the_effective_plan(): void
    {
        $owner = $this->freeOwner();
        Sanctum::actingAs($owner, ['desktop-tracker']);

        $this->getJson(route('desktop.settings'))
            ->assertOk()
            // Free grants no monitoring, so the desktop app is clamped to zero.
            ->assertJsonPath('settings.screenshots_per_hour', 0)
            ->assertJsonPath('settings.activity_tracking_enabled', false)
            ->assertJsonPath('settings.app_url_tracking_enabled', false)
            ->assertJsonPath('settings.slack_connected', false);
    }

    public function test_screenshot_upload_strips_unentitled_metadata_and_enforces_hourly_cap(): void
    {
        Bus::fake();
        Storage::fake('screenshots');
        // Starter includes monitoring; the operator has then revoked activity
        // tracking for this one company and lowered its rate to 6/hour, so this
        // also proves a per-company override can restrict below the plan.
        $owner = $this->starterOwner();
        $owner->organization->update(['entitlement_overrides' => [
            'activity_tracking' => false,
            'max_screenshots_per_hour' => 6,
        ]]);
        app(CurrentOrganization::class)->set($owner->organization_id);
        Sanctum::actingAs($owner, ['desktop-tracker']);

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'started_at' => now(),
            'status' => TrackingSession::STATUS_ACTIVE,
            'client_uuid' => 'entitlement-cap-session',
        ]);
        $capturedAt = now()->startOfHour()->addMinutes(5);

        $this->postJson(route('desktop.screenshots.store'), [
            'tracking_session_id' => $session->id,
            'captured_at' => $capturedAt->toIso8601String(),
            'image' => UploadedFile::fake()->image('first.jpg'),
            'activity_percent' => 99,
            'keyboard_count' => 100,
            'mouse_count' => 200,
            'active_app' => 'Private App',
            'active_window_title' => 'Sensitive title',
            'url_domain' => 'secret.example',
        ])->assertCreated();

        $stored = TrackingScreenshot::firstOrFail();
        $this->assertSame(0, $stored->activity_percent);
        $this->assertSame(0, $stored->keyboard_count);
        $this->assertSame(0, $stored->mouse_count);
        $this->assertNull($stored->active_app);
        $this->assertNull($stored->active_window_title);
        $this->assertNull($stored->url_domain);

        foreach (range(2, 6) as $minute) {
            TrackingScreenshot::create([
                'tracking_session_id' => $session->id,
                'user_id' => $owner->id,
                'captured_at' => $capturedAt->copy()->addMinutes($minute),
                'image_path' => "screenshots/test-{$minute}.jpg",
            ]);
        }

        $this->postJson(route('desktop.screenshots.store'), [
            'tracking_session_id' => $session->id,
            'captured_at' => $capturedAt->copy()->addMinutes(20)->toIso8601String(),
            'image' => UploadedFile::fake()->image('seventh.jpg'),
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'screenshot_limit_reached')
            ->assertJsonPath('limit', 6);
    }
}
