<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TimeEntry;
use App\Models\TrackingSession;
use App\Models\User;
use App\Models\WorkHour;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The dashboard's `kpiTrends` prop — the sparkline series + window deltas
 * behind the KPI cards.
 *
 * What is pinned here, and why:
 *
 *  - SHAPE: every series is EXACTLY 14 points, oldest → newest, with silent
 *    days as real zeros — a sparkline component receives a fixed-length dense
 *    array, never a sparse one it has to repair.
 *  - HONESTY: the daily hours series must agree with an independent SUM over
 *    work_hours for the same window. A sparkline that disagrees with the big
 *    number beside it is worse than no sparkline.
 *  - DELTAS: up/down/flat and the good flag, including the both-windows-zero
 *    case and break time staying neutral (good: null) whichever way it moves.
 *  - ACCESS: a member gets NO kpiTrends — the series are team totals, and a
 *    member's dashboard must not grow a new query cost.
 *  - TENANCY: another organization's punches/hours/sessions never move this
 *    org's series.
 *  - COST: the whole block adds at most 5 queries to a cold-cache dashboard.
 */
class DashboardKpiTrendsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Frozen mid-evening so every same-day punch in these tests is in the
     * past. Asia/Karachi matches config('app.timezone') in this environment,
     * so stored wall-clock and business wall-clock line up.
     */
    private const NOW = '2026-08-04 18:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'Asia/Karachi'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function organization(): Organization
    {
        Plan::ensureSeeded();

        $organization = Organization::factory()->timezone('Asia/Karachi')->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);

        $plan = Plan::where('key', 'pro')->first() ?? Plan::orderBy('sort')->firstOrFail();

        app(CurrentOrganization::class)->runFor($organization, fn () => Subscription::create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]));

        // Bind the tenant so every model created below is stamped with it,
        // exactly as the Authenticated listener would during a request.
        app(CurrentOrganization::class)->set($organization);

        return $organization;
    }

    private function admin(Organization $organization): User
    {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'super_admin',
        ]);
    }

    private function member(Organization $organization): User
    {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'member',
            'permissions' => [],
        ]);
    }

    private function punch(User $user, string $date, string $time, string $type): void
    {
        TimeEntry::create([
            'user_id' => $user->id,
            'action_type' => $type,
            'action_timestamp' => Carbon::parse("{$date} {$time}", 'Asia/Karachi'),
            'action_date' => $date,
            'action_time' => $time,
        ]);
    }

    private function workHour(User $user, string $date, float $hours, array $extra = []): WorkHour
    {
        return WorkHour::create(array_merge([
            'user_id' => $user->id,
            'date' => $date,
            'hours' => $hours,
            'description' => 'test entry',
        ], $extra));
    }

    /**
     * A finished tracker session mirrored into work_hours for one day — the
     * shape TrackingSessionService::syncWorkHour writes.
     */
    private function trackedDay(User $user, string $date, float $hours, int $activityPercent, string $uuid): void
    {
        $session = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => $uuid,
            'started_at' => Carbon::parse("{$date} 09:00:00", 'Asia/Karachi'),
            'stopped_at' => Carbon::parse("{$date} 17:00:00", 'Asia/Karachi'),
            'total_seconds' => (int) round($hours * 3600),
            'activity_percent' => $activityPercent,
            'status' => TrackingSession::STATUS_STOPPED,
            'source' => 'desktop',
        ]);

        $this->workHour($user, $date, $hours, [
            'tracking_session_id' => $session->id,
            'source' => WorkHour::SOURCE_TRACKER,
        ]);
    }

    /** The kpiTrends prop off a dashboard response (null when absent). */
    private function trends($response): ?array
    {
        return $response->viewData('page')['props']['kpiTrends'] ?? null;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today('Asia/Karachi');
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shape: dense, ordered, exactly 14 points
    // ─────────────────────────────────────────────────────────────────────

    public function test_every_series_is_exactly_14_points_zero_filled_and_ordered(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);
        $worker = User::factory()->create(['organization_id' => $org->id]);

        $today = $this->today()->toDateString();
        $fiveDaysAgo = $this->today()->subDays(5)->toDateString();

        // Today: a manual entry, a tracked (mirrored) session at 80% activity,
        // and a full punch day with one 30-minute break.
        $this->workHour($worker, $today, 2.5);
        $this->trackedDay($worker, $today, 2.0, 80, 'uuid-today');
        $this->punch($worker, $today, '09:00:00', 'clock_in');
        $this->punch($worker, $today, '13:00:00', 'break_start');
        $this->punch($worker, $today, '13:30:00', 'break_end');
        $this->punch($worker, $today, '17:00:00', 'clock_out');

        // One lone manual entry five days ago; everything else silent.
        $this->workHour($worker, $fiveDaysAgo, 1.25);

        $trends = $this->trends(
            $this->actingAs($admin)->get(route('dashboard'))->assertOk()
        );

        $this->assertNotNull($trends);
        $this->assertSame(14, $trends['days']);

        foreach (['hours', 'active', 'activity', 'breakMinutes'] as $key) {
            $this->assertCount(14, $trends['series'][$key], "series [{$key}] must always be 14 points");
        }

        // Oldest → newest: today is the LAST point, 5 days ago is index 8,
        // and every untouched day is a real zero, not a missing element.
        $hours = array_fill(0, 14, 0.0);
        $hours[13] = 4.5;   // 2.5 manual + 2.0 tracked
        $hours[8] = 1.25;
        $this->assertSame($hours, $trends['series']['hours']);

        $active = array_fill(0, 14, 0);
        $active[13] = 1;
        $this->assertSame($active, $trends['series']['active']);

        $activity = array_fill(0, 14, 0.0);
        $activity[13] = 80.0;
        $this->assertSame($activity, $trends['series']['activity']);

        $breaks = array_fill(0, 14, 0);
        $breaks[13] = 30;
        $this->assertSame($breaks, $trends['series']['breakMinutes']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Honesty: the hours series must agree with the table it summarises
    // ─────────────────────────────────────────────────────────────────────

    public function test_daily_hours_sum_equals_an_independent_work_hour_sum_for_the_window(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);
        $worker = User::factory()->create(['organization_id' => $org->id]);

        $today = $this->today();

        // Both window EDGES (today and today-13), a mid-window day, a tracked
        // mirror row, and one row just OUTSIDE the visible window (today-14,
        // which belongs to the comparison window and must NOT be in the
        // series sum).
        $this->workHour($worker, $today->toDateString(), 3.75);
        $this->workHour($worker, $today->subDays(13)->toDateString(), 1.5);
        $this->workHour($worker, $today->subDays(6)->toDateString(), 2.25);
        $this->trackedDay($worker, $today->subDays(2)->toDateString(), 2.0, 60, 'uuid-mid');
        $this->workHour($worker, $today->subDays(14)->toDateString(), 9.0);

        $trends = $this->trends(
            $this->actingAs($admin)->get(route('dashboard'))->assertOk()
        );

        // Independent recomputation, straight off the table with no Eloquent
        // scope involved.
        $independent = (float) DB::table('work_hours')
            ->where('organization_id', $org->id)
            ->where('date', '>=', $today->subDays(13)->toDateString())
            ->where('date', '<=', $today->toDateString())
            ->sum('hours');

        $this->assertEqualsWithDelta(
            $independent,
            array_sum($trends['series']['hours']),
            0.001,
            'The 14-day hours series must sum to the same total as the work_hours table for that window.'
        );

        $this->assertEqualsWithDelta(9.5, $independent, 0.001, 'seed sanity: 3.75 + 1.5 + 2.25 + 2.0');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Deltas
    // ─────────────────────────────────────────────────────────────────────

    public function test_deltas_compare_the_two_windows_including_neutral_break_time(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);
        $worker = User::factory()->create(['organization_id' => $org->id]);

        $today = $this->today();
        $curDay = $today->subDays(10)->toDateString();   // inside the last 14 days
        $prevDay = $today->subDays(20)->toDateString();  // inside the 14 before

        // hours: 10h before, 15h now → +50%, up, and up is good.
        $this->workHour($worker, $prevDay, 10.0);
        $this->workHour($worker, $curDay, 15.0);

        // active + breaks: punches ONLY in the current window → from a zero
        // window, which must read as a 100% up move, not a division by zero.
        $this->punch($worker, $curDay, '09:00:00', 'clock_in');
        $this->punch($worker, $curDay, '13:00:00', 'break_start');
        $this->punch($worker, $curDay, '13:30:00', 'break_end');
        $this->punch($worker, $curDay, '17:00:00', 'clock_out');

        $trends = $this->trends(
            $this->actingAs($admin)->get(route('dashboard'))->assertOk()
        );

        $this->assertSame(
            ['pct' => 50.0, 'direction' => 'up', 'good' => true],
            $trends['deltas']['hours']
        );

        $this->assertSame(
            ['pct' => 100.0, 'direction' => 'up', 'good' => true],
            $trends['deltas']['active']
        );

        // No tracked sessions anywhere: both windows zero → flat, muted.
        $this->assertSame(
            ['pct' => 0.0, 'direction' => 'flat', 'good' => null],
            $trends['deltas']['activity']
        );

        // Break time moved up 100%, but more rest is NOT bad: good stays
        // null so the UI renders it muted, never red or green.
        $this->assertSame(
            ['pct' => 100.0, 'direction' => 'up', 'good' => null],
            $trends['deltas']['breakMinutes']
        );
    }

    public function test_deltas_read_down_moves_as_bad_and_equal_windows_as_flat(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);
        $worker = User::factory()->create(['organization_id' => $org->id]);

        $today = $this->today();
        $curDay = $today->subDays(3)->toDateString();
        $prevDay = $today->subDays(17)->toDateString();

        // hours halved → down 50%, and down on hours is bad.
        $this->workHour($worker, $prevDay, 10.0);
        $this->workHour($worker, $curDay, 5.0);

        // Exactly one person punched in EACH window → identical windows must
        // be flat with a muted (null) good flag, not a 0.0% "up".
        foreach ([$prevDay, $curDay] as $day) {
            $this->punch($worker, $day, '09:00:00', 'clock_in');
            $this->punch($worker, $day, '17:00:00', 'clock_out');
        }

        $trends = $this->trends(
            $this->actingAs($admin)->get(route('dashboard'))->assertOk()
        );

        $this->assertSame(
            ['pct' => 50.0, 'direction' => 'down', 'good' => false],
            $trends['deltas']['hours']
        );

        $this->assertSame(
            ['pct' => 0.0, 'direction' => 'flat', 'good' => null],
            $trends['deltas']['active']
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // An ongoing break counts up to now — today only
    // ─────────────────────────────────────────────────────────────────────

    public function test_an_open_break_today_is_counted_up_to_now(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);
        $worker = User::factory()->create(['organization_id' => $org->id]);

        $today = $this->today()->toDateString();

        // One closed 30-minute break, then a break still OPEN since 17:30.
        // Frozen "now" is 18:00, so the dashboard's break figure (which the
        // today-summary counts live) is 30 + 30 = 60 minutes — the sparkline
        // must agree.
        $this->punch($worker, $today, '09:00:00', 'clock_in');
        $this->punch($worker, $today, '13:00:00', 'break_start');
        $this->punch($worker, $today, '13:30:00', 'break_end');
        $this->punch($worker, $today, '17:30:00', 'break_start');

        $trends = $this->trends(
            $this->actingAs($admin)->get(route('dashboard'))->assertOk()
        );

        $this->assertSame(60, $trends['series']['breakMinutes'][13]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Access: members must not receive (or pay for) the block
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_member_does_not_receive_kpi_trends(): void
    {
        $org = $this->organization();
        $member = $this->member($org);

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->missing('kpiTrends'));
    }

    public function test_a_team_viewer_receives_kpi_trends(): void
    {
        $org = $this->organization();
        $viewer = User::factory()->create([
            'organization_id' => $org->id,
            'role' => 'member',
            'permissions' => ['dashboard.view_team'],
        ]);

        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('kpiTrends.series.hours', 14)
                ->has('kpiTrends.deltas.breakMinutes'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tenancy
    // ─────────────────────────────────────────────────────────────────────

    public function test_another_organizations_activity_never_moves_this_orgs_series(): void
    {
        $orgA = $this->organization();
        $adminA = $this->admin($orgA);

        $orgB = $this->organization();   // rebinds CurrentOrganization to B
        $adminB = $this->admin($orgB);
        $workerB = User::factory()->create(['organization_id' => $orgB->id]);

        $today = $this->today()->toDateString();

        // Org B has a loud day: hours, punches, breaks, a tracked session.
        $this->workHour($workerB, $today, 8.0);
        $this->trackedDay($workerB, $today, 4.0, 90, 'uuid-b');
        $this->punch($workerB, $today, '09:00:00', 'clock_in');
        $this->punch($workerB, $today, '13:00:00', 'break_start');
        $this->punch($workerB, $today, '13:15:00', 'break_end');
        $this->punch($workerB, $today, '17:00:00', 'clock_out');

        // Org A must see a completely silent fortnight...
        $trendsA = $this->trends(
            $this->actingAs($adminA)->get(route('dashboard'))->assertOk()
        );

        $this->assertSame(array_fill(0, 14, 0.0), $trendsA['series']['hours']);
        $this->assertSame(array_fill(0, 14, 0), $trendsA['series']['active']);
        $this->assertSame(array_fill(0, 14, 0.0), $trendsA['series']['activity']);
        $this->assertSame(array_fill(0, 14, 0), $trendsA['series']['breakMinutes']);

        // ...while org B sees exactly its own day, unpolluted and undoubled.
        $trendsB = $this->trends(
            $this->actingAs($adminB)->get(route('dashboard'))->assertOk()
        );

        $this->assertSame(12.0, $trendsB['series']['hours'][13]);
        $this->assertSame(1, $trendsB['series']['active'][13]);
        $this->assertSame(90.0, $trendsB['series']['activity'][13]);
        $this->assertSame(15, $trendsB['series']['breakMinutes'][13]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Cost
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_trend_block_adds_at_most_five_queries_to_a_cold_dashboard(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);
        $worker = User::factory()->create(['organization_id' => $org->id]);

        $today = $this->today()->toDateString();
        $this->workHour($worker, $today, 6.0);
        $this->punch($worker, $today, '09:00:00', 'clock_in');
        $this->punch($worker, $today, '17:00:00', 'clock_out');

        // First load warms every dashboard cache (topProjects, kpiTrends, …).
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        // Evict ONLY the trend block, so the cold/warm difference isolates
        // exactly what kpiTrends costs on a cold cache.
        Cache::forget(DashboardController::kpiTrendsCacheKey($org->id));

        $cold = $this->countQueries(
            fn () => $this->actingAs($admin)->get(route('dashboard'))->assertOk()
        );

        $warm = $this->countQueries(
            fn () => $this->actingAs($admin)->get(route('dashboard'))->assertOk()
        );

        $blockCost = $cold - $warm;

        $this->assertGreaterThanOrEqual(1, $blockCost, 'the evicted trend block should actually have recomputed');
        $this->assertLessThanOrEqual(5, $blockCost, 'kpiTrends must cost at most 5 queries on a cold cache');
    }
}
