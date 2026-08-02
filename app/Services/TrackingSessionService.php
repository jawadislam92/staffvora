<?php

namespace App\Services;

use App\Models\TrackingActivitySample;
use App\Models\TrackingAuditLog;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\UpworkProfile;
use App\Models\WorkHour;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrackingSessionService
{
    /**
     * How far a desktop clock may run ahead of the server before we stop
     * believing it. Small, because a future timestamp has no legitimate use.
     */
    public const CLOCK_SKEW_SECONDS = 300;

    /**
     * How far back a client timestamp may reach. The desktop app queues events
     * while offline, so a genuinely old value is possible — but a day is the
     * outer edge of "my laptop was offline", and everything beyond it is either
     * a broken system clock or fabrication.
     */
    public const MAX_BACKDATE_SECONDS = 86400;

    /**
     * Slack added on top of server-measured elapsed time before a claimed
     * duration is considered impossible. Covers request latency, retries and
     * a heartbeat interval or two.
     */
    public const CLAIM_GRACE_SECONDS = 300;

    /** Absolute ceiling for a single session, regardless of elapsed time. */
    public const MAX_SESSION_SECONDS = 86400;

    /** Most calendar days one session may be split across in work_hours. */
    public const MAX_SPAN_DAYS = 31;

    /**
     * Turn a client-supplied ISO timestamp into a value the server is willing to
     * store. Out-of-window values are CLAMPED (not rejected) so a machine with a
     * skewed clock degrades to "now" instead of losing the ability to track,
     * which is what the desktop app's offline queue depends on.
     *
     * This is the single choke point for every timestamp the desktop agent
     * sends: session start/stop, heartbeats, screenshots and activity samples.
     * BusinessTime::fromClient() itself accepts year 1 through year 9999.
     */
    public static function clampClientTime(
        ?string $iso,
        ?CarbonInterface $min = null,
        ?CarbonInterface $max = null,
    ): Carbon {
        $value = BusinessTime::fromClient($iso) ?? now();

        $min = $min ? Carbon::instance($min)->copy() : now()->subSeconds(self::MAX_BACKDATE_SECONDS);
        $max = $max ? Carbon::instance($max)->copy() : now()->addSeconds(self::CLOCK_SKEW_SECONDS);

        if ($max->lessThan($min)) {
            $max = $min->copy();
        }

        if ($value->lessThan($min)) {
            self::logClamp($iso, $min);

            return $min;
        }

        if ($value->greaterThan($max)) {
            self::logClamp($iso, $max);

            return $max;
        }

        return $value;
    }

    private static function logClamp(?string $iso, Carbon $to): void
    {
        Log::warning('Desktop client timestamp out of range; clamped.', [
            'supplied' => $iso,
            'clamped_to' => $to->toIso8601String(),
        ]);
    }

    /**
     * The instant a session's time is measured from. Server-observed
     * `created_at` is authoritative; the client's `started_at` may only move the
     * anchor EARLIER, and it has already been clamped to a bounded window at
     * start, so an offline-queued session keeps its real elapsed time while a
     * backdated one cannot buy unlimited hours.
     */
    public function serverAnchorFor(TrackingSession $session): CarbonInterface
    {
        $created = $session->created_at;
        $started = $session->started_at;

        if ($created && $started) {
            return $started->lessThan($created) ? $started : $created;
        }

        return $started ?? $created ?? now();
    }

    /**
     * Clamp a client-claimed duration to what the wall clock can actually
     * support. `total_seconds` arrives from software running on the employee's
     * own machine, so it is attacker-controlled: without this, POSTing
     * total_seconds=360000 mints 100 billable hours from a 4-second session.
     *
     * Decreases are allowed through untouched — idle deduction and admin
     * screenshot deletion legitimately lower a session's time.
     */
    public function clampTotalSeconds(TrackingSession $session, int $claimed, ?CarbonInterface $until = null): int
    {
        $until = $until ?? now();
        $anchor = $this->serverAnchorFor($session);

        $elapsed = max(0, $until->getTimestamp() - $anchor->getTimestamp());
        $ceiling = (int) min($elapsed + self::CLAIM_GRACE_SECONDS, self::MAX_SESSION_SECONDS);

        return max(0, min($claimed, $ceiling));
    }

    /**
     * Record that a client claimed more time than the clock allows. This is an
     * anomaly trail, not a gate — the write still proceeds with the clamped
     * value so a clock-skew false positive never destroys real hours, but an
     * admin can see who is over-reporting.
     */
    public function recordClampAnomaly(TrackingSession $session, int $claimed, int $applied, string $action): void
    {
        Log::warning('Desktop client claimed more tracked time than elapsed; clamped.', [
            'tracking_session_id' => $session->id,
            'user_id' => $session->user_id,
            'claimed_seconds' => $claimed,
            'applied_seconds' => $applied,
            'action' => $action,
        ]);

        try {
            TrackingAuditLog::record([
                'tracking_session_id' => $session->id,
                'subject_user_id' => $session->user_id,
                'actor_user_id' => null,
                'action' => 'session.total_seconds_clamped',
                'event_date' => BusinessTime::dateKey($session->started_at) ?? now()->toDateString(),
                'old_value' => ['claimed_seconds' => $claimed, 'source' => $action],
                'new_value' => ['applied_seconds' => $applied],
                'reason' => 'Claimed duration exceeded server-measured elapsed time.',
            ]);
        } catch (\Throwable $e) {
            // Never let the audit trail break tracking.
            Log::error('Failed to write tracking clamp audit row.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Completely remove a tracking session and every trace of it: activity
     * samples, screenshots, and the mirrored work-hours row. Used when an
     * admin deletes a bogus session, when a work-diary entry that mirrors a
     * session is deleted, and when screenshot deletion empties a session to
     * crumbs. Returns the seconds that were removed.
     */
    public function purge(TrackingSession $session): int
    {
        $removed = (int) $session->total_seconds;

        TrackingActivitySample::where('tracking_session_id', $session->id)->delete();
        TrackingScreenshot::where('tracking_session_id', $session->id)->delete();
        WorkHour::where('tracking_session_id', $session->id)->delete();
        $session->delete();

        return $removed;
    }

    /**
     * Close a session and mirror it into work_hours, exactly like a normal
     * desktop "stop". Used both by the stop endpoint and the stale-session
     * sweep (sessions whose desktop app died without sending a stop).
     */
    public function finalize(TrackingSession $session, ?CarbonInterface $stoppedAt = null): void
    {
        DB::transaction(function () use ($session, $stoppedAt) {
            $session->update([
                'stopped_at' => $stoppedAt ?? $session->last_heartbeat_at ?? $session->started_at,
                'status' => TrackingSession::STATUS_STOPPED,
            ]);

            $this->syncWorkHour($session->fresh());
        });
    }

    /**
     * Mirror a finished tracking session into the work_hours table so the
     * existing Report sheet auto-populates without manual entry.
     *
     * An overnight session is split into one row per calendar day it spans,
     * each day getting its wall-clock share of the tracked time — the exact
     * same allocation the Timeline uses (inDaySeconds). Without this, a night
     * shift that started at 9:47 PM would dump all its hours on the start date,
     * so the next day's Report/Diary/Dashboard would be missing them while the
     * Timeline showed them. We delete-then-recreate so re-syncs (e.g. after a
     * screenshot deletion changes the total) stay idempotent. Manual entries
     * (no tracking_session_id) are never touched.
     *
     * The delete + re-create pair runs in one transaction: without it a failure
     * partway through (a decimal overflow on `hours`, a timeout) leaves the
     * session inflated with no matching work_hours rows, and the Timeline and
     * the Work Hours report disagree permanently.
     */
    public function syncWorkHour(TrackingSession $session): void
    {
        DB::transaction(function () use ($session) {
            WorkHour::where('tracking_session_id', $session->id)->delete();

            if (! $session->total_seconds || $session->total_seconds < 60) {
                return;
            }

            $trackerName = $session->upwork_profile_id
                ? UpworkProfile::query()->where('id', $session->upwork_profile_id)->value('name')
                : null;

            $base = [
                'user_id' => $session->user_id,
                'description' => $session->task_note ?: 'Tracked via desktop',
                'work_type' => $this->normaliseWorkType($session->work_type) ?: 'tracker',
                'client_id' => $session->client_id,
                'tracker' => $trackerName,
                'source' => 'tracker',
                'tracking_session_id' => $session->id,
            ];

            // Bounded by dailySeconds() to at most MAX_SPAN_DAYS rows, so this
            // loop can no longer be spun into tens of thousands of inserts by a
            // client-supplied date span. WorkHour::create() (rather than a bulk
            // insert()) is deliberate: the model's `creating` hook derives
            // work_category_id / is_billable, and insert() would skip it.
            foreach ($this->dailySeconds($session) as $date => $seconds) {
                if ($seconds < 1) {
                    continue;
                }
                WorkHour::create($base + [
                    'date' => $date,
                    'hours' => round($seconds / 3600, 4),
                ]);
            }
        });
    }

    /**
     * Split a session's tracked (idle-adjusted) seconds across the calendar
     * days it spans, each day getting its wall-clock share. Mirrors
     * TimelineController::inDaySeconds so every page agrees.
     *
     * @return array<string, int> [Y-m-d => seconds]
     */
    private function dailySeconds(TrackingSession $session): array
    {
        $start = $session->started_at;
        $end = $session->stopped_at ?? $session->last_heartbeat_at ?? $start;
        $total = (int) $session->total_seconds;

        if (! $start) {
            return [now()->toDateString() => $total];
        }
        if (! $end || $end->lessThanOrEqualTo($start)) {
            return [$start->toDateString() => $total];
        }

        // Hard iteration cap. The span is derived from timestamps the desktop
        // agent supplies; without this a 1970 -> 2100 span would spin ~47,000
        // day-steps inside one request and flood work_hours with junk rows.
        // Timestamps are clamped at the boundary too — this is the backstop for
        // rows written before that clamp existed (and for the stale-session
        // sweep, which runs in the scheduler where a hang hits every tenant).
        $spanSeconds = $end->getTimestamp() - $start->getTimestamp();
        if ($spanSeconds > self::MAX_SPAN_DAYS * 86400) {
            Log::warning('Tracking session spans an implausible number of days; collapsing to the start date.', [
                'tracking_session_id' => $session->id,
                'user_id' => $session->user_id,
                'started_at' => $start->toIso8601String(),
                'ended_at' => $end->toIso8601String(),
            ]);

            return [$start->toDateString() => $total];
        }

        $duration = max(1, $spanSeconds);
        $result = [];
        $cursor = $start->copy()->startOfDay();

        while ($cursor->lt($end)) {
            $next = $cursor->copy()->addDay();
            $segStart = $start->greaterThan($cursor) ? $start : $cursor;
            $segEnd = $end->lessThan($next) ? $end : $next;
            $overlap = max(0, $segEnd->getTimestamp() - $segStart->getTimestamp());
            if ($overlap > 0) {
                $result[$cursor->toDateString()] = (int) round($total * ($overlap / $duration));
            }
            $cursor = $next;
        }

        return $result ?: [$start->toDateString() => $total];
    }

    /**
     * Map a stored work type to a valid per-entry billing category. The
     * client-level "tracker_manual" engagement type defaults to "tracker"
     * (the desktop app records tracked time); users choose tracker/manual
     * explicitly in the app.
     */
    public function normaliseWorkType(?string $workType): ?string
    {
        if ($workType === null || $workType === '') {
            return null;
        }

        return $workType === 'tracker_manual' ? 'tracker' : $workType;
    }
}
