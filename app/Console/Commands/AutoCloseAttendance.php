<?php

namespace App\Console\Commands;

use App\Models\AttendanceClockCheck;
use App\Models\TimeEntry;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\AttendanceCloser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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

    /** Users resolved per pass. Keeps the set-based lookups bounded. */
    private const USER_CHUNK = 500;

    public function handle(AttendanceCloser $closer): int
    {
        $capHours = (float) $this->option('cap-hours');
        $idleHours = (float) $this->option('idle-hours');
        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now(\App\Support\BusinessTime::tz());

        $closed = 0;
        $skipped = 0;
        $failed = 0;

        // SCALE: this used to hydrate every user in the tenant and then issue
        // 2-3 individual queries per user, every pass, for every organization,
        // sequentially — ~150,000 queries per pass at 500 orgs x 100 users, so
        // the run could not finish inside its own interval and withoutOverlapping
        // silently began skipping it. Now: users are streamed in bounded chunks
        // and each chunk resolves its state in three set queries.
        //
        // ->active() matters too: deactivated people (who left the company) can
        // have no open clock-in worth closing, and were being scanned every pass
        // for nothing.
        User::query()
            ->active()
            ->when($this->option('user'), fn ($q) => $q->where('id', $this->option('user')))
            ->select(['id', 'name', 'shift_hours'])
            ->orderBy('id')
            ->chunkById(self::USER_CHUNK, function ($users) use ($closer, $capHours, $dry, $now, &$closed, &$skipped, &$failed) {
                $userIds = $users->modelKeys();

                // One query: the newest entry per user, whatever its type.
                $latest = $this->latestEntriesPerUser($userIds, null);

                // Only people whose newest action leaves the session OPEN are
                // candidates. Everything else is already closed.
                $openUserIds = $latest
                    ->filter(fn ($entry) => in_array($entry->action_type, ['clock_in', 'break_start'], true))
                    ->keys()
                    ->all();

                if ($openUserIds === []) {
                    return;
                }

                // One query: the clock-in that started each open session.
                $clockIns = $this->latestEntriesPerUser($openUserIds, ['clock_in']);

                // One query for the whole chunk instead of one per user.
                $checks = AttendanceClockCheck::query()
                    ->whereIn('clock_in_id', $clockIns->pluck('id')->all())
                    ->get()
                    ->keyBy('clock_in_id');

                foreach ($users as $user) {
                    $clockIn = $clockIns->get($user->id);

                    if (! in_array($user->id, $openUserIds, true) || ! $clockIn) {
                        continue; // closed already, or an open break with no clock-in
                    }

                    try {
                        $result = $this->closeIfPastCap($closer, $user, $clockIn, $checks->get($clockIn->id), $capHours, $now, $dry);
                        $result ? $closed++ : $skipped++;
                    } catch (\Throwable $e) {
                        // One person's bad data must not abort the pass for
                        // everyone else in the tenant.
                        $failed++;
                        $this->error(sprintf('  %s: %s', $user->name, $e->getMessage()));
                        Log::error('attendance:auto-clock-out failed for user', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info(sprintf(
            '%s %d open clock-in(s); skipped %d still-active%s.',
            $dry ? 'Would close' : 'Closed',
            $closed,
            $skipped,
            $failed > 0 ? "; {$failed} error(s)" : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Decide and (unless dry) perform the force-close for one open clock-in.
     * Returns true when the session was (or would be) closed.
     */
    private function closeIfPastCap(
        AttendanceCloser $closer,
        User $user,
        TimeEntry $clockIn,
        ?AttendanceClockCheck $check,
        float $capHours,
        Carbon $now,
        bool $dry
    ): bool {
        // If they explicitly confirmed "still working" in Slack and the snooze
        // hasn't expired, trust them and don't force-cap.
        if ($check && $check->confirmed_until && $check->confirmed_until->isFuture()) {
            return false;
        }

        // A person with a long configured shift gets a proportionally longer
        // safety cap (shift + 2h buffer), so a genuine 10h shift is never
        // force-closed on the default 12h backstop. Falls back to the global
        // cap when no per-person shift length is set.
        $userCap = $user->shift_hours
            ? max($capHours, (float) $user->shift_hours + 2)
            : $capHours;

        $clockInTs = Carbon::parse($clockIn->action_timestamp)->setTimezone(\App\Support\BusinessTime::tz());
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
            return false; // still within the day's window — leave them alone
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

        return true;
    }

    /**
     * Greatest-n-per-group: the single newest time entry for each of $userIds,
     * keyed by user_id, in ONE query.
     *
     * Expressed as an anti-join ("no other entry for this user is newer") rather
     * than a window function so it runs identically on MySQL 8 and the local
     * SQLite database. The tie-break on id mirrors the original
     * `orderByDesc('action_timestamp')->orderByDesc('id')` exactly, so two
     * entries sharing a timestamp resolve the same way they always did. Served
     * by the (user_id, action_timestamp, id) index.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, string>|null  $actionTypes
     * @return \Illuminate\Support\Collection<int, TimeEntry>
     */
    private function latestEntriesPerUser(array $userIds, ?array $actionTypes)
    {
        if ($userIds === []) {
            return collect();
        }

        return TimeEntry::query()
            ->whereIn('user_id', $userIds)
            ->when($actionTypes, fn ($q) => $q->whereIn('action_type', $actionTypes))
            ->whereNotExists(function ($sub) use ($userIds, $actionTypes) {
                $sub->selectRaw('1')
                    ->from('time_entries as newer')
                    ->whereColumn('newer.user_id', 'time_entries.user_id')
                    ->whereIn('newer.user_id', $userIds)
                    ->when($actionTypes, fn ($q) => $q->whereIn('newer.action_type', $actionTypes))
                    ->where(function ($w) {
                        $w->whereColumn('newer.action_timestamp', '>', 'time_entries.action_timestamp')
                            ->orWhere(function ($tie) {
                                $tie->whereColumn('newer.action_timestamp', '=', 'time_entries.action_timestamp')
                                    ->whereColumn('newer.id', '>', 'time_entries.id');
                            });
                    });
            })
            ->get()
            ->keyBy('user_id');
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

        return $row ? Carbon::parse($row)->setTimezone(\App\Support\BusinessTime::tz()) : null;
    }

    private function humanHours(float $hours): string
    {
        $mins = max(0, (int) round($hours * 60));

        return $mins >= 60 ? sprintf('%dh %dm', intdiv($mins, 60), $mins % 60) : $mins.'m';
    }
}
