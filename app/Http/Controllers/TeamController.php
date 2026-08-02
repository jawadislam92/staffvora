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
    /**
     * Hard ceiling (in calendar days, inclusive) on a caller-supplied custom
     * range. `?start=2020-01-01&end=2030-01-01` was previously accepted verbatim
     * by anyone with timeline.view_others — i.e. every manager — and fed
     * straight into a full read of tracking_activity_samples (audit finding
     * high-15). Over-long ranges are CLAMPED to the most recent window rather
     * than rejected, so an existing bookmark still renders.
     */
    private const MAX_RANGE_DAYS = 92;

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
        // NOT hydrated as Eloquent models, and NOT eager-loading a client model
        // per session. Every column read below is a scalar; the only thing the
        // models were providing was five Carbon casts per row (the two the
        // overlap maths needs, plus three it never touches) and a related
        // Client object read solely for `->name`. On a 92-day range for a
        // 120-person org that is thousands of rows and tens of thousands of
        // Carbon constructions for arithmetic that is pure integer subtraction.
        // The client name comes from a LEFT JOIN instead.
        $sessionRows = TrackingSession::query()
            ->leftJoin('clients', 'tracking_sessions.client_id', '=', 'clients.id')
            ->where('tracking_sessions.started_at', '<', $dayEnd)
            ->where(function ($q) use ($dayStart) {
                $q->whereNull('tracking_sessions.stopped_at')
                    ->orWhere('tracking_sessions.stopped_at', '>', $dayStart);
            })
            // Skip deletion crumbs (sub-minute sessions never mirror to
            // work_hours), but always keep a running session.
            ->where(function ($q) {
                $q->where('tracking_sessions.total_seconds', '>=', 60)
                    ->orWhere('tracking_sessions.status', TrackingSession::STATUS_ACTIVE);
            })
            ->toBase()
            ->get([
                'tracking_sessions.user_id',
                'tracking_sessions.started_at',
                'tracking_sessions.stopped_at',
                'tracking_sessions.last_heartbeat_at',
                'tracking_sessions.total_seconds',
                'tracking_sessions.activity_percent',
                'clients.name as client_name',
            ]);

        // Each session's contribution to the range is computed ONCE here rather
        // than by a closure re-invoked five times per session downstream (total,
        // weighted activity, and once inside every top-client group). Same
        // rule, same numbers — see the block comment on daySeconds() below.
        $dayStartTs = $dayStart->getTimestamp();
        $dayEndTs = $dayEnd->getTimestamp();
        $nowTs = now()->getTimestamp();

        $sessionsByUser = [];

        foreach ($sessionRows as $row) {
            $startTs = strtotime((string) $row->started_at);
            $endTs = $row->stopped_at !== null ? strtotime((string) $row->stopped_at) : $nowTs;

            $overlap = min($endTs, $dayEndTs) - max($startTs, $dayStartTs);

            if ($overlap <= 0) {
                $seconds = 0;
            } else {
                $fullyInside = $startTs >= $dayStartTs && $endTs <= $dayEndTs;
                $seconds = $fullyInside ? (int) $row->total_seconds : min($overlap, 16 * 3600);
            }

            $sessionsByUser[$row->user_id][] = [
                'seconds' => $seconds,
                'activity' => (int) $row->activity_percent,
                'client' => $row->client_name ?: 'Unassigned',
                'heartbeat' => $row->last_heartbeat_at,
            ];
        }

        // Top app per person, aggregated IN SQL.
        //
        // This used to hydrate every tracking_activity_sample in the range as a
        // full Eloquent model just to pick each person's most-used app. That
        // table takes one row per tracking user per 60s (~1.44M rows/month for a
        // 100-user org), so "This month" on a real team was gigabytes of PHP
        // memory and a hard OOM (audit finding high-15).
        //
        // The grouped result is bounded by users x distinct apps (thousands),
        // not by sample count, so the top-1-per-user pick is done in PHP over
        // the small grouped rows. A global `LIMIT 30` over (user_id, app) pairs
        // would be wrong here — it silently drops most people's top app on a
        // big team — and a window function is not portable to MySQL 5.7.
        $topAppByUser = TrackingActivitySample::query()
            ->selectRaw('user_id, active_app, COUNT(*) as sample_count')
            ->whereBetween('captured_at', [$dayStart, $dayEnd])
            ->whereIn('user_id', $users->pluck('id')->all())
            ->whereNotNull('active_app')
            ->where('active_app', '!=', '')
            ->groupBy('user_id', 'active_app')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->sortByDesc('sample_count')->first()->active_app);

        // Manually logged work-diary hours for the day (Add Entry / edits —
        // anything not synced from the tracker). They count as valid hours
        // but contribute 0% activity, so a half-manual day dilutes the
        // activity score instead of hiding the manual time entirely.
        // Half-open bounds on the DATE column: sargable against
        // work_hours_org_date_idx, and correct on SQLite where the `date` cast
        // can persist "Y-m-d 00:00:00" that an inclusive whereBetween upper
        // bound would drop.
        //
        // Summed in SQL: the previous shape read one row per work-diary entry
        // in the window and added them up in PHP, which on a 92-day range is
        // tens of thousands of rows to produce one number per person.
        $manualHoursByUser = \App\Models\WorkHour::query()
            ->where('date', '>=', $rangeStart->toDateString())
            ->where('date', '<', $rangeEnd->copy()->startOfDay()->addDay()->toDateString())
            ->where(function ($q) {
                $q->whereNull('source')->orWhere('source', '!=', 'tracker');
            })
            ->selectRaw('user_id, SUM(hours) as total_hours')
            ->groupBy('user_id')
            ->pluck('total_hours', 'user_id');

        // Active sessions snapshot (anyone running right now, regardless of date).
        $activeNow = TrackingSession::active()
            ->with('client:id,name')
            ->get(['id', 'user_id', 'client_id', 'task_note', 'started_at', 'last_heartbeat_at', 'activity_percent', 'total_seconds'])
            ->keyBy('user_id');

        // Seconds a session contributes to THIS day (already applied above, once
        // per session): a session fully inside the day keeps its idle-adjusted
        // total (accurate); one that straddles midnight (or is still running) is
        // attributed by its wall-clock overlap with the day, capped at 16h to
        // bound a forgotten session.
        $rows = $users->map(function (User $u) use ($sessionsByUser, $topAppByUser, $activeNow, $manualHoursByUser) {
            $userSessions = $sessionsByUser[$u->id] ?? [];
            $live = $activeNow[$u->id] ?? null;

            // Tracked = each session's time clamped to THIS day. A running
            // session counts toward today, but a night shift's pre-midnight
            // hours stay on the previous date instead of inflating today.
            //
            // One pass produces the total, the activity weighting, the
            // per-client split and the latest heartbeat — the previous shape
            // walked the same sessions five times and recomputed the day-clamp
            // on every walk.
            $trackedSeconds = 0;
            $weightedActivity = 0;
            $byClient = [];
            $lastHeartbeat = null;

            foreach ($userSessions as $s) {
                $seconds = $s['seconds'];
                $trackedSeconds += $seconds;
                $weightedActivity += $s['activity'] * $seconds;
                $byClient[$s['client']] = ($byClient[$s['client']] ?? 0) + $seconds;

                // Raw 'Y-m-d H:i:s' strings in one fixed zone sort
                // lexicographically exactly as they sort chronologically, so the
                // max is found without parsing every row — only the winner is
                // turned into a Carbon, below.
                if ($s['heartbeat'] !== null && ($lastHeartbeat === null || $s['heartbeat'] > $lastHeartbeat)) {
                    $lastHeartbeat = $s['heartbeat'];
                }
            }

            $manualSeconds = (int) round((float) ($manualHoursByUser[$u->id] ?? 0) * 3600);
            $totalSeconds = $trackedSeconds + $manualSeconds;

            // Activity = the tracker's own per-session activity score, weighted
            // by each session's time on this day. Uses the session score (same
            // number shown on each screenshot), which is delivered via
            // heartbeats even when raw samples are sparse.
            $trackedActivity = $trackedSeconds > 0 ? $weightedActivity / $trackedSeconds : 0;

            // Manual hours carry 0% activity, so they dilute the score in
            // proportion to their share of the day.
            $activityPercent = $totalSeconds > 0
                ? (int) round($trackedActivity * ($trackedSeconds / $totalSeconds))
                : 0;

            $topClient = null;
            foreach ($byClient as $name => $seconds) {
                if ($topClient === null || $seconds > $topClient['total_seconds']) {
                    $topClient = ['name' => (string) $name, 'total_seconds' => $seconds];
                }
            }

            $topApp = $topAppByUser->get($u->id);

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
                'top_app' => $topApp ?: null,
                'last_heartbeat_at' => $lastHeartbeat !== null
                    ? Carbon::parse($lastHeartbeat)->toIso8601String()
                    : null,
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
        $window = BusinessTime::utcRange($start, $end);

        // Never hydrate samples. This page previously ran an unbounded ->get()
        // over tracking_activity_samples for the whole window (DEFAULT 7d, and
        // 30d / an uncapped custom range are one click away) — ~670k rows for a
        // 200-person org on the default range, every one of them a full Eloquent
        // model. Counting in SQL makes the payload proportional to
        // (distinct app|domain x users), which is thousands of rows at worst.
        $baseQuery = fn () => TrackingActivitySample::query()
            ->whereBetween('captured_at', $window)
            ->when($userId > 0, fn ($q) => $q->where('user_id', $userId));

        $totals = $baseQuery()
            ->selectRaw('COUNT(*) as sample_count, COUNT(DISTINCT user_id) as people')
            ->first();

        // Grouped by (bucket, user_id) — the user dimension is needed for
        // user_count / top_user, so a single-dimension GROUP BY would not do.
        // $column is a hardcoded literal from the two calls below, never input.
        $grouped = fn (string $column) => $baseQuery()
            ->selectRaw("{$column} as bucket, user_id, COUNT(*) as sample_count")
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column, 'user_id')
            ->get();

        $appRows = $grouped('active_app');
        $urlRows = $grouped('url_domain');

        $userNames = User::whereIn('id', $appRows->pluck('user_id')->merge($urlRows->pluck('user_id'))->unique()->all())
            ->pluck('name', 'id');

        // Domains are folded AFTER the SQL grouping: grouping on the raw column
        // cannot merge the legacy "www."/bare variants that WebDomain::normalize
        // merges, and dropping the normalize call would split each site's totals
        // across variants. Folding over the already-small grouped rows keeps the
        // old output while keeping the scan aggregate-only.
        $rollup = function ($rows, bool $normalizeDomain) use ($sampleInterval, $userNames) {
            $buckets = [];

            foreach ($rows as $row) {
                $name = $normalizeDomain ? WebDomain::normalize($row->bucket) : $row->bucket;

                if ($name === null || $name === '') {
                    continue;
                }

                $count = (int) $row->sample_count;
                $buckets[$name]['total'] = ($buckets[$name]['total'] ?? 0) + $count;
                $buckets[$name]['users'][$row->user_id] = ($buckets[$name]['users'][$row->user_id] ?? 0) + $count;
            }

            return collect($buckets)
                ->map(function ($bucket, $name) use ($sampleInterval, $userNames) {
                    $byUser = collect($bucket['users'])->sortDesc();

                    return [
                        'name' => $name,
                        'total_seconds' => $bucket['total'] * $sampleInterval,
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
            'apps' => $rollup($appRows, false),
            'urls' => $rollup($urlRows, true),
            'totals' => [
                'tracked_seconds' => (int) ($totals->sample_count ?? 0) * $sampleInterval,
                'people' => (int) ($totals->people ?? 0),
            ],
        ]);
    }

    /**
     * The one range resolver for this page. index(), apps() AND sendDigest()
     * all go through it, so the cap below is inherited by every consumer — the
     * point of keeping it in one place.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request, string $range): array
    {
        $tz = BusinessTime::tz();

        if ($request->filled('start') && $request->filled('end')) {
            $validator = validator($request->only('start', 'end'), [
                'start' => ['required', 'date'],
                'end' => ['required', 'date'],
            ]);

            if (! $validator->fails()) {
                try {
                    return $this->clampRange(
                        Carbon::parse($request->input('start'), $tz),
                        Carbon::parse($request->input('end'), $tz)
                    );
                } catch (\Throwable $e) {
                    // fall through to named ranges
                }
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

    /**
     * Normalise a caller-supplied range: swap an inverted pair (Carbon::parse
     * happily accepted end < start, which produced a nonsense session-overlap
     * predicate), snap to whole business days, and clamp the span to
     * self::MAX_RANGE_DAYS keeping the most recent window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function clampRange(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $spanDays = (int) $start->diffInDays($end) + 1;

        if ($spanDays > self::MAX_RANGE_DAYS) {
            $start = $end->copy()->subDays(self::MAX_RANGE_DAYS - 1);
        }

        return [$start, $end->endOfDay()];
    }

    private function resolveDate(Request $request): Carbon
    {
        return BusinessTime::parseDate($request->input('date'));
    }
}
