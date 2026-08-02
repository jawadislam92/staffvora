<?php

namespace Tests\Feature;

use App\Http\Controllers\Concerns\CalculatesTimeStats;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * REGRESSION GUARD for the Carbon -> epoch-second rewrite of
 * {@see CalculatesTimeStats}.
 *
 * The trait's state machine used to build Carbon objects per punch and call
 * `diffInMinutes()`; it now subtracts integers. That swap is invisible in the
 * benchmark (it only got faster) and invisible in the UI (the numbers are the
 * same) — which is exactly why it needs pinning: a future "tidy-up" that
 * reintroduces `diffInMinutes()` without `absolute: false`, or that resolves
 * "now" with `time()` instead of `Carbon::now()`, would silently change payroll
 * arithmetic on four surfaces at once (the dashboard summary, the dashboard
 * trend, team-activity, and every cell of the monthly attendance grid).
 *
 * Nothing here touches the database on purpose: this is the arithmetic, not
 * the query.
 */
class CalculateTimeStatsArithmeticTest extends TestCase
{
    private TimeStatsProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->probe = new TimeStatsProbe;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Punches as the callers actually shape them.
     *
     * `local_ts` is what the trend/team-activity endpoints attach (an int, no
     * object at all); `local_time` is what the grid and the dashboard summary
     * attach (a Carbon, because they also FORMAT the punch). Both must land on
     * the same instant.
     */
    private function punch(string $type, string $at, string $shape = 'local_ts', string $tz = 'Asia/Karachi'): object
    {
        $instant = Carbon::parse($at, $tz);

        $row = (object) [
            'action_type' => $type,
            'action_timestamp' => $instant->copy()->format('Y-m-d H:i:s'),
        ];

        if ($shape === 'local_ts') {
            $row->local_ts = $instant->getTimestamp();
        } elseif ($shape === 'local_time') {
            $row->local_time = $instant->copy();
        }

        return $row;
    }

    /** @param  array<int,array{0:string,1:string}>  $punches */
    private function stats(array $punches, string $shape = 'local_ts'): array
    {
        return $this->probe->stats(collect(array_map(
            fn (array $p) => $this->punch($p[0], $p[1], $shape),
            $punches
        )));
    }

    public function test_a_closed_day_totals_work_and_break_exactly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 20:00:00', 'Asia/Karachi'));

        $stats = $this->stats([
            ['clock_in', '2026-06-08 09:00:00'],
            ['break_start', '2026-06-08 13:00:00'],
            ['break_end', '2026-06-08 13:45:00'],
            ['clock_out', '2026-06-08 18:00:00'],
        ]);

        // 09:00-18:00 = 9h gross, 45m break -> 8.25h effective.
        $this->assertSame(8.25, $stats['workHours']);
        $this->assertSame(0.75, $stats['breakHours']);
        $this->assertSame('clock_out', $stats['lastAction']);
        $this->assertSame('Clocked Out', $stats['status']);
    }

    public function test_multiple_sessions_and_breaks_in_one_day_accumulate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 23:00:00', 'Asia/Karachi'));

        $stats = $this->stats([
            ['clock_in', '2026-06-08 08:00:00'],
            ['break_start', '2026-06-08 10:00:00'],
            ['break_end', '2026-06-08 10:30:00'],
            ['clock_out', '2026-06-08 12:00:00'],
            ['clock_in', '2026-06-08 14:00:00'],
            ['break_start', '2026-06-08 15:00:00'],
            ['break_end', '2026-06-08 15:15:00'],
            ['clock_out', '2026-06-08 18:00:00'],
        ]);

        // 4h + 4h gross = 8h, breaks 30m + 15m = 45m -> 7.25h effective.
        $this->assertSame(7.25, $stats['workHours']);
        $this->assertSame(0.75, $stats['breakHours']);
    }

    public function test_an_open_clock_in_counts_up_to_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 14:30:00', 'Asia/Karachi'));

        $stats = $this->stats([
            ['clock_in', '2026-06-08 09:00:00'],
        ]);

        $this->assertSame(5.5, $stats['workHours']);
        $this->assertSame('Working', $stats['status']);
    }

    public function test_an_open_break_counts_up_to_now_and_is_deducted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 13:30:00', 'Asia/Karachi'));

        $stats = $this->stats([
            ['clock_in', '2026-06-08 09:00:00'],
            ['break_start', '2026-06-08 13:00:00'],
        ]);

        // Work runs 09:00 -> now (4.5h); the break runs 13:00 -> now (0.5h) and
        // is subtracted, so effective work is 4.0h.
        $this->assertSame(4.0, $stats['workHours']);
        $this->assertSame(0.5, $stats['breakHours']);
        $this->assertSame('On Break', $stats['status']);
    }

    /**
     * The 18h cap, on BOTH sides of the boundary. `<= 18*60` is inclusive, so
     * exactly 18h still counts and 18h01m is discarded whole — it is a cap on
     * INCLUSION, not a clamp to 18h, and that distinction is what a rewrite is
     * most likely to get wrong.
     */
    public function test_an_open_clock_in_of_exactly_eighteen_hours_still_counts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-09 03:00:00', 'Asia/Karachi'));

        $stats = $this->stats([['clock_in', '2026-06-08 09:00:00']]);

        $this->assertSame(18.0, $stats['workHours']);
    }

    public function test_an_open_clock_in_past_eighteen_hours_is_discarded_not_clamped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-09 03:01:00', 'Asia/Karachi'));

        $stats = $this->stats([['clock_in', '2026-06-08 09:00:00']]);

        $this->assertSame(0.0, $stats['workHours']);
        $this->assertSame('Working', $stats['status']);
    }

    public function test_an_open_break_past_eighteen_hours_is_discarded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-09 07:01:00', 'Asia/Karachi'));

        $stats = $this->stats([
            ['clock_in', '2026-06-08 09:00:00'],
            ['break_start', '2026-06-08 13:00:00'],
        ]);

        // The clock-in is 22h old and the break 18h01m old: both over the cap,
        // both dropped, and nothing goes negative.
        $this->assertSame(0.0, $stats['workHours']);
        $this->assertSame(0.0, $stats['breakHours']);
    }

    public function test_out_of_order_punches_clamp_to_zero_and_never_go_negative(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 20:00:00', 'Asia/Karachi'));

        $stats = $this->stats([
            ['clock_in', '2026-06-08 15:00:00'],
            ['clock_out', '2026-06-08 09:00:00'],
            ['break_start', '2026-06-08 12:00:00'],
            ['break_end', '2026-06-08 11:00:00'],
        ]);

        $this->assertSame(0.0, $stats['workHours']);
        $this->assertSame(0.0, $stats['breakHours']);
    }

    public function test_a_break_longer_than_the_worked_time_floors_effective_work_at_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 20:00:00', 'Asia/Karachi'));

        $stats = $this->stats([
            ['clock_in', '2026-06-08 09:00:00'],
            ['clock_out', '2026-06-08 10:00:00'],
            ['break_start', '2026-06-08 11:00:00'],
            ['break_end', '2026-06-08 14:00:00'],
        ]);

        $this->assertSame(0.0, $stats['workHours']);
        $this->assertSame(3.0, $stats['breakHours']);
    }

    /**
     * `Carbon::setTestNow()` must still steer the open-session branch. The
     * rewrite resolves "now" lazily, and the obvious lazy form — `time()` —
     * would ignore the test clock and make every open-session assertion in the
     * suite depend on the wall clock.
     */
    public function test_the_open_session_branch_honours_carbon_set_test_now(): void
    {
        $punches = [['clock_in', '2026-06-08 09:00:00']];

        Carbon::setTestNow(Carbon::parse('2026-06-08 11:00:00', 'Asia/Karachi'));
        $early = $this->stats($punches);

        Carbon::setTestNow(Carbon::parse('2026-06-08 17:00:00', 'Asia/Karachi'));
        $late = $this->stats($punches);

        $this->assertSame(2.0, $early['workHours']);
        $this->assertSame(8.0, $late['workHours']);
        $this->assertNotSame($early['workHours'], $late['workHours']);
    }

    /**
     * A closed day resolves no "now" at all. This is the whole point of the
     * lazy `$now ??=`: the monthly grid runs this state machine once per
     * employee-day cell and most cells end clocked out.
     */
    public function test_a_closed_day_is_unaffected_by_the_clock(): void
    {
        $punches = [
            ['clock_in', '2026-06-08 09:00:00'],
            ['clock_out', '2026-06-08 17:00:00'],
        ];

        Carbon::setTestNow(Carbon::parse('2026-06-08 17:05:00', 'Asia/Karachi'));
        $justAfter = $this->stats($punches);

        Carbon::setTestNow(Carbon::parse('2030-01-01 00:00:00', 'Asia/Karachi'));
        $yearsLater = $this->stats($punches);

        $this->assertSame(8.0, $justAfter['workHours']);
        $this->assertEquals($justAfter, $yearsLater);
    }

    /**
     * Elapsed time is timezone-independent. The same instants described from a
     * Karachi tenant and a New York tenant must produce identical minutes —
     * this is the property that lets the trait drop `setTimezone()` entirely.
     */
    public function test_the_arithmetic_is_timezone_independent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 20:00:00', 'UTC'));

        $karachi = $this->probe->stats(collect([
            $this->punch('clock_in', '2026-06-08 09:00:00', 'local_ts', 'Asia/Karachi'),
            $this->punch('clock_out', '2026-06-08 17:00:00', 'local_ts', 'Asia/Karachi'),
        ]));

        // The same two instants, named in New York wall-clock terms.
        $newYork = $this->probe->stats(collect([
            $this->punch('clock_in', '2026-06-08 00:00:00', 'local_ts', 'America/New_York'),
            $this->punch('clock_out', '2026-06-08 08:00:00', 'local_ts', 'America/New_York'),
        ]));

        $this->assertSame(8.0, $karachi['workHours']);
        $this->assertSame($karachi['workHours'], $newYork['workHours']);
    }

    /**
     * The three row shapes entryTimestamp() accepts must all reduce to the same
     * epoch second, or the endpoints that attach `local_ts` would silently
     * disagree with the ones that attach `local_time`.
     */
    public function test_every_accepted_row_shape_yields_identical_stats(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 20:00:00', 'Asia/Karachi'));

        $punches = [
            ['clock_in', '2026-06-08 09:00:00'],
            ['break_start', '2026-06-08 12:00:00'],
            ['break_end', '2026-06-08 12:30:00'],
            ['clock_out', '2026-06-08 17:00:00'],
        ];

        $byTs = $this->stats($punches, 'local_ts');
        $byCarbon = $this->stats($punches, 'local_time');
        $byRawParse = $this->stats($punches, 'raw');

        $this->assertSame(7.5, $byTs['workHours']);
        $this->assertEquals($byTs, $byCarbon);
        $this->assertEquals($byTs, $byRawParse);
    }

    public function test_entry_timestamp_prefers_the_cheapest_shape_but_agrees_with_the_others(): void
    {
        $expected = Carbon::parse('2026-06-08 09:00:00', 'Asia/Karachi')->getTimestamp();

        $this->assertSame($expected, $this->probe->timestamp($this->punch('clock_in', '2026-06-08 09:00:00', 'local_ts')));
        $this->assertSame($expected, $this->probe->timestamp($this->punch('clock_in', '2026-06-08 09:00:00', 'local_time')));
        $this->assertSame($expected, $this->probe->timestamp($this->punch('clock_in', '2026-06-08 09:00:00', 'raw')));
    }

    /**
     * `positiveMinutesBetween(Carbon, Carbon)` keeps its signature because the
     * Slack/attendance session builder still calls it with Carbons. It must
     * floor toward the past and clamp at zero — the two properties
     * `diffInMinutes()` provided.
     */
    public function test_positive_minutes_between_floors_and_clamps(): void
    {
        $base = Carbon::parse('2026-06-08 09:00:00', 'Asia/Karachi');

        $this->assertSame(0, $this->probe->minutes($base, $base));
        $this->assertSame(0, $this->probe->minutes($base, $base->copy()->addSeconds(59)));
        $this->assertSame(1, $this->probe->minutes($base, $base->copy()->addSeconds(60)));
        $this->assertSame(1, $this->probe->minutes($base, $base->copy()->addSeconds(119)));
        $this->assertSame(90, $this->probe->minutes($base, $base->copy()->addMinutes(90)));
        $this->assertSame(0, $this->probe->minutes($base, $base->copy()->subMinutes(30)));
    }

    public function test_an_empty_punch_list_is_a_not_started_day(): void
    {
        $stats = $this->probe->stats(collect());

        $this->assertSame(0.0, $stats['workHours']);
        $this->assertSame(0.0, $stats['breakHours']);
        $this->assertNull($stats['lastAction']);
        $this->assertSame('Not Started', $stats['status']);
    }

    /**
     * An unmatched `clock_out` / `break_end` (the first punch of the day landed
     * in the previous bucket) must not invent time.
     */
    public function test_an_unmatched_closing_punch_adds_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 20:00:00', 'Asia/Karachi'));

        $stats = $this->stats([
            ['break_end', '2026-06-08 09:30:00'],
            ['clock_out', '2026-06-08 10:00:00'],
        ]);

        $this->assertSame(0.0, $stats['workHours']);
        $this->assertSame(0.0, $stats['breakHours']);
        $this->assertSame('Clocked Out', $stats['status']);
    }
}

/**
 * The trait's methods are private, which is correct — they are controller
 * internals, not API. This probe is the smallest possible seam that lets the
 * arithmetic be tested without loosening that.
 */
class TimeStatsProbe
{
    use CalculatesTimeStats;

    public function stats($entries): array
    {
        return $this->calculateTimeStats($entries);
    }

    public function timestamp(object $entry): int
    {
        return $this->entryTimestamp($entry);
    }

    public function minutes(Carbon $start, Carbon $end): int
    {
        return $this->positiveMinutesBetween($start, $end);
    }
}
