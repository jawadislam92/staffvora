<?php

namespace App\Http\Controllers;

use App\Models\MonitoringSetting;
use App\Models\TrackingActivitySample;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\ActivityDigestService;
use App\Support\BusinessTime;
use App\Support\WebDomain;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $authUser = $request->user();
        abort_unless($authUser->hasPermission('timeline.view_others'), 403);

        // A range (preset like today/week/month or a custom start+end). The
        // per-session day-clamp below uses these bounds, so a single day and a
        // multi-day range share the same code path.
        [$rangeStart, $rangeEnd] = $this->resolveRange($request, $request->input('range', 'today'));
        [$dayStart, $dayEnd] = BusinessTime::utcRange($rangeStart, $rangeEnd);

        $users = User::orderBy('name')->get(['id', 'name', 'email', 'role', 'designation', 'avatar']);

        // Sessions that OVERLAP the selected day — including one that started
        // the previous evening and is still running. Each session's time is
        // clamped to the day below, so a night shift's pre-midnight hours stay
        // on the previous date instead of inflating today.
        $sessions = TrackingSession::with('client:id,name')
            ->where('started_at', '<', $dayEnd)
            ->where(function ($q) use ($dayStart) {
                $q->whereNull('stopped_at')->orWhere('stopped_at', '>', $dayStart);
            })
            // Skip deletion crumbs (sub-minute sessions never mirror to
            // work_hours), but always keep a running session.
            ->where(function ($q) {
                $q->where('total_seconds', '>=', 60)
                    ->orWhere('status', TrackingSession::STATUS_ACTIVE);
            })
            ->get(['id', 'user_id', 'client_id', 'started_at', 'stopped_at', 'last_heartbeat_at', 'total_seconds', 'activity_percent', 'status']);

        $sessionsByUser = $sessions->groupBy('user_id');

        $sampleIntervalSeconds = 60;
        $samples = TrackingActivitySample::query()
            ->whereBetween('captured_at', [$dayStart, $dayEnd])
            ->whereIn('user_id', $users->pluck('id'))
            ->get(['id', 'user_id', 'tracking_session_id', 'captured_at', 'keyboard_count', 'mouse_count', 'idle_seconds', 'active_app', 'url_domain']);

        $samplesByUser = $samples->groupBy('user_id');

        // Manually logged work-diary hours for the day (Add Entry / edits —
        // anything not synced from the tracker). They count as valid hours
        // but contribute 0% activity, so a half-manual day dilutes the
        // activity score instead of hiding the manual time entirely.
        $manualByUser = \App\Models\WorkHour::query()
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->where(function ($q) {
                $q->whereNull('source')->orWhere('source', '!=', 'tracker');
            })
            ->get(['user_id', 'hours'])
            ->groupBy('user_id');

        // Active sessions snapshot (anyone running right now, regardless of date).
        $activeNow = TrackingSession::active()
            ->with('client:id,name')
            ->get(['id', 'user_id', 'client_id', 'task_note', 'started_at', 'last_heartbeat_at', 'activity_percent', 'total_seconds'])
            ->keyBy('user_id');

        // Seconds a session contributes to THIS day. A session fully inside the
        // day keeps its idle-adjusted total (accurate); one that straddles
        // midnight (or is still running) is attributed by its wall-clock overlap
        // with the day, capped at 16h to bound a forgotten session.
        $now = now();
        $daySeconds = function ($session) use ($dayStart, $dayEnd, $now): int {
            $start = $session->started_at;
            $end = $session->stopped_at ?? $now;
            $oStart = $start->greaterThan($dayStart) ? $start : $dayStart;
            $oEnd = $end->lessThan($dayEnd) ? $end : $dayEnd;
            $overlap = $oEnd->getTimestamp() - $oStart->getTimestamp();
            if ($overlap <= 0) {
                return 0;
            }
            $fullyInside = $start->greaterThanOrEqualTo($dayStart) && $end->lessThanOrEqualTo($dayEnd);

            return $fullyInside ? (int) $session->total_seconds : (int) min($overlap, 16 * 3600);
        };

        $rows = $users->map(function (User $u) use ($sessionsByUser, $samplesByUser, $sampleIntervalSeconds, $activeNow, $manualByUser, $daySeconds) {
            $userSessions = $sessionsByUser[$u->id] ?? collect();
            $userSamples = $samplesByUser[$u->id] ?? collect();
            $live = $activeNow[$u->id] ?? null;

            // Tracked = each session's time clamped to THIS day. A running
            // session counts toward today, but a night shift's pre-midnight
            // hours stay on the previous date instead of inflating today.
            $trackedSeconds = (int) $userSessions->sum(fn ($s) => $daySeconds($s));
            $manualSeconds = (int) round((float) ($manualByUser[$u->id] ?? collect())->sum('hours') * 3600);
            $totalSeconds = $trackedSeconds + $manualSeconds;

            // Activity = the tracker's own per-session activity score, weighted
            // by each session's time on this day. Uses the session score (same
            // number shown on each screenshot), which is delivered via
            // heartbeats even when raw samples are sparse.
            $weightedActivity = $userSessions->sum(fn ($s) => (int) $s->activity_percent * $daySeconds($s));
            $trackedActivity = $trackedSeconds > 0 ? $weightedActivity / $trackedSeconds : 0;

            // Manual hours carry 0% activity, so they dilute the score in
            // proportion to their share of the day.
            $activityPercent = $totalSeconds > 0
                ? (int) round($trackedActivity * ($trackedSeconds / $totalSeconds))
                : 0;

            $topClient = $userSessions
                ->groupBy(fn ($s) => $s->client?->name ?: 'Unassigned')
                ->map(fn ($g, $name) => ['name' => $name, 'total_seconds' => (int) $g->sum(fn ($s) => $daySeconds($s))])
                ->sortByDesc('total_seconds')
                ->first();

            $topApp = $userSamples
                ->filter(fn ($s) => ! empty($s->active_app))
                ->groupBy('active_app')
                ->map(fn ($g, $name) => ['name' => $name, 'samples' => $g->count()])
                ->sortByDesc('samples')
                ->first();

            $lastHeartbeat = $userSessions
                ->pluck('last_heartbeat_at')
                ->filter()
                ->sortDesc()
                ->first();

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'designation' => $u->designation,
                'avatar_url' => $u->avatar_url,
                'total_seconds' => $totalSeconds,
                'tracked_seconds' => $trackedSeconds,
                'manual_seconds' => $manualSeconds,
                'activity_percent' => $activityPercent,
                'top_client' => $topClient,
                'top_app' => $topApp ? $topApp['name'] : null,
                'last_heartbeat_at' => $lastHeartbeat?->toIso8601String(),
                'is_live' => (bool) $live,
                'live' => $live ? [
                    'client' => $live->client?->name,
                    'task_note' => $live->task_note,
                    'started_at' => $live->started_at?->toIso8601String(),
                    'activity_percent' => (int) $live->activity_percent,
                ] : null,
            ];
        })->sort(function ($a, $b) {
            // Live workers first, then most tracked time, then name — so the
            // people working right now sit at the top regardless of how much
            // others logged earlier in the day.
            return [$b['is_live'], $b['total_seconds'], $a['name']]
                <=> [$a['is_live'], $a['total_seconds'], $b['name']];
        })->values();

        $totals = [
            'day' => (int) $rows->sum('total_seconds'),
            'people_with_time' => $rows->filter(fn ($r) => $r['total_seconds'] > 0)->count(),
            'people_live' => $rows->filter(fn ($r) => $r['is_live'])->count(),
            'team_size' => $users->count(),
        ];

        return Inertia::render('Team/Index', [
            'start' => $rangeStart->toDateString(),
            'end' => $rangeEnd->toDateString(),
            'range' => $request->input('range', $rangeStart->toDateString() === $rangeEnd->toDateString() ? 'today' : 'custom'),
            'rows' => $rows,
            'totals' => $totals,
            'permissions' => [
                'view_screenshots' => $authUser->hasPermission('monitoring.view_screenshots'),
                'send_slack' => $authUser->hasPermission('reports.send_slack'),
            ],
            'slack' => [
                'configured' => app(ActivityDigestService::class)->configured(),
                'daily_enabled' => (bool) config('services.slack_reports.daily_digest_enabled'),
                'daily_time' => config('services.slack_reports.daily_digest_time', '09:00'),
            ],
        ]);
    }

    public function apps(Request $request): Response
    {
        $authUser = $request->user();
        abort_unless($authUser->hasPermission('timeline.view_others'), 403);

        $range = $request->input('range', '7d');
        [$start, $end] = $this->resolveRange($request, $range);
        $userId = (int) $request->input('user_id', 0);

        $sampleInterval = MonitoringSetting::current()->activity_sample_interval_seconds ?: 60;

        $samplesQuery = TrackingActivitySample::query()
            ->whereBetween('captured_at', BusinessTime::utcRange($start, $end));

        if ($userId > 0) {
            $samplesQuery->where('user_id', $userId);
        }

        $samples = $samplesQuery->get(['id', 'user_id', 'active_app', 'url_domain']);

        $userNames = User::whereIn('id', $samples->pluck('user_id')->unique())
            ->pluck('name', 'id');

        $rollup = function (string $key) use ($samples, $sampleInterval, $userNames) {
            return $samples
                ->filter(fn ($s) => ! empty($s->{$key}))
                // Normalize domains so www./bare variants roll up together
                // (older samples were stored unnormalized).
                ->groupBy(fn ($s) => $key === 'url_domain'
                    ? WebDomain::normalize($s->{$key})
                    : $s->{$key})
                ->map(function ($group, $name) use ($sampleInterval, $userNames) {
                    $byUser = $group->groupBy('user_id')
                        ->map(fn ($g) => $g->count())
                        ->sortDesc();

                    return [
                        'name' => $name,
                        'total_seconds' => $group->count() * $sampleInterval,
                        'user_count' => $byUser->count(),
                        'top_user' => $userNames[$byUser->keys()->first()] ?? null,
                    ];
                })
                ->sortByDesc('total_seconds')
                ->values()
                ->take(30)
                ->all();
        };

        return Inertia::render('Team/Apps', [
            'range' => $range,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'userId' => $userId ?: null,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'apps' => $rollup('active_app'),
            'urls' => $rollup('url_domain'),
            'totals' => [
                'tracked_seconds' => $samples->count() * $sampleInterval,
                'people' => $samples->pluck('user_id')->unique()->count(),
            ],
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveRange(Request $request, string $range): array
    {
        $tz = BusinessTime::tz();

        if ($request->filled('start') && $request->filled('end')) {
            try {
                return [
                    Carbon::parse($request->input('start'), $tz)->startOfDay(),
                    Carbon::parse($request->input('end'), $tz)->endOfDay(),
                ];
            } catch (\Throwable $e) {
                // fall through to named ranges
            }
        }

        $today = BusinessTime::today();

        return match ($range) {
            'today' => [$today->copy(), $today->copy()->endOfDay()],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()->endOfDay()],
            'week' => [$today->copy()->startOfWeek(MonitoringSetting::weekStartDay()), $today->copy()->endOfDay()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()->endOfDay()],
            '30d' => [$today->copy()->subDays(29), $today->copy()->endOfDay()],
            default => [$today->copy()->subDays(6), $today->copy()->endOfDay()],
        };
    }

    public function sendDigest(Request $request, ActivityDigestService $service): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('reports.send_slack'), 403);

        [$rangeStart, $rangeEnd] = $this->resolveRange($request, $request->input('range', 'today'));

        try {
            $summary = $service->sendRange($rangeStart, $rangeEnd);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'Slack digest sent for %s (%d people, %sh tracked).',
            $summary['range']['label'],
            $summary['people'],
            number_format($summary['total_seconds'] / 3600, 2),
        ));
    }

    private function resolveDate(Request $request): Carbon
    {
        return BusinessTime::parseDate($request->input('date'));
    }
}
