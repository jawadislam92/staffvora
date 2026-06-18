<?php

namespace Tests\Feature;

use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeEntryOvernightShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_midnight_action_stays_with_the_shift_start_date(): void
    {
        $employee = User::factory()->create([
            'shift_start_time' => '16:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-09 16:00:00', 'Asia/Karachi'));

        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_in'])
            ->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-06-10 01:00:00', 'Asia/Karachi'));

        $this->actingAs($employee)
            ->getJson(route('time-entries.today-summary'))
            ->assertOk()
            ->assertJsonPath('employees.0.total_work_hours', 9);

        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_out'])
            ->assertOk();

        $entries = TimeEntry::query()->orderBy('action_timestamp')->get();

        $this->assertCount(2, $entries);
        $this->assertSame('2026-06-09', $entries[0]->action_date->toDateString());
        $this->assertSame('2026-06-09', $entries[1]->action_date->toDateString());

        $this->actingAs($employee)
            ->getJson(route('time-entries.today'))
            ->assertOk()
            ->assertJsonCount(2, 'entries');

        $this->actingAs($employee)
            ->getJson(route('time-entries.today-summary'))
            ->assertOk()
            ->assertJsonPath('employees.0.total_entries', 2);

        Carbon::setTestNow();
    }

    public function test_unrelated_early_action_is_not_moved_to_the_previous_shift_date(): void
    {
        $employee = User::factory()->create([
            'shift_start_time' => '16:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-10 11:00:00', 'Asia/Karachi'));

        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_in'])
            ->assertOk();

        $entry = TimeEntry::query()->firstOrFail();

        $this->assertSame('2026-06-10', $entry->action_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_early_clock_in_for_a_midnight_shift_attaches_to_the_new_day(): void
    {
        // The reported bug: a 12AM-8AM shift member clocks in at 23:50, the
        // date rolls over, and their clock-in "turns into clocked out" so
        // they have to clock in again after midnight.
        $employee = User::factory()->create([
            'shift_start_time' => '00:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-09 23:50:00', 'Asia/Karachi'));

        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_in'])
            ->assertOk();

        // The entry belongs to the shift's day (June 10), not June 9.
        $this->assertSame('2026-06-10', TimeEntry::query()->firstOrFail()->action_date->toDateString());

        // After midnight the clock-in is still visible as today's — status
        // stays Working, no second clock-in needed.
        Carbon::setTestNow(Carbon::parse('2026-06-10 00:10:00', 'Asia/Karachi'));

        $this->actingAs($employee)
            ->getJson(route('time-entries.today-summary'))
            ->assertOk()
            ->assertJsonPath('employees.0.current_status', 'Working')
            ->assertJsonPath('employees.0.total_entries', 1);

        // Clocking out at end of shift pairs with the original clock-in.
        Carbon::setTestNow(Carbon::parse('2026-06-10 08:00:00', 'Asia/Karachi'));

        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_out'])
            ->assertOk();

        $entries = TimeEntry::query()->orderBy('action_timestamp')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('2026-06-10', $entries[1]->action_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_morning_shift_late_night_work_stays_on_its_own_day(): void
    {
        // An 8AM worker still on at 23:50 is doing same-day overtime, not
        // arriving early for tomorrow.
        $employee = User::factory()->create([
            'shift_start_time' => '08:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-09 23:50:00', 'Asia/Karachi'));

        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_in'])
            ->assertOk();

        $this->assertSame('2026-06-09', TimeEntry::query()->firstOrFail()->action_date->toDateString());

        Carbon::setTestNow();
    }
}
