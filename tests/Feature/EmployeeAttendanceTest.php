<?php

namespace Tests\Feature;

use App\Models\ManualAttendanceAudit;
use App\Models\ManualAttendanceMark;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmployeeAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_summary_never_returns_negative_break_time_for_future_entries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00', 'Asia/Karachi'));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create(['name' => 'Future Entry User']);

        TimeEntry::create([
            'user_id' => $employee->id,
            'action_type' => 'clock_in',
            'action_timestamp' => Carbon::parse('2026-06-08 15:00:00', 'Asia/Karachi'),
            'action_date' => '2026-06-08',
            'action_time' => '15:00:00',
        ]);

        TimeEntry::create([
            'user_id' => $employee->id,
            'action_type' => 'break_start',
            'action_timestamp' => Carbon::parse('2026-06-08 16:00:00', 'Asia/Karachi'),
            'action_date' => '2026-06-08',
            'action_time' => '16:00:00',
        ]);

        $employeeRow = collect(
            $this->actingAs($admin)
                ->getJson(route('employee-attendance.summary', ['date' => '2026-06-08']))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $employee->id);

        $this->assertEquals(0.0, $employeeRow['total_work_hours']);
        $this->assertEquals(0.0, $employeeRow['total_break_hours']);

        Carbon::setTestNow();
    }

    public function test_attendance_summary_calculates_completed_work_and_break_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 14:00:00', 'Asia/Karachi'));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create(['name' => 'Completed Entry User']);

        foreach ([
            ['clock_in', '09:00:00'],
            ['break_start', '10:00:00'],
            ['break_end', '10:30:00'],
            ['clock_out', '12:00:00'],
        ] as [$action, $time]) {
            TimeEntry::create([
                'user_id' => $employee->id,
                'action_type' => $action,
                'action_timestamp' => Carbon::parse("2026-06-08 {$time}", 'Asia/Karachi'),
                'action_date' => '2026-06-08',
                'action_time' => $time,
            ]);
        }

        $employeeRow = collect(
            $this->actingAs($admin)
                ->getJson(route('employee-attendance.summary', ['date' => '2026-06-08']))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $employee->id);

        $this->assertSame(2.5, $employeeRow['total_work_hours']);
        $this->assertSame(0.5, $employeeRow['total_break_hours']);
        $this->assertSame('Clocked Out', $employeeRow['current_status']);

        Carbon::setTestNow();
    }

    public function test_super_admin_can_manually_mark_monthly_attendance(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'permissions' => []]);
        $employee = User::factory()->create(['name' => 'Manual Mark User']);

        $this->actingAs($superAdmin)
            ->patchJson(route('employee-attendance.manual-status'), [
                'user_id' => $employee->id,
                'date' => '2026-06-08',
                'status_code' => 'L',
            ])
            ->assertOk();

        $employeeRow = collect(
            $this->actingAs($superAdmin)
                ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $employee->id);

        $day = collect($employeeRow['days'])->firstWhere('date', '2026-06-08');

        $this->assertSame('L', $day['status_code']);
        $this->assertSame('manual', $day['source']);
        $this->assertSame(1, $employeeRow['summary']['leave']);
    }

    public function test_admin_with_manual_mark_permission_can_mark_attendance(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'permissions' => ['attendance.manual_mark'],
        ]);
        $employee = User::factory()->create();

        $this->actingAs($admin)
            ->patchJson(route('employee-attendance.manual-status'), [
                'user_id' => $employee->id,
                'date' => '2026-06-08',
                'status_code' => 'WFH',
            ])
            ->assertOk();
    }

    public function test_manual_attendance_status_changes_are_audited(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create(['name' => 'Audited Attendance User']);

        $this->actingAs($superAdmin)
            ->patchJson(route('employee-attendance.manual-status'), [
                'user_id' => $employee->id,
                'date' => '2026-06-08',
                'status_code' => 'L',
                'note' => 'Approved leave',
            ])
            ->assertOk();

        $this->actingAs($superAdmin)
            ->patchJson(route('employee-attendance.manual-status'), [
                'user_id' => $employee->id,
                'date' => '2026-06-08',
                'status_code' => 'WFH',
                'note' => 'Remote day',
            ])
            ->assertOk();

        $this->actingAs($superAdmin)
            ->patchJson(route('employee-attendance.manual-status'), [
                'user_id' => $employee->id,
                'date' => '2026-06-08',
                'status_code' => null,
            ])
            ->assertOk();

        $this->assertTrue(ManualAttendanceAudit::query()
            ->where('user_id', $employee->id)
            ->whereDate('attendance_date', '2026-06-08')
            ->whereNull('old_status_code')
            ->where('new_status_code', 'L')
            ->where('changed_by_user_id', $superAdmin->id)
            ->where('reason', 'Approved leave')
            ->exists());
        $this->assertTrue(ManualAttendanceAudit::query()
            ->where('user_id', $employee->id)
            ->whereDate('attendance_date', '2026-06-08')
            ->where('old_status_code', 'L')
            ->where('new_status_code', 'WFH')
            ->where('changed_by_user_id', $superAdmin->id)
            ->where('reason', 'Remote day')
            ->exists());
        $this->assertTrue(ManualAttendanceAudit::query()
            ->where('user_id', $employee->id)
            ->whereDate('attendance_date', '2026-06-08')
            ->where('old_status_code', 'WFH')
            ->whereNull('new_status_code')
            ->where('changed_by_user_id', $superAdmin->id)
            ->where('reason', 'Remote day')
            ->exists());

        $history = $this->actingAs($superAdmin)
            ->getJson(route('employee-attendance.manual-history', ['month' => '2026-06']))
            ->assertOk()
            ->json('history');

        $this->assertCount(3, $history);
        $this->assertSame('Audited Attendance User', $history[0]['employee_name']);
        $this->assertSame('WFH', $history[0]['old_status_code']);
        $this->assertNull($history[0]['new_status_code']);
    }

    public function test_member_cannot_view_manual_attendance_history(): void
    {
        $member = User::factory()->create([
            'role' => 'member',
            'permissions' => ['attendance.view'],
        ]);

        $this->actingAs($member)
            ->getJson(route('employee-attendance.manual-history', ['month' => '2026-06']))
            ->assertForbidden();
    }

    public function test_member_cannot_manually_mark_attendance(): void
    {
        $member = User::factory()->create([
            'role' => 'member',
            'permissions' => ['attendance.view'],
        ]);
        $employee = User::factory()->create();

        $this->actingAs($member)
            ->patchJson(route('employee-attendance.manual-status'), [
                'user_id' => $employee->id,
                'date' => '2026-06-08',
                'status_code' => 'L',
            ])
            ->assertForbidden();
    }

    public function test_super_admin_can_apply_and_replace_a_selected_users_calendar_range(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $selectedEmployee = User::factory()->create(['name' => 'Selected Calendar User']);
        $otherEmployee = User::factory()->create(['name' => 'Other Calendar User']);

        $this->actingAs($superAdmin)
            ->postJson(route('employee-attendance.calendar'), [
                'operation' => 'apply',
                'scope' => 'selected',
                'user_ids' => [$selectedEmployee->id],
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-12',
                'status_code' => 'L',
                'note' => 'Approved leave',
            ])
            ->assertOk()
            ->assertJsonPath('affected', 3);

        $this->assertDatabaseCount('manual_attendance_marks', 3);
        $this->assertDatabaseMissing('manual_attendance_marks', [
            'user_id' => $otherEmployee->id,
        ]);

        $this->actingAs($superAdmin)
            ->postJson(route('employee-attendance.calendar'), [
                'operation' => 'apply',
                'scope' => 'selected',
                'user_ids' => [$selectedEmployee->id],
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-12',
                'status_code' => 'WFH',
            ])
            ->assertOk()
            ->assertJsonPath('affected', 3);

        $this->assertDatabaseCount('manual_attendance_marks', 3);
        $this->assertTrue(
            ManualAttendanceMark::query()
                ->where('user_id', $selectedEmployee->id)
                ->whereDate('attendance_date', '2026-06-10')
                ->where('status_code', 'WFH')
                ->exists()
        );
        $this->assertSame(6, ManualAttendanceAudit::query()->count());
        $this->assertTrue(ManualAttendanceAudit::query()
            ->where('user_id', $selectedEmployee->id)
            ->whereDate('attendance_date', '2026-06-10')
            ->where('old_status_code', 'L')
            ->where('new_status_code', 'WFH')
            ->where('changed_by_user_id', $superAdmin->id)
            ->exists());
    }

    public function test_super_admin_can_clear_a_company_calendar_range(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $employeeOne = User::factory()->create();
        $employeeTwo = User::factory()->create();

        foreach ([$employeeOne, $employeeTwo] as $employee) {
            $this->actingAs($superAdmin)
                ->patchJson(route('employee-attendance.manual-status'), [
                    'user_id' => $employee->id,
                    'date' => '2026-06-10',
                    'status_code' => 'PH',
                ])
                ->assertOk();
        }

        $this->actingAs($superAdmin)
            ->postJson(route('employee-attendance.calendar'), [
                'operation' => 'clear',
                'scope' => 'company',
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-10',
            ])
            ->assertOk()
            ->assertJsonPath('affected', 2);

        $this->assertDatabaseCount('manual_attendance_marks', 0);
        $this->assertDatabaseCount('manual_attendance_audits', 4);
        $this->assertTrue(ManualAttendanceAudit::query()
            ->where('user_id', $employeeOne->id)
            ->whereDate('attendance_date', '2026-06-10')
            ->where('old_status_code', 'PH')
            ->whereNull('new_status_code')
            ->where('changed_by_user_id', $superAdmin->id)
            ->exists());
    }

    public function test_member_cannot_update_the_attendance_calendar(): void
    {
        $member = User::factory()->create([
            'role' => 'member',
            'permissions' => ['attendance.view'],
        ]);

        $this->actingAs($member)
            ->postJson(route('employee-attendance.calendar'), [
                'operation' => 'apply',
                'scope' => 'company',
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-10',
                'status_code' => 'H',
            ])
            ->assertForbidden();
    }

    public function test_monthly_grid_marks_late_coming_when_first_clock_in_exceeds_shift_grace(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00', 'Asia/Karachi'));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'name' => 'Late Shift User',
            'shift_start_time' => '08:00:00',
            'shift_grace_minutes' => 10,
        ]);

        TimeEntry::create([
            'user_id' => $employee->id,
            'action_type' => 'clock_in',
            'action_timestamp' => Carbon::parse('2026-06-08 08:11:00', 'Asia/Karachi'),
            'action_date' => '2026-06-08',
            'action_time' => '08:11:00',
        ]);

        $employeeRow = collect(
            $this->actingAs($admin)
                ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $employee->id);

        $day = collect($employeeRow['days'])->firstWhere('date', '2026-06-08');

        $this->assertSame('LC', $day['status_code']);
        $this->assertSame('automatic', $day['source']);
        $this->assertSame(1, $employeeRow['summary']['late_coming']);
        $this->assertSame(0, $employeeRow['summary']['late_joining']);
        $this->assertSame(1, $employeeRow['summary']['present']);

        Carbon::setTestNow();
    }

    public function test_monthly_grid_keeps_present_when_first_clock_in_is_within_shift_grace(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00', 'Asia/Karachi'));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'name' => 'On Time Shift User',
            'shift_start_time' => '08:00:00',
            'shift_grace_minutes' => 10,
        ]);

        TimeEntry::create([
            'user_id' => $employee->id,
            'action_type' => 'clock_in',
            'action_timestamp' => Carbon::parse('2026-06-08 08:10:00', 'Asia/Karachi'),
            'action_date' => '2026-06-08',
            'action_time' => '08:10:00',
        ]);

        $employeeRow = collect(
            $this->actingAs($admin)
                ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $employee->id);

        $day = collect($employeeRow['days'])->firstWhere('date', '2026-06-08');

        $this->assertSame('P', $day['status_code']);
        $this->assertSame(1, $employeeRow['summary']['present']);
        $this->assertSame(0, $employeeRow['summary']['late_coming']);
        $this->assertSame(0, $employeeRow['summary']['late_joining']);

        Carbon::setTestNow();
    }

    public function test_monthly_grid_marks_dates_before_joining_date_as_late_joining(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00', 'Asia/Karachi'));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'name' => 'New Joiner User',
            'joining_date' => '2026-06-06',
            'shift_start_time' => '08:00:00',
            'shift_grace_minutes' => 10,
        ]);

        TimeEntry::create([
            'user_id' => $employee->id,
            'action_type' => 'clock_in',
            'action_timestamp' => Carbon::parse('2026-06-06 08:00:00', 'Asia/Karachi'),
            'action_date' => '2026-06-06',
            'action_time' => '08:00:00',
        ]);

        $employeeRow = collect(
            $this->actingAs($admin)
                ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $employee->id);

        $this->assertSame('LI', collect($employeeRow['days'])->firstWhere('date', '2026-06-01')['status_code']);
        $this->assertSame('LI', collect($employeeRow['days'])->firstWhere('date', '2026-06-05')['status_code']);
        $this->assertSame('P', collect($employeeRow['days'])->firstWhere('date', '2026-06-06')['status_code']);
        $this->assertSame(5, $employeeRow['summary']['late_joining']);
        $this->assertSame(1, $employeeRow['summary']['present']);

        Carbon::setTestNow();
    }

    public function test_current_day_stays_pending_before_shift_and_grace_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-09 11:00:00', 'Asia/Karachi'));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'name' => 'Afternoon Shift User',
            'shift_start_time' => '12:00:00',
            'shift_grace_minutes' => 15,
        ]);

        $employeeRow = collect(
            $this->actingAs($admin)
                ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $employee->id);

        $day = collect($employeeRow['days'])->firstWhere('date', '2026-06-09');

        $this->assertNull($day['status_code']);
        $this->assertSame('Shift not started', $day['status_label']);
        $this->assertSame('pending', $day['source']);
        $this->assertSame(7, $employeeRow['summary']['absent']);

        Carbon::setTestNow();
    }

    public function test_current_day_becomes_absent_after_shift_and_grace_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-09 12:16:00', 'Asia/Karachi'));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'name' => 'Missed Afternoon Shift User',
            'shift_start_time' => '12:00:00',
            'shift_grace_minutes' => 15,
        ]);

        $employeeRow = collect(
            $this->actingAs($admin)
                ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $employee->id);

        $day = collect($employeeRow['days'])->firstWhere('date', '2026-06-09');

        $this->assertSame('A', $day['status_code']);
        $this->assertSame(8, $employeeRow['summary']['absent']);

        Carbon::setTestNow();
    }

    public function test_current_day_without_configured_shift_stays_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-09 23:00:00', 'Asia/Karachi'));

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'name' => 'Unconfigured Shift User',
            'shift_start_time' => null,
        ]);

        $employeeRow = collect(
            $this->actingAs($admin)
                ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
                ->assertOk()
                ->json('employees')
        )->firstWhere('user_id', $employee->id);

        $day = collect($employeeRow['days'])->firstWhere('date', '2026-06-09');

        $this->assertNull($day['status_code']);
        $this->assertSame('pending', $day['source']);
        $this->assertSame(7, $employeeRow['summary']['absent']);

        Carbon::setTestNow();
    }

    public function test_super_admin_can_send_monthly_attendance_to_slack(): void
    {
        config(['services.slack_reports.webhook_url' => 'https://hooks.slack.test/services/example']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok'),
        ]);

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create(['name' => 'Slack Attendance User']);

        TimeEntry::create([
            'user_id' => $employee->id,
            'action_type' => 'clock_in',
            'action_timestamp' => Carbon::parse('2026-06-01 09:00:00', 'Asia/Karachi'),
            'action_date' => '2026-06-01',
            'action_time' => '09:00:00',
        ]);

        TimeEntry::create([
            'user_id' => $employee->id,
            'action_type' => 'clock_out',
            'action_timestamp' => Carbon::parse('2026-06-01 17:00:00', 'Asia/Karachi'),
            'action_date' => '2026-06-01',
            'action_time' => '17:00:00',
        ]);

        $this->actingAs($admin)
            ->postJson(route('employee-attendance.slack'), [
                'month' => '2026-06',
                'user_ids' => [$employee->id],
                'include_fields' => ['present', 'absent', 'total_work_hours'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Attendance report sent to Slack for June 2026.');

        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/services/example'
            && $request['text'] === 'Attendance Report | June 2026 | 1 users');
    }

    public function test_attendance_slack_report_counts_late_coming_from_shift_grace(): void
    {
        config(['services.slack_reports.webhook_url' => 'https://hooks.slack.test/services/example']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok'),
        ]);

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'name' => 'Slack Late User',
            'shift_start_time' => '08:00:00',
            'shift_grace_minutes' => 10,
        ]);

        TimeEntry::create([
            'user_id' => $employee->id,
            'action_type' => 'clock_in',
            'action_timestamp' => Carbon::parse('2026-06-01 08:11:00', 'Asia/Karachi'),
            'action_date' => '2026-06-01',
            'action_time' => '08:11:00',
        ]);

        $this->actingAs($admin)
            ->postJson(route('employee-attendance.slack'), [
                'month' => '2026-06',
                'user_ids' => [$employee->id],
                'include_fields' => ['present', 'late_coming'],
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            $tableBlock = collect($request['blocks'])->firstWhere('type', 'table');
            $row = $tableBlock['rows'][1] ?? [];

            return $request->url() === 'https://hooks.slack.test/services/example'
                && ($row[0]['text'] ?? null) === 'Slack Late User'
                && ($row[1]['text'] ?? null) === '1'
                && ($row[2]['text'] ?? null) === '1';
        });
    }

    public function test_attendance_slack_does_not_count_current_day_absent_before_shift_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-09 11:00:00', 'Asia/Karachi'));
        config(['services.slack_reports.webhook_url' => 'https://hooks.slack.test/services/example']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok'),
        ]);

        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'name' => 'Slack Afternoon Shift User',
            'shift_start_time' => '12:00:00',
            'shift_grace_minutes' => 15,
        ]);

        $this->actingAs($admin)
            ->postJson(route('employee-attendance.slack'), [
                'month' => '2026-06',
                'user_ids' => [$employee->id],
                'include_fields' => ['absent'],
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            $tableBlock = collect($request['blocks'])->firstWhere('type', 'table');
            $row = $tableBlock['rows'][1] ?? [];

            return ($row[0]['text'] ?? null) === 'Slack Afternoon Shift User'
                && ($row[1]['text'] ?? null) === '7';
        });

        Carbon::setTestNow();
    }
}
