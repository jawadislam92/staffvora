<?php

namespace App\Services;

use App\Models\TrackingActivitySample;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\UpworkProfile;
use App\Models\WorkHour;
use Carbon\CarbonInterface;

class TrackingSessionService
{
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
        $session->update([
            'stopped_at' => $stoppedAt ?? $session->last_heartbeat_at ?? $session->started_at,
            'status' => TrackingSession::STATUS_STOPPED,
        ]);

        $this->syncWorkHour($session->fresh());
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
     */
    public function syncWorkHour(TrackingSession $session): void
    {
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

        foreach ($this->dailySeconds($session) as $date => $seconds) {
            if ($seconds < 1) {
                continue;
            }
            WorkHour::create($base + [
                'date' => $date,
                'hours' => round($seconds / 3600, 4),
            ]);
        }
    }

    /**
     * Split a session's tracked (idle-adjusted) seconds across the calendar
     * days it spans, each day getting its wall-clock share. Mirrors
     * TimelineController::inDaySeconds so every page agrees.
     *
     * @return array<string, int>  [Y-m-d => seconds]
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

        $duration = max(1, $start->diffInSeconds($end));
        $result = [];
        $cursor = $start->copy()->startOfDay();

        while ($cursor->lt($end)) {
            $next = $cursor->copy()->addDay();
            $segStart = $start->greaterThan($cursor) ? $start : $cursor;
            $segEnd = $end->lessThan($next) ? $end : $next;
            $overlap = max(0, $segStart->diffInSeconds($segEnd));
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
