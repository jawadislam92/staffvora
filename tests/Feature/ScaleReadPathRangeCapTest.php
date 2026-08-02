<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TimeEntry;
use App\Models\TrackingActivitySample;
use App\Models\TrackingSession;
use App\Models\User;
use App\Models\WorkHour;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Regression cover for the read-path scale fixes (audit findings high-15,
 * high-16, high-17, high-21).
 *
 * The abuse these guard against is a single GET — `?start=2000-01-01&end=
 * 2030-12-31` — reachable by any manager, which used to hydrate the whole
 * tenant's punch/sample history into PHP memory and OOM the FPM worker (and,
 * on shared infrastructure, its neighbours). The cap must hold on every
 * consumer of each range resolver, and the sargable date predicates must not
 * have silently dropped the boundary days while making the indexes usable.
 */
class ScaleReadPathRangeCapTest extends TestCase
{
    use RefreshDatabase;

    /** Inclusive calendar-day ceiling enforced by both range resolvers. */
    private const MAX_RANGE_DAYS = 92;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function organization(string $tz = 'UTC'): Organization
    {
        Plan::ensureSeeded();

        $organization = Organization::factory()->timezone($tz)->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);

        // 'pro' (not 'free') because /team/apps sits behind
        // entitlement:app_url_tracking — same plan the user factory provisions.
        $plan = Plan::where('key', 'pro')->first() ?? Plan::orderBy('sort')->firstOrFail();

        app(CurrentOrganization::class)->runFor($organization, fn () => Subscription::create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]));

        return $organization;
    }

    private function manager(Organization $organization): User
    {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'admin',
            'permissions' => ['timeline.view_others', 'dashboard.view_team', 'attendance.view'],
        ]);
    }

    private function punch(User $user, string $date, string $time, string $type): void
    {
        TimeEntry::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'action_type' => $type,
            'action_timestamp' => Carbon::parse("{$date} {$time}", 'UTC'),
            'action_date' => $date,
            'action_time' => $time,
        ]);
    }

    // ---------------------------------------------------------------------
    // Range caps
    // ---------------------------------------------------------------------

    public function test_team_page_clamps_a_decade_long_custom_range(): void
    {
        $org = $this->organization();

        $this->actingAs($this->manager($org))
            ->get('/team?start=2020-01-01&end=2030-01-01')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $start = Carbon::parse($page->toArray()['props']['start']);
                $end = Carbon::parse($page->toArray()['props']['end']);

                $this->assertSame(
                    self::MAX_RANGE_DAYS,
                    (int) $start->diffInDays($end) + 1,
                    'Team page must clamp an oversized custom range.'
                );
            });
    }

    public function test_team_apps_page_clamps_a_decade_long_custom_range(): void
    {
        $org = $this->organization();

        $this->actingAs($this->manager($org))
            ->get('/team/apps?range=custom&start=2020-01-01&end=2030-01-01')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $start = Carbon::parse($page->toArray()['props']['start']);
                $end = Carbon::parse($page->toArray()['props']['end']);

                $this->assertSame(
                    self::MAX_RANGE_DAYS,
                    (int) $start->diffInDays($end) + 1,
                    'Team Apps page must clamp an oversized custom range.'
                );
            });
    }

    public function test_team_page_repairs_an_inverted_custom_range(): void
    {
        $org = $this->organization();

        $this->actingAs($this->manager($org))
            ->get('/team?start=2026-06-30&end=2026-06-01')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('start', '2026-06-01')
                ->where('end', '2026-06-30')
            );
    }

    public function test_team_activity_clamps_a_decade_long_custom_range(): void
    {
        $org = $this->organization();

        $payload = $this->actingAs($this->manager($org))
            ->getJson('/time-entries/team-activity?start=2000-01-01&end=2030-12-31')
            ->assertOk()
            ->json();

        $start = Carbon::parse($payload['start']);
        $end = Carbon::parse($payload['end']);

        $this->assertSame(
            self::MAX_RANGE_DAYS,
            (int) $start->diffInDays($end) + 1,
            'team-activity must clamp an oversized custom range.'
        );
    }

    public function test_team_activity_rejects_an_unparseable_date(): void
    {
        $org = $this->organization();

        $this->actingAs($this->manager($org))
            ->getJson('/time-entries/team-activity?start=not-a-date&end=also-not')
            ->assertStatus(422);
    }

    public function test_team_activity_rejects_an_unknown_named_range(): void
    {
        $org = $this->organization();

        $this->actingAs($this->manager($org))
            ->getJson('/time-entries/team-activity?range=everything')
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // The sargable predicates must not lose the boundary days
    // ---------------------------------------------------------------------

    public function test_dashboard_trend_keeps_both_boundary_days_of_the_window(): void
    {
        $org = $this->organization();
        $manager = $this->manager($org);

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00', 'UTC'));

        // The chart window is the last 14 days: 2026-06-07 .. 2026-06-20.
        // Half-open `>= first` / `< last + 1 day` must include BOTH ends —
        // an inclusive `<= last` string comparison drops rows that SQLite
        // persisted through the `date` cast as "2026-06-20 00:00:00".
        $this->punch($manager, '2026-06-07', '09:00:00', 'clock_in');
        $this->punch($manager, '2026-06-07', '17:00:00', 'clock_out');
        $this->punch($manager, '2026-06-20', '09:00:00', 'clock_in');
        $this->punch($manager, '2026-06-20', '11:00:00', 'clock_out');

        // Just outside the window on the low side — must NOT be counted.
        $this->punch($manager, '2026-06-06', '09:00:00', 'clock_in');
        $this->punch($manager, '2026-06-06', '17:00:00', 'clock_out');

        $series = collect(
            $this->actingAs($manager)
                ->getJson('/time-entries/dashboard-trend')
                ->assertOk()
                ->json('series')
        )->keyBy('date');

        $this->assertCount(14, $series);
        $this->assertSame(8.0, (float) $series['2026-06-07']['hours']);
        $this->assertSame(2.0, (float) $series['2026-06-20']['hours']);
        $this->assertArrayNotHasKey('2026-06-06', $series->all());
    }

    public function test_team_activity_aggregates_hours_without_hydrating_the_range(): void
    {
        $org = $this->organization();
        $manager = $this->manager($org);
        $worker = User::factory()->create(['organization_id' => $org->id, 'role' => 'member']);

        Carbon::setTestNow(Carbon::parse('2026-06-20 23:00:00', 'UTC'));

        // Two attendance days of punches — 8h then 4h net.
        $this->punch($worker, '2026-06-19', '09:00:00', 'clock_in');
        $this->punch($worker, '2026-06-19', '17:00:00', 'clock_out');
        $this->punch($worker, '2026-06-20', '09:00:00', 'clock_in');
        $this->punch($worker, '2026-06-20', '13:00:00', 'clock_out');

        // Work-diary hours are now SUM()'d in SQL rather than hydrated.
        WorkHour::create([
            'organization_id' => $org->id,
            'user_id' => $worker->id,
            'date' => '2026-06-19',
            'hours' => 5.5,
        ]);
        WorkHour::create([
            'organization_id' => $org->id,
            'user_id' => $worker->id,
            // The inclusive end of the range: an inclusive whereBetween upper
            // bound used to drop this row on SQLite.
            'date' => '2026-06-20',
            'hours' => 2.5,
        ]);

        // Activity % is now SUM(activity_percent * total_seconds)/SUM(
        // total_seconds) in SQL — 3600s @ 80% and 1200s @ 40% => 70%.
        TrackingSession::create([
            'organization_id' => $org->id,
            'user_id' => $worker->id,
            'client_uuid' => 'uuid-scale-a',
            'started_at' => Carbon::parse('2026-06-20 09:00:00', 'UTC'),
            'stopped_at' => Carbon::parse('2026-06-20 10:00:00', 'UTC'),
            'total_seconds' => 3600,
            'activity_percent' => 80,
            'status' => 'stopped',
            'source' => 'desktop',
        ]);
        TrackingSession::create([
            'organization_id' => $org->id,
            'user_id' => $worker->id,
            'client_uuid' => 'uuid-scale-b',
            'started_at' => Carbon::parse('2026-06-20 10:00:00', 'UTC'),
            'stopped_at' => Carbon::parse('2026-06-20 10:20:00', 'UTC'),
            'total_seconds' => 1200,
            'activity_percent' => 40,
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        $row = collect(
            $this->actingAs($manager)
                ->getJson('/time-entries/team-activity?start=2026-06-19&end=2026-06-20')
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $worker->id);

        $this->assertNotNull($row, 'The worker must appear in the team-activity feed.');
        // 8h on the 19th + 4h on the 20th, computed per user-day by the state
        // machine (which is why punches are batched by user, never chunked by id).
        $this->assertSame((int) round(12.0 * 3600), $row['in_office_seconds']);
        $this->assertSame((int) round(8.0 * 3600), $row['tracked_seconds']);
        $this->assertSame(2, $row['days_worked']);
        $this->assertSame(70, $row['activity_percent']);
    }

    // ---------------------------------------------------------------------
    // The apps rollup moved into SQL — it must still fold domain variants
    // ---------------------------------------------------------------------

    public function test_team_apps_still_folds_www_and_bare_domain_variants(): void
    {
        $org = $this->organization();
        $manager = $this->manager($org);
        $worker = User::factory()->create(['organization_id' => $org->id, 'role' => 'member']);

        $session = TrackingSession::create([
            'organization_id' => $org->id,
            'user_id' => $worker->id,
            'client_uuid' => 'uuid-scale-domains',
            'started_at' => now()->subHour(),
            'total_seconds' => 3600,
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        // Grouping in SQL on the raw column would split these into two rows;
        // the PHP fold over the (already small) grouped rows must merge them.
        foreach (['www.youtube.com', 'YouTube.com', 'youtube.com'] as $i => $domain) {
            TrackingActivitySample::create([
                'organization_id' => $org->id,
                'tracking_session_id' => $session->id,
                'user_id' => $worker->id,
                'captured_at' => now()->subMinutes($i + 1),
                'keyboard_count' => 1,
                'mouse_count' => 1,
                'idle_seconds' => 0,
                'active_app' => 'Google Chrome',
                'url_domain' => $domain,
            ]);
        }

        $this->actingAs($manager)
            ->get('/team/apps?range=today')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Team/Apps')
                ->count('urls', 1)
                ->where('urls.0.name', 'youtube.com')
                ->where('urls.0.user_count', 1)
                ->where('urls.0.top_user', $worker->name)
            );
    }
}
