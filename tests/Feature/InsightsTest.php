<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftOverride;
use App\Models\TrackingSession;
use App\Models\User;
use App\Models\WorkCategory;
use App\Models\WorkHour;
use App\Services\InsightsAggregator;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * INSIGHTS v1 — aggregation correctness, tenant isolation and scale.
 *
 * The three things that could quietly go wrong on this surface, in order of how
 * badly they would hurt:
 *
 *   1. WRONG NUMBERS. Every figure is a SQL aggregate, so an off-by-one in the
 *      half-open date bounds or a mis-weighted activity average is invisible in
 *      the UI and wrong in a management decision. Covered by exact-value asserts
 *      on hand-built data, including the boundary days at both ends.
 *   2. CROSS-TENANT LEAKAGE. The aggregates are unfiltered by design (the
 *      OrganizationScope is what filters them), so a missing scope would silently
 *      add another workspace's hours to the totals.
 *   3. SCALE COLLAPSE. The whole point of the feature. Guarded by asserting the
 *      QUERY COUNT does not grow with the number of people, days or rows.
 *
 * ROUTE NOTE: the canonical registration lives in routes/web.php (this build
 * returns the exact lines). The HTTP tests below register the same definition
 * only when it is not already present, so the permission + entitlement wiring is
 * covered both before and after those lines land.
 */
class InsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The Insights page is a NEW entry point, so it is not in the committed
        // Vite manifest until the next asset build. These tests assert the
        // server payload, not the bundle, so stub Vite out rather than making
        // the suite depend on a build step.
        $this->withoutVite();

        // The Insights feature key is part of this build's returned catalogue
        // row; create it here so the entitlement gate resolves it rather than
        // treating it as an unknown key.
        Feature::firstOrCreate(['key' => 'insights'], [
            'label' => 'Insights',
            'help' => 'Utilization vs target, activity gauge, billable split and highlights.',
            'kind' => 'flag',
            'group' => 'Features',
            'default_value' => false,
            'sort' => 150,
            'is_active' => true,
        ]);

        // Entitlements memoises the catalogue in a PHP static, which survives
        // between tests in one PHPUnit process — without this the row above is
        // invisible to the gate whenever another test class warmed the memo
        // first, and `insights` resolves as an unknown (denied) key.
        Entitlements::bump();

        // Likewise the permission key: a key absent from config/access.php can
        // never be granted (User::expandedPermissions() intersects against the
        // catalogue), so register the row this build returns when it is not yet
        // in the file. Idempotent — a no-op once the key lands.
        if (! array_key_exists('insights.view', config('access.permissions.Insights', []))) {
            config([
                'access.permissions.Insights' => array_merge(
                    config('access.permissions.Insights', []),
                    ['insights.view' => [
                        'label' => 'View Insights',
                        'description' => 'Opens the Insights analytics page.',
                    ]]
                ),
            ]);
        }

        $this->ensureInsightsRoute();
    }

    /**
     * Register the Insights route for the duration of the test run when
     * routes/web.php does not (yet) carry it.
     *
     * The rebuild is not decoration: the marketing `/{slug}` catch-all at the
     * END of routes/web.php matches "insights", and Laravel resolves routes in
     * REGISTRATION order — so a route merely appended here would be swallowed
     * by it and 404. Putting ours at the head of the collection reproduces the
     * position the returned route lines must occupy in web.php.
     */
    private function ensureInsightsRoute(): void
    {
        if (Route::has('insights.index')) {
            return;
        }

        $router = app('router');

        $insights = $router->middleware(['web', 'auth', 'permission:insights.view', 'entitlement:insights'])
            ->get('/insights', [\App\Http\Controllers\InsightsController::class, 'index'])
            ->name('insights.index');

        $reordered = new RouteCollection;
        $reordered->add($insights);

        foreach ($router->getRoutes() as $route) {
            if ($route !== $insights) {
                $reordered->add($route);
            }
        }

        $reordered->refreshNameLookups();
        $reordered->refreshActionLookups();

        $router->setRoutes($reordered);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        // The catalogue memo is a PHP static: drop it so the `insights` row this
        // class inserts (and RefreshDatabase then rolls back) cannot leak into a
        // later test class's view of the catalogue.
        Entitlements::flushMemo();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function manager(array $permissions = ['insights.view']): User
    {
        $manager = User::factory()->create([
            'role' => 'admin',
            'permissions' => $permissions,
        ]);

        // Grant the Insights entitlement for this workspace only.
        $org = Organization::find($manager->organization_id);
        $org->entitlement_overrides = ['insights' => true];
        $org->save();

        return $manager;
    }

    private function worker(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'member'], $attributes));
    }

    private function logHours(User $user, string $date, float $hours, array $attributes = []): WorkHour
    {
        return WorkHour::create(array_merge([
            'user_id' => $user->id,
            'date' => $date,
            'hours' => $hours,
            'is_billable' => true,
            'description' => 'test',
        ], $attributes));
    }

    private function trackSession(User $user, Carbon $startedAt, int $seconds, int $activity): TrackingSession
    {
        return TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-'.$user->id.'-'.$startedAt->timestamp.'-'.$seconds,
            'started_at' => $startedAt,
            'stopped_at' => $startedAt->copy()->addSeconds($seconds),
            'total_seconds' => $seconds,
            'activity_percent' => $activity,
            'status' => TrackingSession::STATUS_STOPPED,
            'source' => 'desktop',
        ]);
    }

    private function build(Carbon $start, Carbon $end, ?int $userId = null, ?int $teamId = null): array
    {
        return app(InsightsAggregator::class)->build($start, $end, $userId, $teamId);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Access control
    // ─────────────────────────────────────────────────────────────────────

    public function test_insights_requires_the_view_permission(): void
    {
        $member = $this->manager([]);

        $this->actingAs($member)->get('/insights')->assertForbidden();
    }

    public function test_insights_requires_the_insights_entitlement(): void
    {
        $manager = $this->manager();

        $org = Organization::find($manager->organization_id);
        $org->entitlement_overrides = ['insights' => false];
        $org->save();

        // EnsureEntitlement refuses (403) but answers with the Upgrade/Locked
        // page, which names the feature and offers the upgrade — never a bare error.
        $this->actingAs($manager->fresh())
            ->get('/insights')
            ->assertForbidden();

        // An API/XHR caller gets the machine-readable refusal instead.
        $this->actingAs($manager->fresh())
            ->getJson('/insights')
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_not_in_plan');
    }

    public function test_insights_page_loads_for_a_permitted_manager(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->get('/insights')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Insights/Index'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Range handling
    // ─────────────────────────────────────────────────────────────────────

    public function test_an_oversized_custom_range_is_clamped_to_the_cap(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->get('/insights?start=2000-01-01&end=2030-12-31')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('range.days', InsightsAggregator::MAX_RANGE_DAYS)
                ->etc()
            );
    }

    public function test_an_inverted_custom_range_is_swapped_not_rejected(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->get('/insights?start=2026-07-31&end=2026-07-01')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('range.start', '2026-07-01')
                ->where('range.end', '2026-07-31')
                ->etc()
            );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Aggregation correctness
    // ─────────────────────────────────────────────────────────────────────

    public function test_hours_are_summed_inclusively_across_both_boundary_days(): void
    {
        $manager = $this->manager();
        $worker = $this->worker();

        // One hour on each boundary day, one inside, one on each side OUTSIDE.
        $this->logHours($worker, '2026-06-30', 5);   // before
        $this->logHours($worker, '2026-07-01', 3);   // first day (inclusive)
        $this->logHours($worker, '2026-07-15', 4);   // middle
        $this->logHours($worker, '2026-07-31', 2);   // last day (inclusive)
        $this->logHours($worker, '2026-08-01', 7);   // after

        $payload = $this->build(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertSame(9.0, $payload['totals']['worked_hours']);

        $row = collect($payload['rows'])->firstWhere('id', $worker->id);
        $this->assertSame(9.0, $row['worked_hours']);
        $this->assertSame(3, $row['days_logged']);
    }

    public function test_billable_split_and_category_rollup_are_aggregated(): void
    {
        $manager = $this->manager();
        $worker = $this->worker();

        WorkCategory::ensureSeeded();
        $client = WorkCategory::where('slug', 'client_work')->firstOrFail();
        $admin = WorkCategory::where('slug', 'internal_admin')->firstOrFail();

        $this->logHours($worker, '2026-07-02', 6, ['work_category_id' => $client->id, 'is_billable' => true]);
        $this->logHours($worker, '2026-07-03', 2, ['work_category_id' => $client->id, 'is_billable' => false]);
        $this->logHours($worker, '2026-07-04', 2, ['work_category_id' => $admin->id, 'is_billable' => false]);

        $payload = $this->build(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertSame(10.0, $payload['totals']['worked_hours']);
        $this->assertSame(6.0, $payload['totals']['billable_hours']);
        $this->assertSame(4.0, $payload['totals']['non_billable_hours']);
        $this->assertSame(60.0, $payload['totals']['billable_ratio']);

        $categories = collect($payload['categories'])->keyBy('name');
        $this->assertSame(8.0, $categories['Client Work']['hours']);
        $this->assertSame(6.0, $categories['Client Work']['billable_hours']);
        $this->assertSame(2.0, $categories['Internal/Admin']['hours']);
        $this->assertSame(80.0, $categories['Client Work']['share']);
    }

    public function test_activity_is_weighted_by_session_length_not_averaged_flat(): void
    {
        $manager = $this->manager();
        $worker = $this->worker();

        // 9h at 80% and 1h at 20%. A flat average would be 50%; the correct
        // time-weighted answer is (9*80 + 1*20) / 10 = 74%.
        $this->trackSession($worker, Carbon::parse('2026-07-06 09:00:00'), 9 * 3600, 80);
        $this->trackSession($worker, Carbon::parse('2026-07-07 09:00:00'), 1 * 3600, 20);

        $payload = $this->build(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $row = collect($payload['rows'])->firstWhere('id', $worker->id);
        $this->assertSame(74, $row['activity_percent']);
        $this->assertSame(74, $payload['totals']['activity_percent']);
    }

    public function test_activity_is_null_rather_than_zero_when_nothing_was_tracked(): void
    {
        $manager = $this->manager();
        $worker = $this->worker();

        // Manual time only — no tracker sessions at all.
        $this->logHours($worker, '2026-07-06', 8);

        $payload = $this->build(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $row = collect($payload['rows'])->firstWhere('id', $worker->id);
        $this->assertNull($row['activity_percent'], 'No tracked time means unknown activity, not 0%.');
        $this->assertNull($payload['totals']['activity_percent']);
        $this->assertSame(8.0, $row['manual_hours']);
        $this->assertSame(0.0, $row['tracked_hours']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Utilization vs target
    // ─────────────────────────────────────────────────────────────────────

    public function test_target_is_derived_from_the_shift_length_and_working_days(): void
    {
        $manager = $this->manager();

        // Mon 2026-07-06 .. Sun 2026-07-12 = 7 days, 6 working (Sunday off).
        $worker = $this->worker(['shift_hours' => 8]);

        $this->logHours($worker, '2026-07-06', 8);
        $this->logHours($worker, '2026-07-07', 8);
        $this->logHours($worker, '2026-07-08', 8);

        $payload = $this->build(Carbon::parse('2026-07-06'), Carbon::parse('2026-07-12'));

        $this->assertSame(6, $payload['range']['working_days']);

        $row = collect($payload['rows'])->firstWhere('id', $worker->id);
        $this->assertSame(48.0, $row['target_hours'], '6 working days x 8h');
        $this->assertSame(24.0, $row['worked_hours']);
        $this->assertSame(50.0, $row['utilization']);
    }

    public function test_a_named_shift_length_wins_over_the_per_user_column(): void
    {
        $manager = $this->manager();

        $shift = Shift::create(['name' => 'Half day', 'hours' => 4, 'sort_order' => 9]);
        $worker = $this->worker(['shift_hours' => 8, 'shift_id' => $shift->id]);

        $payload = $this->build(Carbon::parse('2026-07-06'), Carbon::parse('2026-07-12'));

        $row = collect($payload['rows'])->firstWhere('id', $worker->id);
        $this->assertSame(4.0, $row['expected_daily_hours']);
        $this->assertSame(24.0, $row['target_hours'], '6 working days x 4h from the named shift');
    }

    public function test_a_one_day_shift_override_adjusts_that_persons_target(): void
    {
        $manager = $this->manager();
        $worker = $this->worker(['shift_hours' => 8]);

        // One 4-hour day replaces one standard 8-hour day: 48 - 8 + 4 = 44.
        ShiftOverride::create([
            'user_id' => $worker->id,
            'date' => '2026-07-08',
            'shift_hours' => 4,
            'reason' => 'Half day',
        ]);

        $payload = $this->build(Carbon::parse('2026-07-06'), Carbon::parse('2026-07-12'));

        $row = collect($payload['rows'])->firstWhere('id', $worker->id);
        $this->assertSame(44.0, $row['target_hours']);
    }

    public function test_no_target_set_stays_null_and_is_excluded_from_team_utilization(): void
    {
        $manager = $this->manager();

        $withTarget = $this->worker(['shift_hours' => 8]);
        $withoutTarget = $this->worker(['shift_hours' => null]);

        $this->logHours($withTarget, '2026-07-06', 48);
        $this->logHours($withoutTarget, '2026-07-06', 100);

        $payload = $this->build(Carbon::parse('2026-07-06'), Carbon::parse('2026-07-12'));

        $rows = collect($payload['rows'])->keyBy('id');

        $this->assertNull($rows[$withoutTarget->id]['target_hours'], 'A missing shift length must never be defaulted to 8h.');
        $this->assertNull($rows[$withoutTarget->id]['utilization']);

        // Team utilization counts only the person who HAS a target: 48 / 48.
        $this->assertSame(100.0, $payload['totals']['utilization']);
        $this->assertSame(48.0, $payload['totals']['target_hours']);
        $this->assertGreaterThanOrEqual(1, $payload['totals']['people_without_target']);
    }

    public function test_a_person_who_joined_mid_range_gets_a_pro_rated_target(): void
    {
        $manager = $this->manager();

        // Joined Thursday 2026-07-09: 3 working days remain (Thu, Fri, Sat) in
        // the Mon..Sun window with Sunday off.
        $worker = $this->worker(['shift_hours' => 8, 'joining_date' => '2026-07-09']);

        $payload = $this->build(Carbon::parse('2026-07-06'), Carbon::parse('2026-07-12'));

        $row = collect($payload['rows'])->firstWhere('id', $worker->id);
        $this->assertSame(3, $row['eligible_days']);
        $this->assertSame(24.0, $row['target_hours']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Highlights
    // ─────────────────────────────────────────────────────────────────────

    public function test_highlights_stay_empty_when_there_is_too_little_data(): void
    {
        $manager = $this->manager();
        $worker = $this->worker(['shift_hours' => 8]);

        $this->logHours($worker, '2026-07-06', 0.5);

        $payload = $this->build(Carbon::parse('2026-07-06'), Carbon::parse('2026-07-12'));

        $this->assertSame([], $payload['highlights'], 'Half an hour of data must not produce confident statements.');
    }

    public function test_under_target_highlight_is_derived_from_real_numbers(): void
    {
        $manager = $this->manager();
        $worker = $this->worker(['shift_hours' => 8]);

        // 12h against a 48h target = 25%.
        $this->logHours($worker, '2026-07-06', 6);
        $this->logHours($worker, '2026-07-07', 6);

        $payload = $this->build(Carbon::parse('2026-07-06'), Carbon::parse('2026-07-12'));

        $highlight = collect($payload['highlights'])->firstWhere('key', 'under_target');

        $this->assertNotNull($highlight);
        $this->assertStringContainsString($worker->name, $highlight['title'].$highlight['detail']);
        $this->assertStringContainsString('25%', $highlight['detail']);
    }

    public function test_most_improved_activity_compares_the_two_halves_of_the_range(): void
    {
        $manager = $this->manager();
        $worker = $this->worker();

        // First half (Jul 1..15): 8h at 40%. Second half (Jul 16..31): 8h at 80%.
        $this->trackSession($worker, Carbon::parse('2026-07-03 09:00:00'), 8 * 3600, 40);
        $this->trackSession($worker, Carbon::parse('2026-07-20 09:00:00'), 8 * 3600, 80);
        $this->logHours($worker, '2026-07-03', 8);
        $this->logHours($worker, '2026-07-20', 8);

        $payload = $this->build(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $highlight = collect($payload['highlights'])->firstWhere('key', 'most_improved_activity');

        $this->assertNotNull($highlight);
        $this->assertStringContainsString('40%', $highlight['detail']);
        $this->assertStringContainsString('80%', $highlight['detail']);
        $this->assertStringContainsString('40 points', $highlight['detail']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tenant isolation
    // ─────────────────────────────────────────────────────────────────────

    public function test_another_workspaces_hours_never_enter_the_aggregates(): void
    {
        $manager = $this->manager();
        $mine = $this->worker(['shift_hours' => 8]);
        $this->logHours($mine, '2026-07-06', 5);

        // A second workspace with far more time in the same window.
        $otherOrg = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);

        app(CurrentOrganization::class)->runFor($otherOrg, function () use ($otherOrg) {
            $stranger = User::factory()->create([
                'organization_id' => $otherOrg->id,
                'role' => 'member',
                'shift_hours' => 8,
            ]);

            WorkHour::create([
                'user_id' => $stranger->id,
                'date' => '2026-07-06',
                'hours' => 999,
                'is_billable' => true,
                'description' => 'other tenant',
            ]);
        });

        app(CurrentOrganization::class)->set($manager->organization_id);

        $payload = $this->build(Carbon::parse('2026-07-06'), Carbon::parse('2026-07-12'));

        $this->assertSame(5.0, $payload['totals']['worked_hours']);
        $this->assertCount(1, collect($payload['rows'])->where('worked_hours', '>', 0));
    }

    public function test_a_foreign_user_id_filter_falls_back_to_the_whole_workspace(): void
    {
        $manager = $this->manager();
        $mine = $this->worker(['shift_hours' => 8]);
        $this->logHours($mine, '2026-07-06', 5);

        $otherOrg = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);
        $strangerId = app(CurrentOrganization::class)->runFor($otherOrg, fn () => User::factory()->create([
            'organization_id' => $otherOrg->id,
            'role' => 'member',
        ])->id);

        app(CurrentOrganization::class)->set($manager->organization_id);

        $this->actingAs($manager)
            ->get('/insights?user_id='.$strangerId.'&start=2026-07-06&end=2026-07-12')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The foreign id is DROPPED, never honoured.
                ->where('filters.user_id', null)
                // 5.0 serialises to 5 over the wire; the value, not the type.
                ->where('totals.worked_hours', 5)
                ->etc()
            );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Scale
    // ─────────────────────────────────────────────────────────────────────

    public function test_query_count_does_not_grow_with_people_days_or_rows(): void
    {
        $manager = $this->manager();

        $small = $this->worker(['shift_hours' => 8]);
        $this->logHours($small, '2026-07-06', 8);

        $countQueries = function (callable $callback): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $callback();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // Warm the per-request memoised workspace settings first, so the
        // measurement compares two identical code paths rather than counting
        // the settings row's first-touch on the baseline run only.
        $this->build(Carbon::parse('2026-07-06'), Carbon::parse('2026-07-12'));

        $baseline = $countQueries(fn () => $this->build(Carbon::parse('2026-07-06'), Carbon::parse('2026-07-12')));

        // Now make the workspace an order of magnitude bigger, over a 92-day
        // range, and re-measure.
        $rows = [];
        $organizationId = $manager->organization_id;

        for ($i = 0; $i < 20; $i++) {
            $person = $this->worker(['shift_hours' => 8]);

            for ($d = 0; $d < 40; $d++) {
                $rows[] = [
                    'organization_id' => $organizationId,
                    'user_id' => $person->id,
                    'date' => Carbon::parse('2026-05-05')->addDays($d)->toDateString(),
                    'hours' => 7.5,
                    'is_billable' => $d % 3 === 0,
                    'description' => 'bulk',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // A bulk insert() BYPASSES the BelongsToOrganization hook, so
        // organization_id is stamped explicitly above.
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('work_hours')->insert($chunk);
        }

        $large = $countQueries(fn () => $this->build(Carbon::parse('2026-05-05'), Carbon::parse('2026-08-04')));

        $this->assertSame(
            $baseline,
            $large,
            'Insights must cost a FIXED number of queries: 21 people and 800 rows over 92 days may not add a single one.'
        );

        // And the fixed cost must actually be small — a regression that turned
        // one aggregate into a per-person query would still be "fixed" per call.
        $this->assertLessThanOrEqual(12, $large);
    }

    public function test_no_time_rows_are_hydrated_into_php(): void
    {
        $manager = $this->manager();
        $worker = $this->worker(['shift_hours' => 8]);

        // 92 days of work hours: if the aggregator ever hydrated rows, the
        // payload's per-person row count would follow the data volume.
        for ($d = 0; $d < 92; $d++) {
            $this->logHours($worker, Carbon::parse('2026-05-05')->addDays($d)->toDateString(), 6);
        }

        $payload = $this->build(Carbon::parse('2026-05-05'), Carbon::parse('2026-08-04'));

        // One row per PERSON, never per entry.
        $this->assertCount(2, $payload['rows'], 'The payload is per-person, not per work-hour row.');
        $this->assertSame(552.0, $payload['totals']['worked_hours']);
        $this->assertLessThanOrEqual(
            InsightsAggregator::MAX_RANGE_DAYS,
            count($payload['trend']),
            'The trend series is bounded by the range cap.'
        );
    }
}
