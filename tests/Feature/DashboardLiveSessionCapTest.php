<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TrackingSession;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for audit customer-app B2 — the dashboard inventing phantom
 * hours from tracking sessions that were abandoned days ago.
 *
 * Two guarantees:
 *  - STALENESS: a session that stopped heartbeating (an app that died) no longer
 *    reads as "live", so it contributes nothing to today's tracked hours and the
 *    person drops off the today-summary the way /timesheets, /timeline and
 *    attendance already show them.
 *  - CAP: a genuinely live session that has been open a very long time is capped
 *    at 18h (the forgotten-clock-out ceiling used elsewhere), not 16h, and is
 *    clamped to the part of the session that lands on today.
 */
class DashboardLiveSessionCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 20:00 local → "time since midnight" is 20h, past the 18h cap, so the
        // cap is actually exercised rather than time-of-day masking it.
        Carbon::setTestNow(Carbon::parse('2026-06-15 20:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function organization(string $tz = 'Asia/Karachi'): Organization
    {
        Plan::ensureSeeded();

        $organization = Organization::factory()->timezone($tz)->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);

        $plan = Plan::where('key', 'pro')->first() ?? Plan::orderBy('sort')->firstOrFail();

        app(CurrentOrganization::class)->runFor($organization, fn () => Subscription::create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]));

        return $organization;
    }

    private function admin(Organization $organization): User
    {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'super_admin',
        ]);
    }

    private function rowFor(User $admin, int $userId): ?array
    {
        return collect(
            $this->actingAs($admin)
                ->getJson(route('time-entries.today-summary'))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $userId);
    }

    public function test_an_abandoned_session_contributes_no_phantom_hours(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);

        $worker = User::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Ghost Session Worker',
        ]);

        // Status still 'active', but the app last spoke six days ago — exactly
        // the shape monitoring:close-stale-sessions would sweep. No punches today.
        TrackingSession::create([
            'organization_id' => $org->id,
            'user_id' => $worker->id,
            'client_uuid' => 'abandoned-1',
            'started_at' => now()->subDays(6),
            'last_heartbeat_at' => now()->subDays(6),
            'status' => TrackingSession::STATUS_ACTIVE,
            'total_seconds' => 3600,
            'source' => 'desktop',
        ]);

        // Before the fix this worker appeared with is_live=true and ~16h of
        // "time since midnight" invented out of nothing. Now they are gone.
        $this->assertNull(
            $this->rowFor($admin, $worker->id),
            'An abandoned session must not read as live on the dashboard.'
        );
    }

    public function test_a_genuinely_live_long_session_is_capped_at_18h_and_clamped_to_today(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);

        $worker = User::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Marathon Worker',
        ]);

        // Started 30h ago (yesterday) but still heartbeating now — a real, if
        // forgotten, open session. Contribution = min(18h cap, 30h wall, 20h
        // since-midnight) = 18h.
        TrackingSession::create([
            'organization_id' => $org->id,
            'user_id' => $worker->id,
            'client_uuid' => 'live-long-1',
            'started_at' => now()->subHours(30),
            'last_heartbeat_at' => now()->subMinutes(1),
            'status' => TrackingSession::STATUS_ACTIVE,
            'total_seconds' => 3600,
            'source' => 'desktop',
        ]);

        $row = $this->rowFor($admin, $worker->id);

        $this->assertNotNull($row, 'A live session must still be listed.');
        $this->assertTrue($row['is_live']);
        // 18h, not the old 16h, and not the raw 20h since-midnight.
        $this->assertSame(18.0, (float) $row['tracked_hours']);
    }
}
