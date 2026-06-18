<?php

namespace App\Http\Controllers;

use App\Models\MonitoringSetting;
use App\Models\TrackingActivitySample;
use App\Models\TrackingAuditLog;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\WebDomain;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TimelineController extends Controller
{
    public function index(Request $request): Response
    {
        $authUser = $request->user();
        $canViewOthers = $authUser->hasPermission('timeline.view_others');

        $targetUser = $this->resolveTargetUser($request, $authUser, $canViewOthers);
        $date = $this->resolveDate($request);

        $users = $canViewOthers
            ? User::orderBy('name')->get(['id', 'name', 'email', 'role'])
            : collect([['id' => $authUser->id, 'name' => $authUser->name, 'email' => $authUser->email, 'role' => $authUser->role]]);

        return Inertia::render('Timeline/Index', [
            'targetUser' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'role' => $targetUser->role,
            ],
            'date' => $date->toDateString(),
            'users' => $users,
            'permissions' => [
                'view_screenshots' => $authUser->hasPermission('monitoring.view_screenshots') || $targetUser->id === $authUser->id,
                'view_others' => $canViewOthers,
                'add_offline_time' => (bool) ($authUser->can_create_manual_work_hour ?? false),
                'manage_screenshots' => $authUser->id === $targetUser->id || $authUser->hasPermission('monitoring.manage'),
                'delete_screenshots' => $authUser->hasPermission('monitoring.delete_screenshots'),
            ],
            'initialData' => $this->buildDayPayload($targetUser, $date, $authUser),
            'weekStartsOn' => MonitoringSetting::current()->week_starts_on,
            'aiEnabled' => app(\App\Services\AnthropicService::class)->configured()
                && $authUser->hasPermission('ai.assistant'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $canViewOthers = $authUser->hasPermission('timeline.view_others');

        $targetUser = $this->resolveTargetUser($request, $authUser, $canViewOthers);
        $date = $this->resolveDate($request);

        return response()->json($this->buildDayPayload($targetUser, $date, $authUser));
    }

    /**
     * AI summary of one person's day. Cached per user+date so repeat clicks
     * (and several admins viewing the same day) cost one API call.
     */
    public function aiSummary(Request $request): JsonResponse
    {
        $authUser = $request->user();
        abort_unless($authUser->hasPermission('ai.assistant'), 403, 'AI access has not been granted to this account.');
        $canViewOthers = $authUser->hasPermission('timeline.view_others');

        $targetUser = $this->resolveTargetUser($request, $authUser, $canViewOthers);
        $date = $this->resolveDate($request);

        $ai = app(\App\Services\AnthropicService::class);
        if (! $ai->configured()) {
            return response()->json(['message' => 'AI is not configured.'], 422);
        }

        $summary = \Illuminate\Support\Facades\Cache::remember(
            "ai-day-summary:{$targetUser->id}:{$date->toDateString()}",
            now()->addHours(6),
            function () use ($ai, $targetUser, $date, $authUser) {
                $payload = $this->buildDayPayload($targetUser, $date, $authUser);

                if (($payload['totals']['day'] ?? 0) <= 0 && empty($payload['sessions'])) {
                    return 'No tracked work on this day.';
                }

                return $ai->daySummary($targetUser->name, $payload)
                    ?? 'The AI summary could not be generated right now — try again in a minute.';
            }
        );

        return response()->json(['summary' => $summary]);
    }

    public function history(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $canViewOthers = $authUser->hasPermission('timeline.view_others');

        $targetUser = $this->resolveTargetUser($request, $authUser, $canViewOthers);
        $date = $this->resolveDate($request);

        [$utcStart, $utcEnd] = BusinessTime::utcRange($date->copy()->startOfDay(), $date->copy()->endOfDay());

        $logs = TrackingAuditLog::with(['actor:id,name'])
            ->where('subject_user_id', $targetUser->id)
            ->where(function ($q) use ($date, $utcStart, $utcEnd) {
                $q->whereDate('event_date', $date->toDateString())
                    ->orWhereBetween('created_at', [$utcStart, $utcEnd]);
            })
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'logs' => $logs->map(fn (TrackingAuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => $this->humanizeAction($log->action),
                'reason' => $log->reason,
                'old_value' => $log->old_value,
                'new_value' => $log->new_value,
                'actor_name' => $log->actor?->name ?? 'System',
                'created_at' => $log->created_at?->toIso8601String(),
                'tracking_screenshot_id' => $log->tracking_screenshot_id,
                'tracking_session_id' => $log->tracking_session_id,
            ])->values(),
        ]);
    }

    private function humanizeAction(string $action): string
    {
        return [
            'screenshot.flag' => 'Flagged screenshot',
            'screenshot.unflag' => 'Removed flag from screenshot',
            'screenshot.delete' => 'Deleted screenshot',
            'session.note_edit' => 'Edited session note',
            'session.abandon' => 'Marked session abandoned',
        ][$action] ?? $action;
    }

    /** @return array<string, mixed> */
    private function buildDayPayload(User $targetUser, Carbon $date, User $authUser): array
    {
        $canViewScreenshots = $authUser->id === $targetUser->id
            || $authUser->hasPermission('monitoring.view_screenshots');

        // $date arrives in the business timezone; queries need UTC bounds
        // because rows are stored in UTC.
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();
        $weekStartsOn = MonitoringSetting::current()->week_starts_on;
        $weekStart = $weekStartsOn === 'sunday'
            ? $date->copy()->startOfWeek(Carbon::SUNDAY)
            : $date->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();
        $monthStart = $date->copy()->startOfMonth();
        $monthEnd = $date->copy()->endOfMonth();

        // Sessions that OVERLAP the day, not just those that started on it —
        // a night shift running past midnight must show up on both calendar
        // days, each day with only its own screenshots/activity. The 2-day
        // started_at floor keeps an orphaned "running" session from older
        // days out (the stale sweep closes those anyway).
        [$rangeStart, $rangeEnd] = BusinessTime::utcRange($dayStart, $dayEnd);
        $sessions = TrackingSession::with(['client:id,name', 'upworkProfile:id,name'])
            ->where('user_id', $targetUser->id)
            ->where('started_at', '<=', $rangeEnd)
            ->where('started_at', '>=', $rangeStart->copy()->subDays(2))
            ->where(function ($q) use ($rangeStart) {
                $q->whereNull('stopped_at')->orWhere('stopped_at', '>=', $rangeStart);
            })
            ->orderBy('started_at')
            ->get();

        $sessionIds = $sessions->pluck('id');

        $screenshots = $canViewScreenshots
            ? TrackingScreenshot::whereIn('tracking_session_id', $sessionIds)
                ->whereBetween('captured_at', [$rangeStart, $rangeEnd])
                ->orderBy('captured_at')
                ->get([
                    'id', 'tracking_session_id', 'captured_at', 'thumbnail_path', 'image_path',
                    'activity_percent', 'active_app', 'active_window_title', 'url_domain', 'is_flagged',
                ])
            : collect();

        $shotsBySession = $screenshots->groupBy('tracking_session_id');

        $samples = TrackingActivitySample::whereIn('tracking_session_id', $sessionIds)
            ->whereBetween('captured_at', [$rangeStart, $rangeEnd])
            ->orderBy('captured_at')
            ->get(['id', 'tracking_session_id', 'captured_at', 'keyboard_count', 'mouse_count', 'idle_seconds', 'active_app', 'url_domain']);

        $samplesBySession = $samples->groupBy('tracking_session_id');
        $sampleIntervalSeconds = MonitoringSetting::current()->activity_sample_interval_seconds ?: 60;

        // Drop ghost sessions that screenshot deletion gutted down to crumbs
        // (sub-minute with no screenshots and no samples left). They would
        // otherwise leave residue in the day total, the Tasks box, and the
        // month-strip dots even though the session list already hides them.
        $sessions = $sessions->filter(function (TrackingSession $session) use ($shotsBySession, $samplesBySession) {
            return (int) $session->total_seconds >= 60
                || ($shotsBySession[$session->id] ?? collect())->isNotEmpty()
                || ($samplesBySession[$session->id] ?? collect())->isNotEmpty();
        })->values();

        $dateKey = $date->toDateString();

        $sessionPayload = $sessions->map(function (TrackingSession $session) use ($shotsBySession, $samplesBySession, $sampleIntervalSeconds, $canViewScreenshots, $dayStart, $dayEnd, $dateKey) {
            $rows = ($shotsBySession[$session->id] ?? collect());
            $sessionSamples = $samplesBySession[$session->id] ?? collect();

            return [
                'id' => $session->id,
                'client_name' => $session->client?->name,
                'task_note' => $session->task_note,
                'work_type' => $session->work_type,
                'tracker' => $session->upworkProfile?->name,
                'started_at' => $session->started_at?->toIso8601String(),
                'stopped_at' => $session->stopped_at?->toIso8601String(),
                'total_seconds' => (int) $session->total_seconds,
                'day_seconds' => $this->inDaySeconds($session, $dayStart, $dayEnd),
                'started_before_day' => BusinessTime::dateKey($session->started_at) < $dateKey,
                'continues_after_day' => $session->stopped_at !== null && BusinessTime::dateKey($session->stopped_at) > $dateKey,
                'activity_percent' => (int) $session->activity_percent,
                'status' => $session->status,
                'screenshots' => $canViewScreenshots
                    ? $rows->map(fn (TrackingScreenshot $s) => [
                        'id' => $s->id,
                        'captured_at' => $s->captured_at?->toIso8601String(),
                        'thumbnail_url' => $s->thumbnail_url,
                        'image_url' => $s->image_url,
                        'activity_percent' => (int) $s->activity_percent,
                        'active_app' => $s->active_app,
                        'active_window_title' => $s->active_window_title,
                        'url_domain' => $s->url_domain,
                        'is_flagged' => (bool) $s->is_flagged,
                    ])->values()
                    : [],
                'screenshot_count_hidden' => $canViewScreenshots ? 0 : (int) $rows->count(),
                'apps' => $this->rollupBy($sessionSamples, 'active_app', $sampleIntervalSeconds),
                'urls' => $this->rollupBy($sessionSamples, 'url_domain', $sampleIntervalSeconds),
            ];
        })
            // Hide ghost rows: sessions that merely brush the day with under
            // a minute and left no screenshots or activity here only confuse
            // the view (e.g. a stale orphan closed just after midnight).
            ->filter(fn (array $s) => $s['day_seconds'] >= 60
                || count($s['screenshots']) > 0
                || $s['screenshot_count_hidden'] > 0
                || ! empty($s['apps']))
            ->values();

        // Sub-minute sessions never reach work_hours (syncWorkHour skips
        // them), so excluding them here keeps the Timeline week/month totals
        // in step with Reports and ignores any deletion crumbs.
        $totalsScope = fn (Carbon $from, Carbon $to) => (int) TrackingSession::where('user_id', $targetUser->id)
            ->whereBetween('started_at', BusinessTime::utcRange($from, $to))
            ->where('total_seconds', '>=', 60)
            ->sum('total_seconds');

        $clientBreakdown = $sessions
            ->groupBy(fn (TrackingSession $s) => $this->breakdownLabel($s))
            ->map(fn ($group, $name) => [
                'client' => $name,
                'total_seconds' => (int) $group->sum(fn (TrackingSession $s) => $this->inDaySeconds($s, $dayStart, $dayEnd)),
            ])
            ->values();

        return [
            'date' => $date->toDateString(),
            'day_label' => $date->translatedFormat('l, F j'),
            'sessions' => $sessionPayload,
            'totals' => [
                'day' => (int) $sessions->sum(fn (TrackingSession $s) => $this->inDaySeconds($s, $dayStart, $dayEnd)),
                'week' => $totalsScope($weekStart, $weekEnd),
                'month' => $totalsScope($monthStart, $monthEnd),
            ],
            'week_range' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
            ],
            'month_strip' => $this->monthStrip($date, $targetUser->id),
            'client_breakdown' => $clientBreakdown,
            'day_apps' => $this->rollupBy($samples, 'active_app', $sampleIntervalSeconds, 10),
            'day_urls' => $this->rollupBy($samples, 'url_domain', $sampleIntervalSeconds, 10),
            'activity_bands' => $this->activityBands($samples, $sampleIntervalSeconds),
            'sample_interval_seconds' => (int) $sampleIntervalSeconds,
        ];
    }

    /**
     * Roll up activity samples by a given key (app or domain) into time spent.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rollupBy(Collection $samples, string $key, int $intervalSeconds, int $limit = 12): array
    {
        return $samples
            ->filter(fn ($s) => ! empty($s->{$key}))
            // Normalize domains so www./bare variants roll up together
            // (older samples were stored unnormalized).
            ->groupBy(fn ($s) => $key === 'url_domain'
                ? WebDomain::normalize($s->{$key})
                : $s->{$key})
            ->map(fn ($group, $name) => [
                'name' => $name,
                'samples' => $group->count(),
                'total_seconds' => $group->count() * $intervalSeconds,
            ])
            ->sortByDesc('total_seconds')
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * Bucket activity samples into 6-minute (10-per-hour) slots and tag each
     * slot active / idle / quiet so the front-end can render bands across the
     * 24h ruler.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activityBands(Collection $samples, int $intervalSeconds): array
    {
        $slotsPerHour = 10;        // 6-minute slots
        $totalSlots = 24 * $slotsPerHour;
        $bands = array_fill(0, $totalSlots, ['active' => 0, 'idle' => 0]);

        $tz = BusinessTime::tz();

        foreach ($samples as $sample) {
            $captured = $sample->captured_at?->copy()->setTimezone($tz);
            if (! $captured) {
                continue;
            }
            $slot = ($captured->hour * $slotsPerHour) + (int) floor($captured->minute / (60 / $slotsPerHour));
            if ($slot < 0 || $slot >= $totalSlots) {
                continue;
            }

            $isActive = ($sample->keyboard_count + $sample->mouse_count) > 0 && $sample->idle_seconds < $intervalSeconds;
            $bands[$slot][$isActive ? 'active' : 'idle']++;
        }

        $out = [];
        foreach ($bands as $i => $counts) {
            if ($counts['active'] === 0 && $counts['idle'] === 0) {
                continue;
            }
            $state = $counts['active'] >= $counts['idle'] ? 'active' : 'idle';
            $out[] = [
                'slot' => $i,
                'hour' => (int) floor($i / $slotsPerHour),
                'minute' => ($i % $slotsPerHour) * (60 / $slotsPerHour),
                'state' => $state,
                'samples' => $counts['active'] + $counts['idle'],
            ];
        }

        return $out;
    }

    /**
     * Portion of a session's tracked seconds that falls inside the given day,
     * allocated proportionally to the wall-clock overlap. An overnight session
     * therefore splits cleanly at midnight: Friday gets the pre-midnight
     * share, Saturday the rest, and the two always sum to total_seconds.
     */
    /**
     * Label for the Tasks breakdown. Client work shows the client; non-client
     * work (office work, bidding, test task) shows the work type instead of a
     * bare "Unassigned", so that time is clearly attributed.
     */
    private function breakdownLabel(TrackingSession $session): string
    {
        if ($session->client?->name) {
            return $session->client->name;
        }

        $labels = [
            'office_work' => 'Office work',
            'test_task' => 'Test task',
            'upwork_bidding' => 'Bidding',
        ];

        if ($session->work_type && isset($labels[$session->work_type])) {
            return $labels[$session->work_type];
        }

        if ($session->work_type) {
            return ucfirst(str_replace('_', ' ', $session->work_type));
        }

        return 'Unassigned';
    }

    private function inDaySeconds(TrackingSession $session, Carbon $dayStart, Carbon $dayEnd): int
    {
        $start = $session->started_at;
        $end = $session->stopped_at ?? now(BusinessTime::tz());

        if (! $start || $end->lte($start)) {
            return 0;
        }

        $overlapStart = $start->greaterThan($dayStart) ? $start : $dayStart;
        $overlapEnd = $end->lessThan($dayEnd) ? $end : $dayEnd;
        $overlap = max(0, $overlapStart->diffInSeconds($overlapEnd, false));

        if ($overlap <= 0) {
            return 0;
        }

        $duration = max(1, $start->diffInSeconds($end));

        return (int) round((int) $session->total_seconds * ($overlap / $duration));
    }

    /** @return array<int, array<string, mixed>> */
    private function monthStrip(Carbon $date, int $userId): array
    {
        $start = $date->copy()->startOfMonth();
        $end = $date->copy()->endOfMonth();

        // Bucket in PHP by business-tz date: SQL DATE() would group by the
        // stored UTC date and shift late-evening sessions onto the wrong day.
        // Overnight sessions are split at midnight (same allocation as the
        // day view) so each dot reflects the work done on that calendar day.
        [$rangeStart, $rangeEnd] = BusinessTime::utcRange($start, $end->copy()->endOfDay());
        $rows = TrackingSession::query()
            ->where('user_id', $userId)
            ->where('started_at', '<=', $rangeEnd)
            ->where('started_at', '>=', $rangeStart->copy()->subDays(2))
            // Skip deletion crumbs so the day dots match the real day totals.
            ->where('total_seconds', '>=', 60)
            ->where(function ($q) use ($rangeStart) {
                $q->whereNull('stopped_at')->orWhere('stopped_at', '>=', $rangeStart);
            })
            ->get(['started_at', 'stopped_at', 'total_seconds']);

        $perDay = [];
        foreach ($rows as $session) {
            $firstDay = BusinessTime::dateKey($session->started_at);
            $lastDay = BusinessTime::dateKey($session->stopped_at) ?? BusinessTime::today()->toDateString();
            for ($day = Carbon::parse($firstDay, BusinessTime::tz()); $day->toDateString() <= $lastDay; $day->addDay()) {
                $seconds = $this->inDaySeconds($session, $day->copy()->startOfDay(), $day->copy()->endOfDay());
                if ($seconds >= 30) {
                    $perDay[$day->toDateString()] = ($perDay[$day->toDateString()] ?? 0) + $seconds;
                }
            }
        }

        $today = BusinessTime::today();

        $out = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $iso = $cursor->toDateString();
            $out[] = [
                'date' => $iso,
                'day' => (int) $cursor->day,
                'weekday' => $cursor->translatedFormat('D'),
                'total_seconds' => (int) ($perDay[$iso] ?? 0),
                'is_today' => $cursor->isSameDay($today),
                'is_selected' => $cursor->isSameDay($date),
            ];
        }

        return $out;
    }

    private function resolveTargetUser(Request $request, User $authUser, bool $canViewOthers): User
    {
        $requested = (int) $request->input('user_id', 0);

        if (! $canViewOthers || ! $requested || $requested === $authUser->id) {
            return $authUser;
        }

        return User::query()->findOrFail($requested);
    }

    private function resolveDate(Request $request): Carbon
    {
        return BusinessTime::parseDate($request->input('date'));
    }
}
