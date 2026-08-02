<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AttendanceCloser;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Someone who comes back from a break is WORKING, not closed.
 *
 * The closer used to treat only clock_in and break_start as "open", so a person
 * whose last punch was break_end (returned from lunch, then forgot to clock out)
 * was invisible to the auto clock-out, the still-working nudge and the Slack
 * "clock me out" button. Their session ran on unbounded and inflated the hours
 * the company bills and pays on — the same class of defect as the shared-PC
 * double-start bug. These tests pin the open-state set.
 */
class AttendanceCloserBreakEndTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(): User
    {
        Plan::ensureSeeded();
        $organization = Organization::factory()->timezone('Asia/Karachi')->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);

        app(CurrentOrganization::class)->runFor($organization, fn () => Subscription::create([
            'plan_id' => Plan::where('key', 'free')->firstOrFail()->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]));

        return User::factory()->create([
            'organization_id' => $organization->id,
            'shift_start_time' => '09:00:00',
        ]);
    }

    /** Punch the clock as the given user, in their business timezone. */
    private function punch(User $user, string $type, string $at): void
    {
        Carbon::setTestNow(Carbon::parse($at, 'Asia/Karachi'));

        $this->actingAs($user)
            ->postJson(route('time-entries.store'), ['action_type' => $type])
            ->assertOk();
    }

    public function test_a_worker_back_from_break_is_still_closeable(): void
    {
        $user = $this->employee();

        $this->punch($user, 'clock_in', '2026-06-10 09:00:00');
        $this->punch($user, 'break_start', '2026-06-10 13:00:00');
        $this->punch($user, 'break_end', '2026-06-10 13:30:00');
        // …and then they forget to clock out.

        Carbon::setTestNow(Carbon::parse('2026-06-10 18:00:00', 'Asia/Karachi'));

        $closed = app(CurrentOrganization::class)->runFor(
            $user->organization_id,
            fn () => app(AttendanceCloser::class)->close(
                $user->id,
                Carbon::parse('2026-06-10 18:00:00', 'Asia/Karachi'),
                'Auto clock-out (forgot).'
            )
        );

        $this->assertNotNull($closed, 'A worker back from break must still be auto-closeable.');
        $this->assertSame('clock_out', $closed->action_type);
        $this->assertSame('2026-06-10', $closed->action_date->toDateString());
    }

    public function test_the_open_clock_in_is_found_after_a_break_ends(): void
    {
        $user = $this->employee();

        $this->punch($user, 'clock_in', '2026-06-10 09:00:00');
        $this->punch($user, 'break_start', '2026-06-10 13:00:00');
        $this->punch($user, 'break_end', '2026-06-10 13:30:00');

        $open = app(CurrentOrganization::class)->runFor(
            $user->organization_id,
            fn () => app(AttendanceCloser::class)->openClockIn($user->id)
        );

        $this->assertNotNull($open, 'Someone back from a break is still clocked in.');
        $this->assertSame('clock_in', $open->action_type);
    }

    public function test_an_already_clocked_out_worker_is_left_alone(): void
    {
        $user = $this->employee();

        $this->punch($user, 'clock_in', '2026-06-10 09:00:00');
        $this->punch($user, 'clock_out', '2026-06-10 17:00:00');

        $before = TimeEntry::count();

        $closed = app(CurrentOrganization::class)->runFor(
            $user->organization_id,
            fn () => app(AttendanceCloser::class)->close(
                $user->id,
                Carbon::parse('2026-06-10 18:00:00', 'Asia/Karachi'),
                'Auto clock-out (forgot).'
            )
        );

        $this->assertNull($closed, 'Nothing was open, so nothing should be written.');
        $this->assertSame($before, TimeEntry::count());
    }
}
