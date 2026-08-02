<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\TrackingActivitySample;
use App\Models\TrackingSession;
use App\Models\User;
use App\Models\WorkCategory;
use App\Models\WorkHour;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ROW-SHAPE GUARD for the rewritten `TeamController::index()`.
 *
 * The page stopped hydrating `TrackingSession` models: sessions are read via
 * `toBase()` with a LEFT JOIN for the client name, the five Carbon casts are
 * gone, the day-overlap clamp is computed once per session instead of by a
 * closure re-invoked ~5x, manual work-diary hours are summed in SQL, and the
 * latest heartbeat is found by a lexicographic string max with only the winner
 * parsed.
 *
 * Every one of those replaced something Eloquent used to do implicitly, so the
 * failure mode is not an exception — it is a number that is quietly wrong. This
 * pins the arithmetic branch by branch:
 *
 *   fully inside the day   -> the session's own idle-adjusted total_seconds
 *   straddling the day     -> wall-clock overlap with the day
 *   still running          -> the same rules, measured to "now"
 *   spanning the whole day -> the 16h cap
 *   under a minute         -> dropped as a deletion crumb, unless still active
 *
 * plus the client-name join, the 'Unassigned' fallback, the manual-hours
 * dilution of the activity score, the heartbeat max, and the row ordering.
 */
class TeamPageRowEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    /** 14:00 on 2026-06-15, business timezone. */
    private const NOW = '2026-06-15 14:00:00';

    private const TZ = 'Asia/Karachi';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, self::TZ));

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Zzz Admin',
            'permissions' => ['timeline.view_others'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function worker(string $name): User
    {
        return User::factory()->create(['role' => 'member', 'name' => $name]);
    }

    private function trackSession(User $user, array $attributes = []): TrackingSession
    {
        static $uuid = 0;

        return TrackingSession::create(array_merge([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-team-rows-'.(++$uuid),
            'status' => TrackingSession::STATUS_STOPPED,
            'source' => 'desktop',
            'activity_percent' => 0,
            'total_seconds' => 0,
        ], $attributes));
    }

    private function at(string $dateTime): Carbon
    {
        return Carbon::parse($dateTime, self::TZ);
    }

    /** @return array{rows: array<int,array<string,mixed>>, totals: array<string,mixed>} */
    private function teamPage(string $range = 'today'): array
    {
        $props = null;

        $this->actingAs($this->admin)
            ->get(route('team.index', ['range' => $range]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return ['rows' => $props['rows'], 'totals' => $props['totals']];
    }

    /** @param  array<int,array<string,mixed>>  $rows */
    private function row(array $rows, User $user): array
    {
        $row = collect($rows)->firstWhere('id', $user->id);

        $this->assertNotNull($row, "no team row for {$user->name}");

        return $row;
    }

    public function test_a_session_fully_inside_the_day_keeps_its_idle_adjusted_total(): void
    {
        $worker = $this->worker('Inside Ian');
        $client = Client::create(['name' => 'Acme Ltd', 'is_active' => true]);

        // Three hours of wall clock, but the tracker measured 2h of real work.
        // The rule is that a fully-contained session reports its OWN total, not
        // the wall clock — losing that would inflate everyone's day.
        $this->trackSession($worker, [
            'client_id' => $client->id,
            'started_at' => $this->at('2026-06-15 09:00:00'),
            'stopped_at' => $this->at('2026-06-15 12:00:00'),
            'total_seconds' => 7200,
            'activity_percent' => 80,
            'last_heartbeat_at' => $this->at('2026-06-15 11:58:00'),
        ]);

        $row = $this->row($this->teamPage()['rows'], $worker);

        $this->assertSame(7200, $row['tracked_seconds']);
        $this->assertSame(0, $row['manual_seconds']);
        $this->assertSame(7200, $row['total_seconds']);
        $this->assertSame(80, $row['activity_percent']);
        $this->assertSame(['name' => 'Acme Ltd', 'total_seconds' => 7200], $row['top_client']);
        $this->assertFalse($row['is_live']);
        $this->assertNull($row['live']);
        $this->assertSame(
            $this->at('2026-06-15 11:58:00')->toIso8601String(),
            $row['last_heartbeat_at']
        );
    }

    public function test_a_session_straddling_midnight_only_contributes_its_overlap(): void
    {
        $worker = $this->worker('Night Nora');
        $client = Client::create(['name' => 'Owl Corp', 'is_active' => true]);

        // 22:00 yesterday -> 02:00 today. The session's own total is 4h, but
        // only the 2h after midnight belong to today; the rest stays on the
        // previous date.
        $this->trackSession($worker, [
            'client_id' => $client->id,
            'started_at' => $this->at('2026-06-14 22:00:00'),
            'stopped_at' => $this->at('2026-06-15 02:00:00'),
            'total_seconds' => 14400,
            'activity_percent' => 50,
        ]);

        $row = $this->row($this->teamPage()['rows'], $worker);

        $this->assertSame(7200, $row['tracked_seconds']);
        $this->assertSame(50, $row['activity_percent']);
        $this->assertSame(['name' => 'Owl Corp', 'total_seconds' => 7200], $row['top_client']);
    }

    public function test_a_session_spanning_the_whole_day_is_capped_at_sixteen_hours(): void
    {
        $worker = $this->worker('Forgot Fred');

        // Started the previous evening and not stopped until the following
        // morning: the overlap is the entire day, which the cap bounds at 16h so
        // one forgotten session cannot report 24 hours of work.
        $this->trackSession($worker, [
            'started_at' => $this->at('2026-06-14 20:00:00'),
            'stopped_at' => $this->at('2026-06-16 06:00:00'),
            'total_seconds' => 122400,
            'activity_percent' => 40,
        ]);

        $row = $this->row($this->teamPage()['rows'], $worker);

        $this->assertSame(16 * 3600, $row['tracked_seconds']);
        $this->assertSame('Unassigned', $row['top_client']['name']);
    }

    public function test_a_running_session_counts_toward_today_and_reports_live_details(): void
    {
        $worker = $this->worker('Live Lena');
        $client = Client::create(['name' => 'Now Ltd', 'is_active' => true]);

        $this->trackSession($worker, [
            'client_id' => $client->id,
            'status' => TrackingSession::STATUS_ACTIVE,
            'task_note' => 'Shipping the release',
            'started_at' => $this->at('2026-06-15 10:00:00'),
            'stopped_at' => null,
            'total_seconds' => 3600,
            'activity_percent' => 65,
            'last_heartbeat_at' => $this->at('2026-06-15 13:59:00'),
        ]);

        $row = $this->row($this->teamPage()['rows'], $worker);

        $this->assertSame(3600, $row['tracked_seconds']);
        $this->assertTrue($row['is_live']);
        $this->assertSame('Now Ltd', $row['live']['client']);
        $this->assertSame('Shipping the release', $row['live']['task_note']);
        $this->assertSame(65, $row['live']['activity_percent']);
        $this->assertSame($this->at('2026-06-15 10:00:00')->toIso8601String(), $row['live']['started_at']);
    }

    public function test_sub_minute_deletion_crumbs_are_dropped_but_a_running_one_is_kept(): void
    {
        $crumbOnly = $this->worker('Crumb Cara');
        $mixed = $this->worker('Mixed Mo');
        $runningCrumb = $this->worker('Just Started Jo');

        $this->trackSession($crumbOnly, [
            'started_at' => $this->at('2026-06-15 10:00:00'),
            'stopped_at' => $this->at('2026-06-15 10:00:30'),
            'total_seconds' => 30,
        ]);

        $this->trackSession($mixed, [
            'started_at' => $this->at('2026-06-15 10:00:00'),
            'stopped_at' => $this->at('2026-06-15 10:00:45'),
            'total_seconds' => 45,
        ]);
        $this->trackSession($mixed, [
            'started_at' => $this->at('2026-06-15 11:00:00'),
            'stopped_at' => $this->at('2026-06-15 12:00:00'),
            'total_seconds' => 3600,
        ]);

        // Still running, and only seconds old — must survive the crumb filter.
        $this->trackSession($runningCrumb, [
            'status' => TrackingSession::STATUS_ACTIVE,
            'started_at' => $this->at('2026-06-15 13:59:50'),
            'stopped_at' => null,
            'total_seconds' => 10,
        ]);

        $rows = $this->teamPage()['rows'];

        $this->assertSame(0, $this->row($rows, $crumbOnly)['tracked_seconds']);
        $this->assertNull($this->row($rows, $crumbOnly)['top_client']);
        $this->assertSame(3600, $this->row($rows, $mixed)['tracked_seconds']);
        $this->assertSame(10, $this->row($rows, $runningCrumb)['tracked_seconds']);
        $this->assertTrue($this->row($rows, $runningCrumb)['is_live']);
    }

    /**
     * Manual work-diary hours are now summed in SQL rather than read row by row.
     * They count as time but carry 0% activity, so they dilute the score in
     * proportion to their share of the day — and tracker-mirrored rows
     * (`source = 'tracker'`) must be excluded or the tracked time is counted
     * twice.
     */
    public function test_manual_diary_hours_are_added_and_dilute_the_activity_score(): void
    {
        $worker = $this->worker('Manual Mia');
        WorkCategory::ensureSeeded();
        $category = WorkCategory::query()->orderBy('id')->firstOrFail();

        $this->trackSession($worker, [
            'started_at' => $this->at('2026-06-15 09:00:00'),
            'stopped_at' => $this->at('2026-06-15 10:00:00'),
            'total_seconds' => 3600,
            'activity_percent' => 100,
        ]);

        // 1h typed in by hand …
        WorkHour::create([
            'user_id' => $worker->id,
            'date' => '2026-06-15',
            'hours' => 1,
            'description' => 'Wrote the spec',
            'work_type' => 'office_work',
            'work_category_id' => $category->id,
            'source' => 'manual',
        ]);

        // … and 5h mirrored from the tracker, which must NOT be added again.
        WorkHour::create([
            'user_id' => $worker->id,
            'date' => '2026-06-15',
            'hours' => 5,
            'description' => 'Mirrored session',
            'work_type' => 'office_work',
            'work_category_id' => $category->id,
            'source' => WorkHour::SOURCE_TRACKER,
        ]);

        // A different day's manual entry must stay out of "today".
        WorkHour::create([
            'user_id' => $worker->id,
            'date' => '2026-06-14',
            'hours' => 8,
            'description' => 'Yesterday',
            'work_type' => 'office_work',
            'work_category_id' => $category->id,
            'source' => 'manual',
        ]);

        $row = $this->row($this->teamPage()['rows'], $worker);

        $this->assertSame(3600, $row['tracked_seconds']);
        $this->assertSame(3600, $row['manual_seconds']);
        $this->assertSame(7200, $row['total_seconds']);
        // 100% tracked activity over half the day -> 50%.
        $this->assertSame(50, $row['activity_percent']);
    }

    /**
     * Activity is weighted by each session's contribution to THIS day, not by a
     * flat average — a 3h session at 90% and a 1h session at 10% is 70%, not
     * 50%.
     */
    public function test_activity_is_weighted_by_each_sessions_time_on_the_day(): void
    {
        $worker = $this->worker('Weighted Wes');

        $this->trackSession($worker, [
            'started_at' => $this->at('2026-06-15 08:00:00'),
            'stopped_at' => $this->at('2026-06-15 11:00:00'),
            'total_seconds' => 10800,
            'activity_percent' => 90,
        ]);
        $this->trackSession($worker, [
            'started_at' => $this->at('2026-06-15 11:00:00'),
            'stopped_at' => $this->at('2026-06-15 12:00:00'),
            'total_seconds' => 3600,
            'activity_percent' => 10,
        ]);

        $row = $this->row($this->teamPage()['rows'], $worker);

        $this->assertSame(14400, $row['tracked_seconds']);
        $this->assertSame(70, $row['activity_percent']);
    }

    /**
     * The client name now arrives from a LEFT JOIN instead of an eager-loaded
     * relation, and the per-client split is built in the same single pass as the
     * total. A clear winner must win; a NULL client must read 'Unassigned'.
     */
    public function test_the_top_client_is_the_one_with_the_most_time_on_the_day(): void
    {
        $worker = $this->worker('Split Sam');
        $small = Client::create(['name' => 'Small Fry', 'is_active' => true]);
        $big = Client::create(['name' => 'Big Fish', 'is_active' => true]);

        $this->trackSession($worker, [
            'client_id' => $small->id,
            'started_at' => $this->at('2026-06-15 09:00:00'),
            'stopped_at' => $this->at('2026-06-15 10:00:00'),
            'total_seconds' => 3600,
        ]);
        $this->trackSession($worker, [
            'client_id' => $big->id,
            'started_at' => $this->at('2026-06-15 10:00:00'),
            'stopped_at' => $this->at('2026-06-15 12:00:00'),
            'total_seconds' => 7200,
        ]);
        $this->trackSession($worker, [
            'client_id' => null,
            'started_at' => $this->at('2026-06-15 12:00:00'),
            'stopped_at' => $this->at('2026-06-15 12:30:00'),
            'total_seconds' => 1800,
        ]);
        // A second session for the same client must be folded into one bucket.
        $this->trackSession($worker, [
            'client_id' => $big->id,
            'started_at' => $this->at('2026-06-15 13:00:00'),
            'stopped_at' => $this->at('2026-06-15 13:30:00'),
            'total_seconds' => 1800,
        ]);

        $row = $this->row($this->teamPage()['rows'], $worker);

        $this->assertSame(14400, $row['tracked_seconds']);
        $this->assertSame(['name' => 'Big Fish', 'total_seconds' => 9000], $row['top_client']);
    }

    /**
     * An EXACT tie. The pick is `>` (not `>=`) over an insertion-ordered map, so
     * the first session read wins — but the underlying query carries no
     * ORDER BY, so which row is "first" is the storage engine's choice and is
     * NOT a contract. What IS a contract is that the answer is one of the tied
     * clients with the correct total, and that the totals are unaffected.
     */
    public function test_an_exact_tie_still_reports_one_of_the_tied_clients_with_the_right_total(): void
    {
        $worker = $this->worker('Tied Tia');
        $a = Client::create(['name' => 'Alpha Co', 'is_active' => true]);
        $b = Client::create(['name' => 'Beta Co', 'is_active' => true]);

        foreach ([$a, $b] as $client) {
            $this->trackSession($worker, [
                'client_id' => $client->id,
                'started_at' => $this->at('2026-06-15 09:00:00'),
                'stopped_at' => $this->at('2026-06-15 10:00:00'),
                'total_seconds' => 3600,
            ]);
        }

        $row = $this->row($this->teamPage()['rows'], $worker);

        $this->assertSame(7200, $row['tracked_seconds']);
        $this->assertContains($row['top_client']['name'], ['Alpha Co', 'Beta Co']);
        $this->assertSame(3600, $row['top_client']['total_seconds']);
    }

    /**
     * The latest heartbeat is found by a string max over the raw 'Y-m-d H:i:s'
     * values (lexicographic == chronological in one fixed zone) with only the
     * winner parsed. A rewrite that compared the wrong field, or that parsed
     * before comparing, would show a stale "last seen".
     */
    public function test_the_last_heartbeat_is_the_latest_across_the_days_sessions(): void
    {
        $worker = $this->worker('Beat Ben');

        $this->trackSession($worker, [
            'started_at' => $this->at('2026-06-15 09:00:00'),
            'stopped_at' => $this->at('2026-06-15 10:00:00'),
            'total_seconds' => 3600,
            'last_heartbeat_at' => $this->at('2026-06-15 09:59:00'),
        ]);
        $this->trackSession($worker, [
            'started_at' => $this->at('2026-06-15 11:00:00'),
            'stopped_at' => $this->at('2026-06-15 12:00:00'),
            'total_seconds' => 3600,
            'last_heartbeat_at' => $this->at('2026-06-15 11:58:00'),
        ]);
        $this->trackSession($worker, [
            'started_at' => $this->at('2026-06-15 10:00:00'),
            'stopped_at' => $this->at('2026-06-15 10:30:00'),
            'total_seconds' => 1800,
            'last_heartbeat_at' => null,
        ]);

        $row = $this->row($this->teamPage()['rows'], $worker);

        $this->assertSame($this->at('2026-06-15 11:58:00')->toIso8601String(), $row['last_heartbeat_at']);
    }

    public function test_a_person_with_no_sessions_reports_a_complete_empty_row(): void
    {
        $worker = $this->worker('Quiet Quinn');

        $row = $this->row($this->teamPage()['rows'], $worker);

        $this->assertSame(0, $row['total_seconds']);
        $this->assertSame(0, $row['tracked_seconds']);
        $this->assertSame(0, $row['manual_seconds']);
        $this->assertSame(0, $row['activity_percent']);
        $this->assertNull($row['top_client']);
        $this->assertNull($row['top_app']);
        $this->assertNull($row['last_heartbeat_at']);
        $this->assertFalse($row['is_live']);
        $this->assertSame($worker->email, $row['email']);
        $this->assertSame('member', $row['role']);
    }

    public function test_the_top_app_comes_from_the_days_activity_samples(): void
    {
        $worker = $this->worker('Appy Ada');

        $session = $this->trackSession($worker, [
            'started_at' => $this->at('2026-06-15 09:00:00'),
            'stopped_at' => $this->at('2026-06-15 12:00:00'),
            'total_seconds' => 10800,
        ]);

        foreach ([['Slack', 2], ['PhpStorm', 5]] as [$app, $count]) {
            foreach (range(1, $count) as $i) {
                TrackingActivitySample::create([
                    'tracking_session_id' => $session->id,
                    'user_id' => $worker->id,
                    'captured_at' => $this->at('2026-06-15 09:00:00')->addMinutes($i * ($app === 'Slack' ? 1 : 7)),
                    'keyboard_count' => 1,
                    'mouse_count' => 1,
                    'idle_seconds' => 0,
                    'active_app' => $app,
                ]);
            }
        }

        $row = $this->row($this->teamPage()['rows'], $worker);

        $this->assertSame('PhpStorm', $row['top_app']);
    }

    /**
     * Ordering and the totals block: live people first, then most time, then
     * name. Everything below is deterministic (no ties), so this IS a contract.
     */
    public function test_rows_are_ordered_live_first_then_by_time_then_by_name_and_totals_match(): void
    {
        $live = $this->worker('Aaa Live');       // least time, but running
        $most = $this->worker('Bbb Most');
        $least = $this->worker('Ccc Least');
        $idle = $this->worker('Ddd Idle');       // no time at all

        $this->trackSession($live, [
            'status' => TrackingSession::STATUS_ACTIVE,
            'started_at' => $this->at('2026-06-15 13:00:00'),
            'stopped_at' => null,
            'total_seconds' => 600,
        ]);
        $this->trackSession($most, [
            'started_at' => $this->at('2026-06-15 08:00:00'),
            'stopped_at' => $this->at('2026-06-15 12:00:00'),
            'total_seconds' => 14400,
        ]);
        $this->trackSession($least, [
            'started_at' => $this->at('2026-06-15 08:00:00'),
            'stopped_at' => $this->at('2026-06-15 09:00:00'),
            'total_seconds' => 3600,
        ]);

        $page = $this->teamPage();
        $names = array_column($page['rows'], 'name');

        $this->assertSame('Aaa Live', $names[0], 'the running person leads regardless of total time');
        $this->assertSame('Bbb Most', $names[1]);
        $this->assertSame('Ccc Least', $names[2]);

        // The two zero-time people (Ddd Idle and the admin) tie on time, so name
        // decides: "Ddd Idle" before "Zzz Admin".
        $this->assertSame(['Ddd Idle', 'Zzz Admin'], array_slice($names, 3));

        $this->assertSame([
            'day' => 600 + 14400 + 3600,
            'people_with_time' => 3,
            'people_live' => 1,
            'team_size' => 5,
        ], $page['totals']);
    }

    /**
     * A multi-day range must clamp each session to the RANGE, not to a single
     * day — the same code path, different bounds. Yesterday's evening session
     * belongs to a 7-day range in full.
     */
    public function test_a_multi_day_range_includes_earlier_days_in_full(): void
    {
        $worker = $this->worker('Range Rita');

        $this->trackSession($worker, [
            'started_at' => $this->at('2026-06-12 09:00:00'),
            'stopped_at' => $this->at('2026-06-12 12:00:00'),
            'total_seconds' => 10800,
        ]);
        $this->trackSession($worker, [
            'started_at' => $this->at('2026-06-15 09:00:00'),
            'stopped_at' => $this->at('2026-06-15 10:00:00'),
            'total_seconds' => 3600,
        ]);

        $today = $this->row($this->teamPage('today')['rows'], $worker);
        $week = $this->row($this->teamPage('7d')['rows'], $worker);

        $this->assertSame(3600, $today['tracked_seconds']);
        $this->assertSame(14400, $week['tracked_seconds']);
    }

    /**
     * The LEFT JOIN reaches a second table, so it is worth stating plainly that
     * it cannot reach another workspace's clients or sessions.
     */
    public function test_another_workspaces_sessions_never_appear_on_the_team_page(): void
    {
        $mine = $this->worker('Mine Mary');

        $this->trackSession($mine, [
            'started_at' => $this->at('2026-06-15 09:00:00'),
            'stopped_at' => $this->at('2026-06-15 10:00:00'),
            'total_seconds' => 3600,
        ]);

        $current = app(CurrentOrganization::class);
        $myOrg = $current->id();
        $otherOrg = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);

        $current->runFor($otherOrg, function () {
            $stranger = User::factory()->create(['role' => 'member', 'name' => 'Aaa Stranger']);
            TrackingSession::create([
                'user_id' => $stranger->id,
                'client_uuid' => 'uuid-foreign',
                'status' => TrackingSession::STATUS_ACTIVE,
                'source' => 'desktop',
                'started_at' => Carbon::parse('2026-06-15 09:00:00', self::TZ),
                'stopped_at' => null,
                'total_seconds' => 99999,
                'activity_percent' => 99,
            ]);
        });

        $current->set($myOrg);

        $page = $this->teamPage();

        $this->assertNotContains('Aaa Stranger', array_column($page['rows'], 'name'));
        $this->assertSame(3600, $page['totals']['day']);
        $this->assertSame(0, $page['totals']['people_live']);
    }
}
