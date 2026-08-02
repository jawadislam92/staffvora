<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;

/**
 * Derives work/break hours, last action, and current status from a chronological
 * collection of clock TimeEntry rows for a SINGLE user. Shared by the dashboard
 * summary, the dashboard trend/team-activity endpoints, and (upstream) the
 * attendance page so the surfaces can't drift.
 *
 * Multi-tenant note: DAY BUCKETING (which punches belong to which attendance
 * day) is the caller's job and is business-timezone aware upstream. What this
 * trait does — measuring elapsed time between punches — is zone-independent, so
 * it works on epoch seconds and never resolves a timezone itself. A Karachi
 * tenant and a New York tenant therefore get identical arithmetic on identical
 * punch sequences, which is the point.
 *
 * An open clock-in/break is counted up to now, capped at 18h so a forgotten
 * clock-out doesn't inflate the figure. The state machine assumes the entries
 * belong to one user in chronological order — callers must group by user before
 * calling this.
 */
trait CalculatesTimeStats
{
    private function calculateTimeStats($entries)
    {
        $totalWorkMinutes = 0;
        $totalBreakMinutes = 0;
        $currentSessionStart = null;
        $currentBreakStart = null;
        $lastAction = null;
        $status = 'Not Started';

        // The state machine measures ELAPSED time between two instants, and
        // elapsed time is timezone-independent — so it runs on epoch seconds
        // (integers) rather than Carbon objects. Nothing here formats a punch,
        // so nothing here needs a zone. Callers that DO format (the grid's
        // first_clock_in / last_action_time) still read the `local_time` Carbon
        // they attached when grouping; entryLocalTime() below is unchanged for
        // them. This matters because the monthly grid runs this state machine
        // once per employee-day cell — thousands of times per request — and
        // Carbon's diff/constructor path is ~20x the cost of integer maths.
        foreach ($entries as $entry) {
            $entryTs = $this->entryTimestamp($entry);
            $lastAction = $entry->action_type;

            switch ($entry->action_type) {
                case 'clock_in':
                    $currentSessionStart = $entryTs;
                    $status = 'Working';
                    break;
                case 'clock_out':
                    if ($currentSessionStart !== null) {
                        $totalWorkMinutes += $this->positiveMinutesBetweenTimestamps($currentSessionStart, $entryTs);
                        $currentSessionStart = null;
                    }
                    $status = 'Clocked Out';
                    break;
                case 'break_start':
                    $currentBreakStart = $entryTs;
                    $status = 'On Break';
                    break;
                case 'break_end':
                    if ($currentBreakStart !== null) {
                        $totalBreakMinutes += $this->positiveMinutesBetweenTimestamps($currentBreakStart, $entryTs);
                        $currentBreakStart = null;
                    }
                    $status = 'Working';
                    break;
            }
        }

        // Resolved LAZILY: most cells on a monthly grid end the day clocked
        // out, so "now" was being constructed thousands of times per request to
        // be thrown away. Carbon::now() (not time()) so Carbon::setTestNow()
        // still controls this in tests.
        $now = null;

        // Include a reasonable overnight session without counting stale clock-ins.
        if ($currentSessionStart !== null) {
            $now ??= Carbon::now()->getTimestamp();
            $ongoingWorkMinutes = $this->positiveMinutesBetweenTimestamps($currentSessionStart, $now);

            if ($ongoingWorkMinutes <= 18 * 60) {
                $totalWorkMinutes += $ongoingWorkMinutes;
            }
        }

        if ($currentBreakStart !== null) {
            $now ??= Carbon::now()->getTimestamp();
            $ongoingBreakMinutes = $this->positiveMinutesBetweenTimestamps($currentBreakStart, $now);

            if ($ongoingBreakMinutes <= 18 * 60) {
                $totalBreakMinutes += $ongoingBreakMinutes;
            }
        }

        // Calculate effective work time (excluding breaks)
        $effectiveWorkMinutes = max(0, $totalWorkMinutes - $totalBreakMinutes);

        return [
            'workHours' => round($effectiveWorkMinutes / 60, 2),
            'breakHours' => round($totalBreakMinutes / 60, 2),
            'lastAction' => $lastAction,
            'status' => $status,
        ];
    }

    /**
     * The punch's instant in the ORGANIZATION's business timezone.
     *
     * Callers that walk the same punch more than once — the monthly attendance
     * grid re-runs this state machine per user-day cell, the dashboard summary
     * runs it three times per user over overlapping today/week/month slices —
     * parse each row ONCE when they group it and hang the result on
     * `local_time`. That re-parsing was the dominant cost on both endpoints
     * (~19k Carbon constructions for one monthly grid). Rows without
     * `local_time` (plain Eloquent models) still parse lazily, so this stays a
     * drop-in for every existing caller.
     *
     * @param  object  $entry  a TimeEntry model or a projected stdClass row
     */
    private function entryLocalTime($entry, string $tz): Carbon
    {
        $preParsed = $entry->local_time ?? null;

        return $preParsed instanceof Carbon
            ? $preParsed
            : Carbon::parse($entry->action_timestamp)->setTimezone($tz);
    }

    /**
     * The punch's instant as epoch seconds.
     *
     * Three accepted forms, cheapest first:
     *  - `local_ts` (int)     — set by callers that never FORMAT a punch (the
     *                           trend endpoint, the team-activity roll-up). One
     *                           strtotime() per row, no object at all.
     *  - `local_time` (Carbon) — set by callers that DO format (the monthly grid
     *                           and the dashboard summary render clock times).
     *  - neither               — a plain Eloquent model; parsed here.
     *
     * No setTimezone() anywhere: an epoch second is the same number in every
     * zone and this value is only ever used for subtraction.
     *
     * @param  object  $entry  a TimeEntry model or a projected stdClass row
     */
    private function entryTimestamp($entry): int
    {
        $ts = $entry->local_ts ?? null;

        if (is_int($ts)) {
            return $ts;
        }

        $preParsed = $entry->local_time ?? null;

        return $preParsed instanceof Carbon
            ? $preParsed->getTimestamp()
            : Carbon::parse($entry->action_timestamp)->getTimestamp();
    }

    private function positiveMinutesBetween(Carbon $start, Carbon $end): int
    {
        return $this->positiveMinutesBetweenTimestamps($start->getTimestamp(), $end->getTimestamp());
    }

    /**
     * Whole minutes from $start to $end, floored, never negative.
     *
     * Integer maths rather than Carbon::diffInMinutes(): identical output (both
     * floor toward the past and both clamp at zero, so the negative branch
     * agrees too) at roughly a twentieth of the cost. On the monthly grid this
     * runs tens of thousands of times per request.
     */
    private function positiveMinutesBetweenTimestamps(int $start, int $end): int
    {
        $seconds = $end - $start;

        return $seconds <= 0 ? 0 : intdiv($seconds, 60);
    }
}
