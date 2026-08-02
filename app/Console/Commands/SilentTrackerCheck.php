<?php

namespace App\Console\Commands;

use App\Models\DesktopDiagnostic;
use App\Models\MonitoringSetting;
use App\Models\TimeEntry;
use App\Models\TrackingActivitySample;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\SlackBotService;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Capture-health watchdog — BREAK-AWARE.
 *
 * A tracker can keep sending heartbeats (so it looks "live", with time and an
 * activity percentage) while its screenshot / sample helpers are blocked by
 * antivirus. The person looks tracked, but the day has no screenshots and no
 * apps, and nobody finds out until payroll. This finds those sessions in real
 * time and posts a Slack alert. It alerts at most once per session.
 *
 * WHY IT MUST NOT CRY WOLF
 * The naive version of this check alerted on any live session that had not
 * captured recently, and immediately started spamming false "antivirus block"
 * alarms — most obviously while people were on their break, when the tracker is
 * SUPPOSED to capture nothing. Once a monitoring alert is noisy it stops being
 * read, which is worse than not having it. So the watchdog now stays silent for
 * every legitimate reason a session captures nothing:
 *
 *   1. ON BREAK      — `break_start` is the person's latest clock action. The
 *                      desktop can keep the tracked-seconds counter advancing
 *                      during a break, which would otherwise fool the
 *                      paused-vs-blocked test below into a false alarm (the
 *                      original reported case).
 *   2. NOT CLOCKED IN— latest action is `clock_out`, or there is none at all.
 *   3. OUTSIDE SHIFT — resolved with the real precedence: one-day ShiftOverride
 *                      > named Shift > the per-user `shift_start_time` column
 *                      (User::effectiveShiftFor). No schedule configured means
 *                      no suppression.
 *   4. ARCHIVED USER — deactivated people are never alerted about.
 *   5. PAUSED / IDLE — tracked seconds frozen since the last pass. The desktop
 *                      freezes total_seconds while paused and advances it while
 *                      active, so comparing it against `health_probe_seconds`
 *                      (this command's memory of the previous run) separates a
 *                      paused tracker from one that is counting time but not
 *                      capturing. Only the latter is an antivirus block.
 *
 * It DOES alert when someone is clocked in, inside their shift, actively
 * counting time, and has captured nothing for longer than the threshold.
 *
 * SCALE: sessions stream in bounded chunks; each chunk resolves last-capture
 * times, users (with shifts and same-window overrides eager-loaded) and clock
 * states in a fixed number of set queries — never per session. A Slack outage or
 * one malformed record is isolated per session. Cross-tenant isolation is
 * `tenant:run`'s job: it binds each organization in turn and one tenant's
 * failure is logged without aborting the others.
 *
 * DIAGNOSTICS: alerts always write a `tracker-health` row explaining the
 * decision. Per-decision traces are opt-in (`--trace`) precisely so this cannot
 * become an unpruned telemetry firehose — 500 tenants × 100 users × 96 runs a
 * day would be millions of rows nobody reads.
 */
class SilentTrackerCheck extends Command
{
    protected $signature = 'monitoring:silent-tracker-check
        {--minutes= : Flag a live session with no screenshot/sample for this long (default: services.attendance.tracker_health_silent_minutes, 20)}
        {--live-minutes= : How recent a heartbeat must be for the session to count as live (default: services.attendance.tracker_health_live_minutes, 5)}
        {--user= : Limit to one user id (testing)}
        {--force : Ignore the already-alerted guard (re-alert; testing)}
        {--trace : Record a tracker-health diagnostic for every decision, not just alerts}
        {--dry-run : Report without posting or marking}';

    protected $description = 'Alert when a live tracker is counting time but capturing nothing (likely antivirus block). Silent for breaks, clock-outs and off-shift.';

    /** Sessions resolved per pass. Keeps the grouped lookups bounded. */
    private const SESSION_CHUNK = 500;

    /**
     * Tracked seconds must advance by more than this between two passes before
     * the session counts as "actively counting time". A small margin absorbs
     * heartbeat rounding without letting a frozen counter look alive.
     */
    private const PROBE_ADVANCE_SECONDS = 60;

    /** Assumed shift length when a start time is configured but no hours are. */
    private const DEFAULT_SHIFT_HOURS = 8.0;

    /**
     * Slack after a shift's nominal end still counts as "on shift" — people
     * routinely run over, and alerting is better than a silent gap.
     */
    private const SHIFT_TAIL_MINUTES = 90;

    /** Clock states that mean the person is working right now. */
    private const WORKING_STATES = ['clock_in', 'break_end'];

    public function handle(SlackBotService $slack): int
    {
        $minutes = $this->intOption('minutes', (int) config('services.attendance.tracker_health_silent_minutes', 20));
        $liveMinutes = $this->intOption('live-minutes', (int) config('services.attendance.tracker_health_live_minutes', 5));
        $minutes = max(1, $minutes);
        $liveMinutes = max(1, $liveMinutes);

        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $trace = (bool) $this->option('trace');

        $now = Carbon::now(BusinessTime::tz());
        $cutoff = $now->copy()->subMinutes($minutes);
        $liveCutoff = $now->copy()->subMinutes($liveMinutes);

        $flagged = 0;
        $skipped = 0;
        $failed = 0;

        TrackingSession::query()
            ->where('status', TrackingSession::STATUS_ACTIVE)
            ->where('started_at', '<=', $cutoff)            // running long enough to have captured
            ->where('last_heartbeat_at', '>=', $liveCutoff) // genuinely live (heartbeats arriving)
            ->when(! $force, fn ($q) => $q->whereNull('health_alerted_at'))
            ->when($this->option('user'), fn ($q) => $q->where('user_id', $this->option('user')))
            ->orderBy('id')
            ->chunkById(self::SESSION_CHUNK, function ($sessions) use ($slack, $cutoff, $now, $dry, $trace, &$flagged, &$skipped, &$failed) {
                $sessionIds = $sessions->modelKeys();
                $userIds = $sessions->pluck('user_id')->filter()->unique()->values()->all();

                $lastShots = $this->lastCaptureBySession(TrackingScreenshot::query(), $sessionIds);
                $lastSamples = $this->lastCaptureBySession(TrackingActivitySample::query(), $sessionIds);

                // Only ACTIVE users are hydrated: an archived/deactivated person
                // is simply absent from the map, and an absent user is never
                // alerted about.
                $users = User::query()
                    ->active()
                    ->whereIn('id', $userIds)
                    ->with([
                        'shift',
                        // Bound the override load to the two business days a
                        // running session can belong to. `$user->shiftOverrides`
                        // is otherwise an unbounded per-user history read inside
                        // effectiveShiftFor().
                        'shiftOverrides' => fn ($q) => $q->whereBetween('date', [
                            $now->copy()->subDay()->toDateString(),
                            $now->copy()->addDay()->toDateString(),
                        ]),
                    ])
                    ->get()
                    ->keyBy('id');

                $clockStates = $this->latestClockStatePerUser($userIds);

                foreach ($sessions as $session) {
                    try {
                        $outcome = $this->evaluate($session, $users, $clockStates, $lastShots, $lastSamples, $cutoff, $now, $dry, $trace, $slack);

                        if ($outcome === 'alerted') {
                            $flagged++;
                        } elseif ($outcome === 'skipped') {
                            $skipped++;
                        }
                    } catch (\Throwable $e) {
                        // A Slack outage (or one malformed record) must not abort
                        // the sweep for every other machine in the tenant.
                        $failed++;
                        $this->error(sprintf('  session #%d: %s', $session->id, $e->getMessage()));
                        Log::error('monitoring:silent-tracker-check failed for session', [
                            'tracking_session_id' => $session->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info(sprintf(
            '%s %d silent tracker(s); %d suppressed (break/off-shift/paused)%s.',
            $dry ? 'Would flag' : 'Flagged',
            $flagged,
            $skipped,
            $failed > 0 ? "; {$failed} error(s)" : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Decide what to do about one live session.
     *
     * @return string 'alerted' | 'skipped' | 'capturing'
     */
    private function evaluate(
        TrackingSession $session,
        $users,
        $clockStates,
        $lastShots,
        $lastSamples,
        Carbon $cutoff,
        Carbon $now,
        bool $dry,
        bool $trace,
        SlackBotService $slack,
    ): string {
        $last = collect([$lastShots->get($session->id), $lastSamples->get($session->id)])
            ->filter()
            ->map(fn ($d) => Carbon::parse($d))
            ->max();

        if ($last && $last->gte($cutoff)) {
            return 'capturing'; // healthy — capturing normally
        }

        $user = $users->get($session->user_id);

        // (4) Archived / deactivated: nothing to alert anyone about.
        if (! $user) {
            $this->trace($trace, $session, 'skipped: user archived or deactivated', $last, $now, $dry);

            return 'skipped';
        }

        $name = $user->name;
        $state = $clockStates->get($session->user_id);

        // (2) Not clocked in at all — a tracker left running after clock-out
        // legitimately captures nothing.
        if ($state === null || $state === 'clock_out') {
            $this->line(sprintf('%-22s no capture but NOT clocked in → skip', $name));
            $this->trace($trace, $session, 'skipped: not clocked in ('.($state ?? 'no punches').')', $last, $now, $dry);

            return 'skipped';
        }

        // (1) On break. Capturing nothing is the correct behaviour, and the
        // desktop may keep the seconds counter advancing through the break, so
        // the paused-vs-blocked test below would otherwise misfire. Re-baseline
        // the probe so the seconds accrued during the break are not read as
        // "advanced" on the next pass.
        if ($state === 'break_start') {
            $this->line(sprintf('%-22s no capture but ON BREAK → skip', $name));
            $this->rebaseline($session, $dry);
            $this->trace($trace, $session, 'skipped: on break', $last, $now, $dry);

            return 'skipped';
        }

        if (! in_array($state, self::WORKING_STATES, true)) {
            $this->trace($trace, $session, 'skipped: unrecognised clock state ('.$state.')', $last, $now, $dry);

            return 'skipped';
        }

        // (3) Outside the person's shift window.
        if (! $this->withinShiftWindow($user, $now)) {
            $this->line(sprintf('%-22s no capture but OUTSIDE SHIFT → skip', $name));
            $this->rebaseline($session, $dry);
            $this->trace($trace, $session, 'skipped: outside shift window', $last, $now, $dry);

            return 'skipped';
        }

        // (5) Paused vs blocked. The desktop freezes total_seconds while paused
        // and advances it while active, so compare against the snapshot taken on
        // the previous pass:
        //     unchanged → paused / idle          → no alert
        //     advanced  → working, not capturing → antivirus block → alert
        // The first time a session is seen silent we only record the baseline;
        // one pass of latency is the price of never crying wolf.
        $previous = $session->health_probe_seconds;
        $current = (int) $session->total_seconds;
        $advanced = $previous !== null && $current > ((int) $previous + self::PROBE_ADVANCE_SECONDS);

        if ($dry) {
            $label = $previous === null ? 'baseline' : ($advanced ? 'counting time → would alert' : 'paused/idle → skip');
            $this->line(sprintf('%-22s no capture, %s [dry-run]', $name, $label));

            return $advanced ? 'alerted' : 'skipped';
        }

        if (! $advanced) {
            $this->rebaseline($session, false);
            $this->trace($trace, $session, $previous === null ? 'skipped: probe baseline recorded' : 'skipped: paused/idle (tracked seconds frozen)', $last, $now, false);

            return 'skipped';
        }

        $ageMinutes = (int) round($session->started_at->diffInMinutes($now));
        $this->line(sprintf('%-22s live %dm, counting time but no capture (likely AV block)', $name, $ageMinutes));

        $this->announce($slack, $user, $session, $last);

        // Query-builder update (NOT $session->save()) so the datetime casts
        // cannot re-serialize started_at / last_heartbeat_at into another zone.
        TrackingSession::whereKey($session->id)->update([
            'health_probe_seconds' => $current,
            'health_alerted_at' => $now,
        ]);

        $this->record($session, 'ALERTED: counting time but capturing nothing (likely antivirus block)', $last, $now, 'error');

        return 'alerted';
    }

    /**
     * Is `now` inside this person's working window? Shift precedence is resolved
     * by User::effectiveShiftFor (one-day override > named shift > the per-user
     * column). A person with no configured start time has no window, so nothing
     * is suppressed for them.
     */
    private function withinShiftWindow(User $user, Carbon $now): bool
    {
        $shift = $user->effectiveShiftFor($now->toDateString());
        $start = $shift['start_time'] ?? null;

        if (! $start) {
            return true;
        }

        $hours = $shift['hours'] !== null ? (float) $shift['hours'] : self::DEFAULT_SHIFT_HOURS;
        $grace = (int) ($shift['grace_minutes'] ?? 0);
        $length = (int) round(max(0.5, $hours) * 60) + self::SHIFT_TAIL_MINUTES;

        $windowStart = $now->copy()->startOfDay()
            ->setTimeFromTimeString($start->format('H:i:s'))
            ->subMinutes(max(0, $grace));

        if ($now->betweenIncluded($windowStart, $windowStart->copy()->addMinutes($length))) {
            return true;
        }

        // A night shift started yesterday is still running after midnight.
        $yesterdayStart = $windowStart->copy()->subDay();

        return $now->betweenIncluded($yesterdayStart, $yesterdayStart->copy()->addMinutes($length));
    }

    /**
     * Latest clock action per user (clock_in | clock_out | break_start |
     * break_end), for a whole chunk, in ONE query — the set-based equivalent of
     * TimeEntry::currentClockState() called per user.
     *
     * Written as an anti-join rather than a window function so it runs
     * identically on MySQL 8 and the local SQLite database, and is served by the
     * (user_id, action_timestamp, id) index. Ties on action_timestamp break on
     * id, matching currentClockState()'s ordering exactly.
     *
     * @param  array<int, int>  $userIds
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function latestClockStatePerUser(array $userIds)
    {
        if ($userIds === []) {
            return collect();
        }

        return TimeEntry::query()
            ->whereIn('user_id', $userIds)
            ->whereNotExists(function ($sub) use ($userIds) {
                $sub->selectRaw('1')
                    ->from('time_entries as newer')
                    ->whereColumn('newer.user_id', 'time_entries.user_id')
                    ->whereIn('newer.user_id', $userIds)
                    ->where(function ($w) {
                        $w->whereColumn('newer.action_timestamp', '>', 'time_entries.action_timestamp')
                            ->orWhere(function ($tie) {
                                $tie->whereColumn('newer.action_timestamp', '=', 'time_entries.action_timestamp')
                                    ->whereColumn('newer.id', '>', 'time_entries.id');
                            });
                    });
            })
            ->get(['user_id', 'action_type'])
            ->pluck('action_type', 'user_id');
    }

    /**
     * Latest captured_at per tracking session, for a whole chunk, in one grouped
     * query — replacing one MAX() round trip per session.
     *
     * @param  array<int, int>  $sessionIds
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function lastCaptureBySession($query, array $sessionIds)
    {
        if ($sessionIds === []) {
            return collect();
        }

        return $query
            ->whereIn('tracking_session_id', $sessionIds)
            ->groupBy('tracking_session_id')
            ->selectRaw('tracking_session_id, MAX(captured_at) as last_captured_at')
            ->pluck('last_captured_at', 'tracking_session_id');
    }

    /** Snapshot the current tracked seconds for the next pass to compare against. */
    private function rebaseline(TrackingSession $session, bool $dry): void
    {
        if ($dry) {
            return;
        }

        TrackingSession::whereKey($session->id)->update([
            'health_probe_seconds' => (int) $session->total_seconds,
        ]);
    }

    /** Per-decision diagnostic — opt-in, so this never becomes a firehose. */
    private function trace(bool $enabled, TrackingSession $session, string $decision, ?Carbon $last, Carbon $now, bool $dry): void
    {
        if (! $enabled || $dry) {
            return;
        }

        $this->record($session, $decision, $last, $now, 'info');
    }

    /**
     * Write a tracker-health diagnostic: the full picture behind the decision, so
     * "why did I get this notification?" is answerable at a glance instead of
     * being reconstructed by hand from the database.
     */
    private function record(TrackingSession $session, string $decision, ?Carbon $last, Carbon $now, string $level): void
    {
        DesktopDiagnostic::record([
            'organization_id' => $session->organization_id,
            'user_id' => $session->user_id,
            'category' => DesktopDiagnostic::CATEGORY_TRACKER_HEALTH,
            'level' => $level,
            'event' => 'tracker_health_decision',
            'summary' => sprintf('%s — session #%d (%s)', $decision, $session->id, $session->device_name ?: 'unknown device'),
            'message' => $decision,
            'device_name' => $session->device_name,
            'context' => [
                'session' => [
                    'id' => $session->id,
                    'user_id' => $session->user_id,
                    'device_name' => $session->device_name,
                    'source' => $session->source,
                    'started_at' => (string) $session->started_at,
                    'last_heartbeat_at' => (string) $session->last_heartbeat_at,
                    'total_seconds' => (int) $session->total_seconds,
                    'health_probe_seconds' => $session->health_probe_seconds,
                    'seconds_advanced_since_probe' => $session->health_probe_seconds !== null
                        ? (int) $session->total_seconds - (int) $session->health_probe_seconds
                        : null,
                    'session_age_min' => $session->started_at ? (int) round($session->started_at->diffInMinutes($now)) : null,
                ],
                'capture' => [
                    'last_capture_at' => $last?->toDateTimeString(),
                    'minutes_since_last_capture' => $last ? (int) round($last->diffInMinutes($now)) : null,
                ],
                'evaluated_at' => $now->toDateTimeString(),
            ],
            'captured_at' => now(),
        ]);
    }

    private function announce(SlackBotService $slack, ?User $user, TrackingSession $session, ?Carbon $last): void
    {
        // The tenant's own configured attendance channel wins; the env values are
        // the operator-wide fallback for single-workspace installs.
        $channel = MonitoringSetting::current()->slack_attendance_channel
            ?: config('services.attendance.tracker_health_channel')
            ?: config('services.attendance.clockin_channel');

        if (! $channel) {
            return;
        }

        $lastText = $last ? $last->format('g:i A') : 'never';

        $slack->postToChannel($channel, sprintf(
            ":warning: *%s*'s tracker is live and *actively counting time* (clocked in, not on break) but has captured *no screenshots/activity* since %s — likely an antivirus block on that machine. Device: %s. Session started %s.",
            $user?->name ?? 'Someone',
            $lastText,
            $session->device_name ?: 'unknown',
            $session->started_at->format('g:i A'),
        ));
    }

    private function intOption(string $name, int $default): int
    {
        $raw = $this->option($name);

        return ($raw === null || $raw === '') ? $default : (int) $raw;
    }
}
