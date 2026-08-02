<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TimeEntry;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Double-start race guard (ported from the mature timetracker's clock-sequence
 * fix). A clock-in left open past midnight is evening overtime the shift-end
 * auto-close can't retroactively fire for; the old day-scoped last-action
 * lookup saw the new day's empty list as "closed" and let a SECOND clock-in
 * through, orphaning the first (in/in/out) and producing overlapping sessions.
 * The guard now enforces the sequence against TimeEntry::currentClockState()
 * (the GLOBAL latest action) inside a locked transaction, so a still-open
 * clock-in blocks a new one on both the web and desktop clock paths.
 */
class TimeEntryDoubleClockGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employeeInTimezone(string $shiftStart, string $tz = 'Asia/Karachi'): User
    {
        Plan::ensureSeeded();
        $organization = Organization::factory()->timezone($tz)->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);

        app(CurrentOrganization::class)->runFor($organization, fn () => Subscription::create([
            'plan_id' => Plan::where('key', 'free')->firstOrFail()->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]));

        return User::factory()->create([
            'organization_id' => $organization->id,
            'shift_start_time' => $shiftStart,
        ]);
    }

    public function test_open_clock_in_from_last_night_blocks_a_new_clock_in_after_midnight(): void
    {
        $employee = $this->employeeInTimezone('09:00:00');

        // Evening overtime clock-in, never clocked out.
        Carbon::setTestNow(Carbon::parse('2026-07-04 19:26:00', 'Asia/Karachi'));
        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_in'])
            ->assertOk();

        // Past midnight — a new attendance day. A second clock-in must be rejected.
        Carbon::setTestNow(Carbon::parse('2026-07-05 00:40:00', 'Asia/Karachi'));
        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_in'])
            ->assertStatus(422)
            ->assertJsonPath('message', "You're already clocked in — clock out first.");

        // Clocking out closes the still-open session cleanly.
        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_out'])
            ->assertOk();

        // Exactly one clock-in and one clock-out — no orphan, no double.
        $this->assertSame(1, TimeEntry::where('user_id', $employee->id)->where('action_type', 'clock_in')->count());
        $this->assertSame(1, TimeEntry::where('user_id', $employee->id)->where('action_type', 'clock_out')->count());
    }

    public function test_same_day_duplicate_clock_in_is_rejected(): void
    {
        $employee = $this->employeeInTimezone('09:00:00');

        Carbon::setTestNow(Carbon::parse('2026-07-04 09:00:00', 'Asia/Karachi'));

        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_in'])
            ->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-07-04 09:00:02', 'Asia/Karachi'));

        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_in'])
            ->assertStatus(422)
            ->assertJsonPath('message', "You're already clocked in — clock out first.");

        $this->assertSame(1, TimeEntry::where('user_id', $employee->id)->count());
    }

    public function test_desktop_endpoint_also_blocks_the_duplicate_clock_in(): void
    {
        $employee = $this->employeeInTimezone('09:00:00');
        Sanctum::actingAs($employee, ['desktop-tracker']);

        Carbon::setTestNow(Carbon::parse('2026-07-04 19:26:00', 'Asia/Karachi'));
        $this->postJson('/api/desktop/time-clock', ['action_type' => 'clock_in'])->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-07-05 00:40:00', 'Asia/Karachi'));

        // Desktop status offers Clock Out (not a duplicate Clock In).
        $available = $this->getJson('/api/desktop/time-clock')->assertOk()->json('available');
        $this->assertContains('clock_out', $available);
        $this->assertNotContains('clock_in', $available);

        // And the act endpoint rejects a duplicate clock-in.
        $this->postJson('/api/desktop/time-clock', ['action_type' => 'clock_in'])->assertStatus(422);

        $this->assertSame(1, TimeEntry::where('user_id', $employee->id)->where('action_type', 'clock_in')->count());
    }

    public function test_a_properly_closed_prior_day_still_allows_a_fresh_clock_in(): void
    {
        $employee = $this->employeeInTimezone('09:00:00');

        Carbon::setTestNow(Carbon::parse('2026-07-04 09:00:00', 'Asia/Karachi'));
        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_in'])
            ->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-07-04 17:00:00', 'Asia/Karachi'));
        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_out'])
            ->assertOk();

        // Next day: a fresh clock-in is allowed (last GLOBAL action was clock_out).
        Carbon::setTestNow(Carbon::parse('2026-07-05 09:00:00', 'Asia/Karachi'));
        $this->actingAs($employee)
            ->postJson(route('time-entries.store'), ['action_type' => 'clock_in'])
            ->assertOk();

        $this->assertSame(2, TimeEntry::where('user_id', $employee->id)->where('action_type', 'clock_in')->count());
    }
}
