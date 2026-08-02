<?php

namespace Tests\Feature;

use App\Models\ManualAttendanceMark;
use App\Models\MonitoringSetting;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE BRANCH TABLE for `EmployeeAttendanceController::resolveMonthlyStatus()`.
 *
 * The performance pass re-signatured that method: it no longer derives "is this
 * day in the future / a holiday / a week-off / before tracking started" from a
 * Carbon per cell, nor the employee's shift and joining date from a User per
 * cell — all of it is now pre-derived once per day and once per employee and
 * handed in. The claim is that the DECISION ORDER and every returned
 * code/label/source are unchanged. Nothing about that claim is self-evident
 * from reading the diff, and a monthly grid is ~3,400 cells whose statuses feed
 * payroll.
 *
 * So this asserts the whole table in one request, including the PRECEDENCE
 * between branches (which is where a refactor like this actually breaks):
 *
 *   manual mark  >  future  >  clocked in  >  before joining  >  before the
 *   workspace started tracking  >  public holiday  >  weekly off  >  today's
 *   shift window still open  >  absent / no record
 *
 * June 2026 is chosen because 2026-06-01 is a Monday, so 06-07/14/21/28 are the
 * Sundays and 06-13 is an ordinary Saturday working day.
 */
class MonthlyGridStatusBranchTest extends TestCase
{
    use RefreshDatabase;

    /** 11:00 on Monday 2026-06-15 — mid-month, so past / today / future all exist. */
    private const NOW = '2026-06-15 11:00:00';

    private const TZ = 'Asia/Karachi';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function punch(User $user, string $type, string $dateTime): void
    {
        $instant = Carbon::parse($dateTime, self::TZ);

        TimeEntry::create([
            'user_id' => $user->id,
            'action_type' => $type,
            'action_timestamp' => $instant,
            'action_date' => $instant->toDateString(),
            'action_time' => $instant->format('H:i:s'),
        ]);
    }

    private function mark(User $user, string $date, string $code): void
    {
        ManualAttendanceMark::create([
            'user_id' => $user->id,
            'attendance_date' => $date,
            'status_code' => $code,
            'note' => "marked {$code}",
        ]);
    }

    /** @return array<int,array<string,mixed>> employee rows, keyed by user id */
    private function grid(User $actor, string $month = '2026-06'): array
    {
        return collect(
            $this->actingAs($actor)
                ->getJson(route('employee-attendance.monthly', ['month' => $month]))
                ->assertOk()
                ->json('employees')
        )->keyBy('user_id')->all();
    }

    /**
     * @param  array<int,array{0:string,1:?string,2:string,3:string}>  $table
     *                                                                         date, expected code, expected label, expected source
     */
    private function assertBranchTable(array $employeeRow, array $table, string $who): void
    {
        $byDate = collect($employeeRow['days'])->keyBy('date');

        foreach ($table as [$date, $code, $label, $source]) {
            $cell = $byDate[$date] ?? null;

            $this->assertNotNull($cell, "{$who}: no cell for {$date}");
            $this->assertSame($code, $cell['status_code'], "{$who} {$date}: status_code");
            $this->assertSame($label, $cell['status_label'], "{$who} {$date}: status_label");
            $this->assertSame($source, $cell['source'], "{$who} {$date}: source");
        }
    }

    public function test_every_status_branch_and_its_precedence_resolves_as_documented(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NOW, self::TZ));

        $admin = User::factory()->create(['role' => 'super_admin', 'name' => 'Owner']);

        $ada = User::factory()->create([
            'name' => 'Ada Everybranch',
            'shift_start_time' => '08:00:00',
            'shift_grace_minutes' => 10,
            'joining_date' => null,
        ]);

        $bo = User::factory()->create([
            'name' => 'Bo Latejoiner',
            'shift_start_time' => '09:00:00',
            'shift_grace_minutes' => 0,
            'joining_date' => '2026-06-05',
        ]);

        $cy = User::factory()->create([
            'name' => 'Cy Noshift',
            'shift_start_time' => null,
            'joining_date' => null,
        ]);

        // Workspace policy. Settings are tenant-owned, so the users above must
        // exist (and bind the tenant) before the row materialises.
        MonitoringSetting::current()->update([
            'attendance_tracking_start_date' => '2026-06-03',
            'attendance_holidays' => ['2026-06-11'],
            'attendance_week_off_days' => [Carbon::SUNDAY],
            'attendance_auto_absent' => true,
        ]);
        MonitoringSetting::forgetSharedCache();

        // ── Ada ──────────────────────────────────────────────────────────────
        $this->punch($ada, 'clock_in', '2026-06-02 08:11:00');   // late, and before tracking started
        $this->punch($ada, 'clock_in', '2026-06-03 08:10:00');   // exactly on the grace boundary
        $this->punch($ada, 'clock_out', '2026-06-03 17:10:00');
        $this->punch($ada, 'clock_in', '2026-06-11 08:00:00');   // on the public holiday
        $this->punch($ada, 'clock_in', '2026-06-14 08:05:00');   // on the weekly off
        $this->mark($ada, '2026-06-04', 'L');
        $this->mark($ada, '2026-06-05', 'WFH');
        $this->mark($ada, '2026-06-09', 'HD');
        $this->mark($ada, '2026-06-10', 'PH');
        $this->mark($ada, '2026-06-20', 'L');                    // a manual mark on a FUTURE day

        // ── Bo ───────────────────────────────────────────────────────────────
        $this->punch($bo, 'clock_in', '2026-06-02 09:30:00');    // a punch dated BEFORE he joined

        // ── Cy ───────────────────────────────────────────────────────────────
        $this->punch($cy, 'clock_in', '2026-06-03 23:59:00');    // no shift → lateness is impossible

        $grid = $this->grid($admin);

        $this->assertBranchTable($grid[$ada->id], [
            // date          code    label                 source
            ['2026-06-01', null,  'Not tracked',         'pre-tracking'],  // no punch, before tracking start
            ['2026-06-02', 'LC',  'Late coming',         'automatic'],     // punch beats pre-tracking
            ['2026-06-03', 'P',   'Present',             'automatic'],     // exactly shift+grace is NOT late
            ['2026-06-04', 'L',   'Leave',               'manual'],
            ['2026-06-05', 'WFH', 'Work from home',      'manual'],
            ['2026-06-06', 'A',   'Absent',              'automatic'],     // Saturday is a working day here
            ['2026-06-07', 'H',   'Weekly off',          'automatic'],     // Sunday
            ['2026-06-08', 'A',   'Absent',              'automatic'],
            ['2026-06-09', 'HD',  'Half day',            'manual'],
            ['2026-06-10', 'PH',  'Public holiday',      'manual'],
            ['2026-06-11', 'P',   'Present',             'automatic'],     // worked ON the public holiday
            ['2026-06-14', 'P',   'Present',             'automatic'],     // worked ON the weekly off
            ['2026-06-15', 'A',   'Absent',              'automatic'],     // today, shift window has closed
            ['2026-06-16', null,  'Not set',             'future'],
            ['2026-06-20', 'L',   'Leave',               'manual'],        // manual mark beats "future"
            ['2026-06-21', null,  'Not set',             'future'],        // a future Sunday is still "future"
            ['2026-06-30', null,  'Not set',             'future'],
        ], 'Ada');

        $this->assertBranchTable($grid[$bo->id], [
            ['2026-06-01', 'LI',  'Late joining',        'automatic'],     // joining beats pre-tracking
            ['2026-06-02', 'LC',  'Late coming',         'automatic'],     // a punch beats "before joining"
            ['2026-06-04', 'LI',  'Late joining',        'automatic'],
            ['2026-06-05', 'A',   'Absent',              'automatic'],     // the joining day itself counts
            ['2026-06-07', 'H',   'Weekly off',          'automatic'],
            ['2026-06-11', 'PH',  'Public holiday',      'automatic'],     // the policy holiday, unworked
            ['2026-06-16', null,  'Not set',             'future'],
        ], 'Bo');

        $this->assertBranchTable($grid[$cy->id], [
            ['2026-06-02', null,  'Not tracked',         'pre-tracking'],
            ['2026-06-03', 'P',   'Present',             'automatic'],     // 23:59 with no shift is not late
            ['2026-06-04', 'A',   'Absent',              'automatic'],
            // The load-bearing case: nobody without a configured shift can have
            // an absence "due" today, so today stays pending rather than Absent.
            ['2026-06-15', null,  'Shift not started',   'pending'],
        ], 'Cy');
    }

    /**
     * The per-employee summary is produced by `addStatusToSummary()` walking the
     * same codes, so it is the cheapest end-to-end check that the branch table
     * above is not merely returning plausible labels.
     */
    public function test_the_month_summary_counts_every_branch_exactly_once(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NOW, self::TZ));

        $admin = User::factory()->create(['role' => 'super_admin', 'name' => 'Owner']);
        $ada = User::factory()->create([
            'name' => 'Ada Everybranch',
            'shift_start_time' => '08:00:00',
            'shift_grace_minutes' => 10,
        ]);

        MonitoringSetting::current()->update([
            'attendance_tracking_start_date' => '2026-06-03',
            'attendance_holidays' => ['2026-06-11'],
            'attendance_week_off_days' => [Carbon::SUNDAY],
            'attendance_auto_absent' => true,
        ]);
        MonitoringSetting::forgetSharedCache();

        $this->punch($ada, 'clock_in', '2026-06-02 08:11:00');
        $this->punch($ada, 'clock_in', '2026-06-03 08:10:00');
        $this->punch($ada, 'clock_out', '2026-06-03 17:10:00');
        $this->punch($ada, 'clock_in', '2026-06-11 08:00:00');
        $this->punch($ada, 'clock_in', '2026-06-14 08:05:00');
        $this->mark($ada, '2026-06-04', 'L');
        $this->mark($ada, '2026-06-05', 'WFH');
        $this->mark($ada, '2026-06-09', 'HD');
        $this->mark($ada, '2026-06-10', 'PH');
        $this->mark($ada, '2026-06-20', 'L');

        $row = $this->grid($admin)[$ada->id];

        // total_work_hours is compared separately: it round-trips through JSON,
        // where a whole number of hours arrives back as an int.
        $summary = $row['summary'];
        $this->assertEqualsWithDelta(9.0, $summary['total_work_hours'], 0.0001, 'only 06-03 is a closed session: 08:10 -> 17:10');
        unset($summary['total_work_hours']);

        $this->assertSame([
            // LC counts as both present and late_coming, by design.
            'holidays' => 1,           // 06-07
            'present' => 4,            // 06-02 (LC), 06-03, 06-11, 06-14
            'absent' => 5,             // 06-06, 06-08, 06-12, 06-13, 06-15
            'leave' => 2,              // 06-04, 06-20
            'half_day' => 1,           // 06-09
            'work_from_home' => 1,     // 06-05
            'late_coming' => 1,        // 06-02
            'late_joining' => 0,
            'public_holiday' => 1,     // 06-10
        ], $summary);
    }

    /**
     * The cells still carry the punch times, formatted server-side. The refactor
     * stopped hydrating TimeEntry models, so the Eloquent
     * `formatted_action_time` accessor is no longer available and the format is
     * now applied inline — same output, different code path.
     */
    public function test_cells_still_carry_formatted_punch_times_and_the_manual_note(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NOW, self::TZ));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $ada = User::factory()->create([
            'name' => 'Ada Formatted',
            'shift_start_time' => '08:00:00',
            'shift_grace_minutes' => 10,
        ]);

        $this->punch($ada, 'clock_in', '2026-06-03 08:10:00');
        $this->punch($ada, 'clock_out', '2026-06-03 17:10:00');
        $this->mark($ada, '2026-06-04', 'L');

        $days = collect($this->grid($admin)[$ada->id]['days'])->keyBy('date');

        $this->assertSame('8:10 AM', $days['2026-06-03']['first_clock_in']);
        $this->assertSame('5:10 PM', $days['2026-06-03']['last_action_time']);
        $this->assertEqualsWithDelta(9.0, $days['2026-06-03']['work_hours'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $days['2026-06-03']['break_hours'], 0.0001);

        $this->assertNull($days['2026-06-02']['first_clock_in']);
        $this->assertNull($days['2026-06-02']['last_action_time']);
        $this->assertSame('marked L', $days['2026-06-04']['manual_note']);
    }

    /**
     * The two policy branches the main table cannot cover at the same time: a
     * non-Sunday weekly off, and auto-absent switched off, which turns every
     * unrecorded working day into a neutral "No record" instead of "Absent".
     */
    public function test_configurable_week_off_and_auto_absent_off_resolve_as_documented(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NOW, self::TZ));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $dee = User::factory()->create([
            'name' => 'Dee Norecord',
            'shift_start_time' => '08:00:00',
            'shift_grace_minutes' => 10,
        ]);

        MonitoringSetting::current()->update([
            'attendance_tracking_start_date' => null,
            'attendance_holidays' => [],
            'attendance_week_off_days' => [Carbon::SATURDAY],
            'attendance_auto_absent' => false,
        ]);
        MonitoringSetting::forgetSharedCache();

        $row = $this->grid($admin)[$dee->id];

        $this->assertBranchTable($row, [
            ['2026-06-06', 'H',  'Weekly off', 'automatic'],  // Saturday is now the off day
            ['2026-06-07', null, 'No record',  'no-record'],  // Sunday is now a working day
            ['2026-06-08', null, 'No record',  'no-record'],
            ['2026-06-15', null, 'No record',  'no-record'],  // today, window closed, still not accused
        ], 'Dee');

        $this->assertSame(0, $row['summary']['absent'], 'nobody is branded Absent when the workspace opts out');
        $this->assertSame(2, $row['summary']['holidays'], '06-06 and 06-13 are the June Saturdays before today');
    }

    /**
     * A shift override / a shift change must not leak across employees: the
     * "shift start + grace" instant is memoised per (day, shift, grace) inside
     * the grid, and a cache key that dropped the shift would give everyone the
     * first employee's deadline.
     */
    public function test_the_memoised_shift_deadline_is_not_shared_between_different_shifts(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NOW, self::TZ));

        $admin = User::factory()->create(['role' => 'super_admin']);

        $early = User::factory()->create([
            'name' => 'Early Bird',
            'shift_start_time' => '08:00:00',
            'shift_grace_minutes' => 10,
        ]);

        $late = User::factory()->create([
            'name' => 'Late Starter',
            'shift_start_time' => '13:00:00',
            'shift_grace_minutes' => 10,
        ]);

        // The SAME clock-in time on the same day: late for one shift, early for
        // the other.
        $this->punch($early, 'clock_in', '2026-06-03 09:00:00');
        $this->punch($late, 'clock_in', '2026-06-03 09:00:00');

        $grid = $this->grid($admin);

        $this->assertSame('LC', collect($grid[$early->id]['days'])->firstWhere('date', '2026-06-03')['status_code']);
        $this->assertSame('P', collect($grid[$late->id]['days'])->firstWhere('date', '2026-06-03')['status_code']);

        // …and today, where the deadline decides pending vs absent rather than
        // late vs present: 11:00 is past 08:10 but not past 13:10.
        $this->assertSame('A', collect($grid[$early->id]['days'])->firstWhere('date', '2026-06-15')['status_code']);
        $this->assertSame('pending', collect($grid[$late->id]['days'])->firstWhere('date', '2026-06-15')['source']);
    }
}
