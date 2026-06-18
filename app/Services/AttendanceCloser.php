<?php

namespace App\Services;

use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Closes an open attendance clock-in by writing a real clock_out (and a
 * break_end first if the person was on break) with a plain-language reason.
 * Shared by the auto-clock-out cap command, the Slack still-working check, and
 * the Slack "clock me out" button so the closing rules live in one place.
 */
class AttendanceCloser
{
    /**
     * Close the user's currently-open clock-in at $closeAt with $reason.
     * Returns the clock_out entry, or null if there was nothing open.
     * $closeAt is clamped to never predate the clock-in.
     */
    public function close(int $userId, Carbon $closeAt, string $reason): ?TimeEntry
    {
        $last = TimeEntry::where('user_id', $userId)
            ->orderByDesc('action_timestamp')->orderByDesc('id')
            ->first();

        if (! $last || ! in_array($last->action_type, ['clock_in', 'break_start'], true)) {
            return null; // already closed / nothing open
        }

        $clockIn = TimeEntry::where('user_id', $userId)
            ->where('action_type', 'clock_in')
            ->orderByDesc('action_timestamp')->orderByDesc('id')
            ->first();

        if (! $clockIn) {
            return null;
        }

        $clockInTs = Carbon::parse($clockIn->action_timestamp)->setTimezone('Asia/Karachi');
        if ($closeAt->lessThanOrEqualTo($clockInTs)) {
            $closeAt = $clockInTs->copy()->addMinute();
        }

        $date = $clockIn->action_date instanceof Carbon
            ? $clockIn->action_date->toDateString()
            : (string) $clockIn->action_date;

        return DB::transaction(function () use ($last, $clockIn, $closeAt, $date, $reason) {
            if ($last->action_type === 'break_start') {
                TimeEntry::create([
                    'user_id' => $clockIn->user_id,
                    'action_type' => 'break_end',
                    'action_timestamp' => $closeAt,
                    'action_date' => $date,
                    'action_time' => $closeAt->toTimeString(),
                    'notes' => 'Auto break-end (system close).',
                ]);
            }

            return TimeEntry::create([
                'user_id' => $clockIn->user_id,
                'action_type' => 'clock_out',
                'action_timestamp' => $closeAt,
                'action_date' => $date,
                'action_time' => $closeAt->toTimeString(),
                'notes' => $reason,
            ]);
        });
    }

    /** The open clock-in entry for a user, or null if they're not clocked in. */
    public function openClockIn(int $userId): ?TimeEntry
    {
        $last = TimeEntry::where('user_id', $userId)
            ->orderByDesc('action_timestamp')->orderByDesc('id')
            ->first();

        if (! $last || ! in_array($last->action_type, ['clock_in', 'break_start'], true)) {
            return null;
        }

        return TimeEntry::where('user_id', $userId)
            ->where('action_type', 'clock_in')
            ->orderByDesc('action_timestamp')->orderByDesc('id')
            ->first();
    }
}
