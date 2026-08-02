<?php

namespace Tests\Feature;

use App\Models\ManualAttendanceAudit;
use App\Models\ManualAttendanceMark;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\ShiftOverride;
use App\Models\Subscription;
use App\Models\TimeEntry;
use App\Models\TrackingSession;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression cover for the attendance + time-entry hot paths (audit findings
 * medium-10, medium-11, medium-12, medium-13, medium-14, medium-19).
 *
 * These two endpoints run on every dashboard poll and every clock action, so
 * they are what actually falls over for a large company. The guards here are of
 * three kinds:
 *
 *  - ISOLATION: a user id from another tenant must never be written, and must
 *    not be usable as an existence oracle for the platform's user-id space.
 *  - SHAPE: the aggressive projection/no-hydration rewrites must return exactly
 *    the numbers and keys the React pages already consume.
 *  - COST: the query count must not grow with the size of the team — the N+1
 *    that made every clock action feel seconds slow was reintroduced once
 *    already by the shift-resolution work, so it is pinned by a test.
 */
class AttendanceHotPathScaleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Asia/Karachi matches config('app.timezone') in this environment, so the
     * stored wall-clock and the business-timezone wall-clock line up and the
     * "g:i A" assertions below read as written.
     */
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

    /**
     * @param  string  $date  the ATTENDANCE day the punch is filed under
     * @param  string|null  $timestampDate  the calendar day the punch happened
     *                                      on, when it differs (early clock-in
     *                                      for tomorrow's shift, night-shift
     *                                      spillover)
     */
    private function punch(User $user, string $date, string $time, string $type, ?string $timestampDate = null): void
    {
        TimeEntry::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'action_type' => $type,
            'action_timestamp' => Carbon::parse(($timestampDate ?? $date)." {$time}", 'Asia/Karachi'),
            'action_date' => $date,
            'action_time' => $time,
        ]);
    }

    // ---------------------------------------------------------------------
    // Tenant isolation on the manual-attendance writes (medium-19)
    // ---------------------------------------------------------------------

    public function test_manual_status_refuses_a_user_id_from_another_tenant(): void
    {
        $victimOrg = $this->organization();
        $victim = User::factory()->create([
            'organization_id' => $victimOrg->id,
            'name' => 'Victim Employee',
        ]);

        $attackerOrg = $this->organization();
        $attacker = $this->admin($attackerOrg);

        $this->actingAs($attacker)
            ->patchJson(route('employee-attendance.manual-status'), [
                'user_id' => $victim->id,
                'date' => '2026-06-08',
                'status_code' => 'A',
                'note' => 'not yours',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        // The write is what matters: a mark stamped with the ATTACKER's
        // organization but pointing at the victim is a permanently dangling
        // reference that corrupts the attacker tenant's attendance counts and
        // renders as "Deleted user" forever.
        $this->assertSame(0, DB::table('manual_attendance_marks')->count());
        $this->assertSame(0, DB::table('manual_attendance_audits')->count());
    }

    public function test_manual_status_gives_the_same_answer_for_foreign_and_nonexistent_ids(): void
    {
        $victimOrg = $this->organization();
        $victim = User::factory()->create(['organization_id' => $victimOrg->id]);

        $attackerOrg = $this->organization();
        $attacker = $this->admin($attackerOrg);

        // A differing status per id would be a cross-tenant existence oracle:
        // it would let a caller enumerate the platform's whole user-id space.
        $foreign = $this->actingAs($attacker)
            ->patchJson(route('employee-attendance.manual-status'), [
                'user_id' => $victim->id,
                'date' => '2026-06-08',
                'status_code' => 'A',
            ])->status();

        $nonexistent = $this->actingAs($attacker)
            ->patchJson(route('employee-attendance.manual-status'), [
                'user_id' => 99999999,
                'date' => '2026-06-08',
                'status_code' => 'A',
            ])->status();

        $this->assertSame($nonexistent, $foreign);
    }

    public function test_manual_status_still_writes_for_a_user_in_the_same_tenant(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);
        $employee = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($admin)
            ->patchJson(route('employee-attendance.manual-status'), [
                'user_id' => $employee->id,
                'date' => '2026-06-08',
                'status_code' => 'L',
                'note' => 'Approved leave',
            ])
            ->assertOk();

        $this->assertSame(1, ManualAttendanceMark::query()->where('user_id', $employee->id)->count());
        $this->assertSame(1, ManualAttendanceAudit::query()->where('user_id', $employee->id)->count());
    }

    public function test_attendance_slack_refuses_a_user_id_from_another_tenant(): void
    {
        $victimOrg = $this->organization();
        $victim = User::factory()->create(['organization_id' => $victimOrg->id]);

        $attackerOrg = $this->organization();
        $attacker = $this->admin($attackerOrg);

        $this->actingAs($attacker)
            ->postJson(route('employee-attendance.slack'), [
                'month' => '2026-06',
                'user_ids' => [$victim->id],
                'include_fields' => ['present'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_ids.0');
    }

    // ---------------------------------------------------------------------
    // The monthly grid rewrite must keep the numbers (medium-10, medium-11)
    // ---------------------------------------------------------------------

    public function test_monthly_grid_counts_punches_on_the_last_day_of_the_month(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);
        $employee = User::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Month End Worker',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-01 09:00:00', 'Asia/Karachi'));

        // The old inclusive whereBetween upper bound compared 'Y-m-d' against
        // the "Y-m-d 00:00:00" SQLite persists through the `date` cast, so the
        // last day of the month silently fell out of the grid.
        $this->punch($employee, '2026-06-30', '09:00:00', 'clock_in');
        $this->punch($employee, '2026-06-30', '17:00:00', 'clock_out');

        $row = collect(
            $this->actingAs($admin)
                ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $employee->id);

        $day = collect($row['days'])->firstWhere('date', '2026-06-30');

        $this->assertSame('P', $day['status_code']);
        $this->assertSame(8.0, (float) $day['work_hours']);
        $this->assertSame('9:00 AM', $day['first_clock_in']);
        $this->assertSame('5:00 PM', $day['last_action_time']);
        $this->assertSame(8.0, (float) $row['summary']['total_work_hours']);
    }

    public function test_monthly_grid_keeps_an_archived_employee_who_worked_that_month(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);

        $leaver = User::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Left In June',
            'is_active' => false,
        ]);
        $longGone = User::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Left Years Ago',
            'is_active' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-01 09:00:00', 'Asia/Karachi'));

        $this->punch($leaver, '2026-06-10', '09:00:00', 'clock_in');
        $this->punch($leaver, '2026-06-10', '17:00:00', 'clock_out');

        $ids = collect(
            $this->actingAs($admin)
                ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
                ->assertOk()
                ->json('employees')
        )->pluck('user_id');

        // Payroll history for a leaver must survive; the ones with nothing in
        // the window must stop costing 30 cells each, forever.
        $this->assertTrue($ids->contains($leaver->id), 'An archived employee with punches that month must still be listed.');
        $this->assertFalse($ids->contains($longGone->id), 'An archived employee with no data that month must not be rendered.');
    }

    public function test_monthly_grid_query_count_does_not_grow_with_the_team(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);

        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00', 'Asia/Karachi'));

        User::factory()->count(3)->create(['organization_id' => $org->id]);

        // Warm the per-request caches that only populate once (entitlement
        // catalogue, the tenant's monitoring settings row) so the comparison
        // below measures the endpoint, not first-run seeding.
        $this->actingAs($admin)
            ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
            ->assertOk();

        $small = $this->countQueries(fn () => $this->actingAs($admin)
            ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
            ->assertOk());

        User::factory()->count(12)->create(['organization_id' => $org->id]);

        $large = $this->countQueries(fn () => $this->actingAs($admin)
            ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
            ->assertOk());

        $this->assertSame(
            $small,
            $large,
            'The monthly grid must issue a fixed number of queries regardless of headcount.'
        );
    }

    // ---------------------------------------------------------------------
    // The dashboard summary rewrite (medium-12, medium-13)
    // ---------------------------------------------------------------------

    public function test_today_summary_query_count_does_not_grow_with_the_team(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Karachi'));

        $seed = function (int $count) use ($org) {
            foreach (range(1, $count) as $i) {
                $worker = User::factory()->create([
                    'organization_id' => $org->id,
                    'shift_start_time' => '09:00',
                    'shift_hours' => 8,
                ]);
                $this->punch($worker, '2026-06-15', '09:00:00', 'clock_in');
            }
        };

        $seed(2);

        // Warm the once-per-tenant caches (entitlements, monitoring settings)
        // so the comparison measures the endpoint itself.
        $this->actingAs($admin)->getJson(route('time-entries.today-summary'))->assertOk();

        $small = $this->countQueries(fn () => $this->actingAs($admin)
            ->getJson(route('time-entries.today-summary'))
            ->assertOk());

        $seed(10);
        $large = $this->countQueries(fn () => $this->actingAs($admin)
            ->getJson(route('time-entries.today-summary'))
            ->assertOk());

        // Every extra employee used to add two lazy shift queries inside
        // attendanceDateFor() — ~600 per call on a 300-person tenant, on a poll
        // that fires after every clock action.
        $this->assertSame(
            $small,
            $large,
            'The dashboard summary must not run per-employee shift queries.'
        );
    }

    public function test_today_summary_still_resolves_a_one_day_shift_override(): void
    {
        // The eager-loaded override window must cover [now - 1d, now + 1d]:
        // attendanceDateFor() asks for the day before and the day after, and a
        // narrower constrained eager load makes a real override resolve as "no
        // override" silently — a night-shift correctness regression.
        $org = $this->organization();
        $admin = $this->admin($org);

        Carbon::setTestNow(Carbon::parse('2026-06-15 22:30:00', 'Asia/Karachi'));

        $worker = User::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Night Shift Worker',
            'shift_start_time' => '09:00',
            'shift_hours' => 8,
        ]);

        // Tomorrow's shift starts at 00:30, so a 22:30 clock-in tonight is an
        // early arrival for TOMORROW's attendance day (the 4h grace window).
        ShiftOverride::create([
            'organization_id' => $org->id,
            'user_id' => $worker->id,
            'date' => '2026-06-16',
            'shift_start_time' => '00:30',
            'shift_hours' => 8,
        ]);

        // Filed under TOMORROW's attendance day, punched TONIGHT — exactly what
        // store() writes when attendanceDateFor() sees the override.
        $this->punch($worker, '2026-06-16', '22:30:00', 'clock_in', '2026-06-15');

        $row = collect(
            $this->actingAs($admin)
                ->getJson(route('time-entries.today-summary'))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $worker->id);

        $this->assertNotNull($row, 'The worker must be listed.');
        // Only reachable if attendanceDateFor() saw the 2026-06-16 override and
        // bucketed tonight's punch onto tomorrow's attendance day.
        $this->assertSame(1, $row['total_entries']);
        $this->assertSame('clock_in', $row['last_action']);
    }

    public function test_today_summary_keeps_the_today_week_and_month_buckets(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);

        // Wednesday, so week-start (Monday by default) is 2026-06-15.
        Carbon::setTestNow(Carbon::parse('2026-06-17 18:00:00', 'Asia/Karachi'));

        $worker = User::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Bucketed Worker',
        ]);

        // 2h earlier this month (before this week), 3h earlier this week, 4h today.
        $this->punch($worker, '2026-06-02', '09:00:00', 'clock_in');
        $this->punch($worker, '2026-06-02', '11:00:00', 'clock_out');
        $this->punch($worker, '2026-06-15', '09:00:00', 'clock_in');
        $this->punch($worker, '2026-06-15', '12:00:00', 'clock_out');
        $this->punch($worker, '2026-06-17', '09:00:00', 'clock_in');
        $this->punch($worker, '2026-06-17', '13:00:00', 'clock_out');

        $row = collect(
            $this->actingAs($admin)
                ->getJson(route('time-entries.today-summary'))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $worker->id);

        $this->assertNotNull($row);
        // The single-pass bucketing must partition exactly like the three
        // filter() passes it replaced.
        $this->assertSame(4.0, (float) $row['total_work_hours']);
        $this->assertSame(7.0, (float) $row['weekly_work_hours']);
        $this->assertSame(9.0, (float) $row['monthly_work_hours']);
        $this->assertSame(2, $row['total_entries']);
        $this->assertSame('1:00 PM', $row['last_action_time']);
        $this->assertSame('Clocked Out', $row['current_status']);
    }

    public function test_today_summary_drops_deactivated_staff(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Karachi'));

        $archived = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => false,
        ]);
        $this->punch($archived, '2026-06-15', '09:00:00', 'clock_in');

        $ids = collect(
            $this->actingAs($admin)
                ->getJson(route('time-entries.today-summary'))
                ->assertOk()
                ->json('employees')
        )->pluck('user_id');

        $this->assertFalse($ids->contains($archived->id));
    }

    // ---------------------------------------------------------------------
    // teamActivity range surface (medium-14)
    // ---------------------------------------------------------------------

    public function test_team_activity_rejects_a_relative_date_expression(): void
    {
        $org = $this->organization();

        // `date` accepted anything strtotime did, so "+5 years" resolved into a
        // real (clamped, but caller-chosen) window. Y-m-d only.
        $this->actingAs($this->admin($org))
            ->getJson('/time-entries/team-activity?start=%2B5+years&end=2026-06-30')
            ->assertStatus(422);
    }

    public function test_team_activity_ignores_a_session_left_running_days_ago(): void
    {
        $org = $this->organization();
        $admin = $this->admin($org);
        $worker = User::factory()->create(['organization_id' => $org->id]);

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00', 'Asia/Karachi'));

        // A crashed desktop client's session, never stopped. Unbounded, every
        // one of these was read on every call, forever.
        TrackingSession::create([
            'organization_id' => $org->id,
            'user_id' => $worker->id,
            'client_uuid' => 'uuid-abandoned',
            'started_at' => Carbon::parse('2026-01-04 09:00:00', 'UTC'),
            'total_seconds' => 36000,
            'activity_percent' => 90,
            'status' => 'active',
            'source' => 'desktop',
        ]);

        $this->punch($worker, '2026-06-20', '09:00:00', 'clock_in');
        $this->punch($worker, '2026-06-20', '11:00:00', 'clock_out');

        $row = collect(
            $this->actingAs($admin)
                ->getJson('/time-entries/team-activity?start=2026-06-20&end=2026-06-20')
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $worker->id);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['activity_percent'], 'A months-old abandoned session must not weight today’s activity.');
    }

    /**
     * Run a request and return how many SQL statements it issued.
     */
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
}
