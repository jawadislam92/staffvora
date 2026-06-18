<?php

namespace App\Console\Commands;

use App\Models\AttendanceClockCheck;
use App\Models\TimeEntry;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\AttendanceCloser;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Safety net for forgotten clock-outs.
 *
 * The desktop/web clock is fully manual — it only records the action the user
 * presses. If someone clocks in and never clocks out (closed the app, slept the
 * laptop, or simply forgot), the clock-in hangs open forever: they read as
 * "Working" indefinitely and the day never closes. This closes those dangling
 * clock-ins and, crucially, writes a real clock_out entry with a plain-language
 * REASON, so the Activity log always shows the pair and why it was auto-closed.
 *
 * A clock-out time is never invented out of thin air — it is chosen, in order:
 *   1. Tracker went idle: the tracker sent its last heartbeat >= idle-hours ago
 *      -> clock out at that last activity. (Credits only time they were active.)
 *   2. Never tracked (e.g. executives who don't run the tracker): once the
 *      clock-in is older than cap-hours -> clock out at clock-in + cap.
 * Either way the clock-out never runs past cap-hours, so there are no 60-hour
 * ghosts. A session whose tracker is still alive (idle < idle-hours) is left
 * alone — we never cut off someone who is genuinely mid-shift.
 */
class AutoCloseAttendance extends Command
{
    protected $signature = 'attendance:auto-clock-out
        {--cap-hours=12 : Maximum hours a single clock-in may span before it is force-closed}
        {--idle-hours=2 : Close a tracked session this many hours after its last heartbeat}
        {--user= : Limit to a single user id (for testing)}
        {--dry-run : Report what would be closed without writing anything}';

    protected $description = 'Auto clock-out dangling attendance clock-ins (forgotten clock-outs), recording a reason.';

    public function handle(AttendanceCloser $closer): int
    {
        $capHours = (float) $this->option('cap-hours');
        $idleHours = (float) $this->option('idle-hours');
        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now('Asia/Karachi');

        $users = User::query()
            ->when($this->option('user'), fn ($q) => $q->where('id', $this->option('user')))
            ->get();

        $closed = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $last = TimeEntry::where('user_id', $user->id)
                ->orderByDesc('action_timestamp')->orderByDesc('id')
                ->first();

            // Only act on an OPEN session (latest action is a clock-in or an
            // unfinished break). Anything else is already closed.
            if (! $last || ! in_array($last->action_type, ['clock_in', 'break_start'], true)) {
                continue;
            }

            // The clock-in that started this open session.
            $clockIn = TimeEntry::where('user_id', $user->id)
                ->where('action_type', 'clock_in')
                ->orderByDesc('action_timestamp')->orderByDesc('id')
                ->first();
            if (! $clockIn) {
                continue; // open break with no clock-in — malformed, leave it
            }

            // If they explicitly confirmed "still working" in Slack and the
            // snooze hasn't expired, trust them and don't force-cap.
            $check = AttendanceClockCheck::where('clock_in_id', $clockIn->id)->first();
            if ($check && $check->confirmed_until && $check->confirmed_until->isFuture()) {
                $skipped++;
                continue;
            }

            // A person with a long configured shift gets a proportionally longer
            // safety cap (shift + 2h buffer), so a genuine 10h shift is never
            // force-closed on the default 12h backstop. Falls back to the global
            // cap when no per-person shift length is set.
            $userCap = $user->shift_hours
                ? max($capHours, (float) $user->shift_hours + 2)
                : $capHours;

            $clockInTs = Carbon::parse($clockIn->action_timestamp)->setTimezone('Asia/Karachi');
            $ageHours = $clockInTs->diffInMinutes($now, false) / 60;
            $capAt = $clockInTs->copy()->addMinutes((int) round($userCap * 60));

            // Presence is the CLOCK, not the tracker: a person can keep working
            // (or do non-tracker work) after their tracker goes quiet and clock
            // out manually later. So a quiet tracker NO LONGER triggers a
            // clock-out — that produced contradictory records (an auto clock-out
            // back-dated to the tracker's last activity while the person was
            // still present and clocked out manually hours later). We only
            // force-close at the hard cap; the Slack "still working?" check ASKS
            // the person at ~8h and handles genuinely forgotten clock-outs.
            if ($ageHours < $userCap) {
                $skipped++; // still within the day's window — leave them alone
                continue;
            }

            $closeAt = $capAt->copy();
            $reason = sprintf(
                'Auto clock-out: no manual clock-out; capped at %dh maximum.',
                (int) round($userCap)
            );

            $spanHours = $clockInTs->diffInMinutes($closeAt, false) / 60;
            $this->line(sprintf(
                '%-22s clock-in %s -> %s clock-out (%s) %s',
                $user->name,
                $clockInTs->format('M j g:i A'),
                $closeAt->format('M j g:i A'),
                $this->humanHours($spanHours),
                $dry ? '[dry-run]' : ''
            ));
            $this->line('    reason: '.$reason);

            if (! $dry) {
                $closer->close($user->id, $closeAt, $reason);
                AttendanceClockCheck::where('clock_in_id', $clockIn->id)
                    ->whereNull('resolved_at')
                    ->update(['resolved_at' => $now, 'resolution' => 'closed_elsewhere']);
            }
            $closed++;
        }

        $this->info(sprintf('%s %d open clock-in(s); skipped %d still-active.', $dry ? 'Would close' : 'Closed', $closed, $skipped));

        return self::SUCCESS;
    }

    /** Most recent tracker heartbeat/stop during the open session, or null if never tracked. */
    private function lastTrackerSignal(int $userId, Carbon $clockInTs): ?Carbon
    {
        $row = TrackingSession::where('user_id', $userId)
            ->where(function ($q) use ($clockInTs) {
                $q->where('last_heartbeat_at', '>=', $clockInTs)
                    ->orWhere('stopped_at', '>=', $clockInTs)
                    ->orWhere('started_at', '>=', $clockInTs);
            })
            ->selectRaw('MAX(GREATEST(COALESCE(last_heartbeat_at, started_at), COALESCE(stopped_at, started_at))) as sig')
            ->value('sig');

        return $row ? Carbon::parse($row)->setTimezone('Asia/Karachi') : null;
    }

    private function humanHours(float $hours): string
    {
        $mins = max(0, (int) round($hours * 60));

        return $mins >= 60 ? sprintf('%dh %dm', intdiv($mins, 60), $mins % 60) : $mins.'m';
    }
}
