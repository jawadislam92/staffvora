<?php

namespace Tests\Feature;

use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The monthly grid endpoint now defaults to a WEEKLY window so the attendance
 * page fits a phone and the payload scales with the visible window instead of
 * the whole calendar (its ~770 KB month was the biggest single payload in the
 * app). These lock in that new contract WITHOUT regressing the month view every
 * pre-existing caller still asks for by passing `month`.
 */
class AttendanceWeeklyWindowTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Karachi';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** With no parameters at all, the grid is the current Monday..Sunday week. */
    public function test_the_default_window_is_the_current_week(): void
    {
        // 2026-06-17 is a Wednesday; its week runs Mon 2026-06-15 .. Sun 2026-06-21.
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', self::TZ));

        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory()->create(['name' => 'Week Window User']);

        $response = $this->actingAs($admin)
            ->getJson(route('employee-attendance.monthly'))
            ->assertOk();

        $response->assertJsonPath('range', 'week');
        $response->assertJsonPath('start', '2026-06-15');
        $response->assertJsonPath('end', '2026-06-21');

        $days = $response->json('days');
        $this->assertCount(7, $days, 'A week window must ship exactly 7 day columns.');
        $this->assertSame('2026-06-15', $days[0]['date']);
        $this->assertSame('2026-06-21', $days[6]['date']);

        // Every employee row carries only the 7 visible days, not a whole month.
        foreach ($response->json('employees') as $employee) {
            $this->assertCount(7, $employee['days'], 'Each employee row must only carry the visible week.');
        }
    }

    /** An explicit anchor date snaps to that day's Monday..Sunday week. */
    public function test_an_explicit_week_anchor_snaps_to_its_monday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', self::TZ));

        $admin = User::factory()->create(['role' => 'super_admin']);

        // A Sunday must still resolve to the week that Sunday CLOSES (Mon 06-08).
        $response = $this->actingAs($admin)
            ->getJson(route('employee-attendance.monthly', ['range' => 'week', 'date' => '2026-06-14']))
            ->assertOk();

        $response->assertJsonPath('start', '2026-06-08');
        $response->assertJsonPath('end', '2026-06-14');
        $this->assertCount(7, $response->json('days'));
    }

    /**
     * Passing `month` (every pre-existing bookmark and the page's Month toggle)
     * still returns the full month — the backwards-compatible fallback.
     */
    public function test_passing_month_still_returns_the_full_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', self::TZ));

        $admin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($admin)
            ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
            ->assertOk();

        $response->assertJsonPath('range', 'month');
        $this->assertCount(30, $response->json('days'), 'June has 30 days and the month view must ship them all.');
    }

    /** An explicit range=month with no month still means "this month". */
    public function test_explicit_month_range_defaults_to_the_current_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', self::TZ));

        $admin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($admin)
            ->getJson(route('employee-attendance.monthly', ['range' => 'month']))
            ->assertOk();

        $response->assertJsonPath('range', 'month');
        $this->assertCount(30, $response->json('days'));
    }

    /**
     * The whole point: the weekly default is dramatically smaller than the month
     * for the same tenant and the same worked days.
     */
    public function test_the_weekly_payload_is_much_smaller_than_the_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:00:00', self::TZ));

        $admin = User::factory()->create(['role' => 'super_admin']);

        // A handful of people with a punch each so both windows have real rows.
        foreach (range(1, 8) as $i) {
            $worker = User::factory()->create(['name' => "Payload User {$i}"]);
            TimeEntry::create([
                'user_id' => $worker->id,
                'action_type' => 'clock_in',
                'action_timestamp' => Carbon::parse('2026-06-16 09:00:00', self::TZ),
                'action_date' => '2026-06-16',
                'action_time' => '09:00:00',
            ]);
        }

        $week = $this->actingAs($admin)
            ->getJson(route('employee-attendance.monthly', ['range' => 'week', 'date' => '2026-06-17']))
            ->assertOk();
        $month = $this->actingAs($admin)
            ->getJson(route('employee-attendance.monthly', ['month' => '2026-06']))
            ->assertOk();

        $weekBytes = strlen($week->getContent());
        $monthBytes = strlen($month->getContent());

        // 7 days vs 30 — the per-employee day arrays are the bulk of the payload,
        // so the week must be well under half the month.
        $this->assertLessThan($monthBytes * 0.5, $weekBytes,
            "Weekly payload ({$weekBytes} bytes) must be far smaller than monthly ({$monthBytes} bytes).");
    }
}
