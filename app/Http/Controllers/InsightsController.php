<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Services\InsightsAggregator;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * INSIGHTS v1 — utilization vs target, the activity gauge, the billable /
 * category split, the trend + composition ribbon, and derived highlights.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS CONTROLLER OWNS
 * ─────────────────────────────────────────────────────────────────────────────
 * Input only. It resolves and CLAMPS the range, validates the person/team
 * filter against the current workspace, and hands both to
 * {@see InsightsAggregator}, which is where every number is computed (in SQL).
 * There is no second stats implementation here and none in the page.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SECURITY
 * ─────────────────────────────────────────────────────────────────────────────
 * • Route-gated by `permission:insights.view` AND `entitlement:insights`, so a
 *   plan that does not include Insights cannot reach it at all.
 * • `user_id` / `team_id` are validated with `exists` rules SCOPED TO THE
 *   ACTIVE ORGANIZATION (a raw `exists:users,id` would confirm the existence of
 *   another tenant's row). A failing id is DROPPED, not honoured: the page then
 *   renders the whole workspace, never another workspace's slice.
 * • This surface is read-only. It writes nothing, so there is no mutation to
 *   gate beyond the view permission.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SCALE
 * ─────────────────────────────────────────────────────────────────────────────
 * The range is clamped to InsightsAggregator::MAX_RANGE_DAYS (92) before it
 * touches the database, exactly like TeamController — `?start=2000-01-01` from
 * a bookmarked URL is silently narrowed to the most recent quarter rather than
 * rejected. Combined with the aggregator's fixed query count, the cost of this
 * page is bounded by headcount + range length, not by tracked volume.
 */
class InsightsController extends Controller
{
    /** Range presets offered by the picker. Anything else falls back to 30d. */
    private const PRESETS = ['7d', '30d', 'week', 'month', 'last_month', '90d'];

    public function index(Request $request, InsightsAggregator $aggregator): Response
    {
        $authUser = $request->user();
        abort_unless($authUser->hasPermission('insights.view'), 403);

        [$start, $end, $range] = $this->resolveRange($request);
        [$userId, $teamId] = $this->resolveFilter($request);

        $payload = $aggregator->build($start, $end, $userId, $teamId);

        return Inertia::render('Insights/Index', array_merge($payload, [
            'range' => array_merge($payload['range'], ['preset' => $range]),
            'filters' => [
                'user_id' => $userId,
                'team_id' => $teamId,
            ],
            // Directory options for the filter. Bounded by headcount / team
            // count, and deliberately the ACTIVE list — Insights measures the
            // people who are still here.
            'people' => User::query()->active()->orderBy('name')->get(['id', 'name']),
            'teams' => Team::query()->orderBy('name')->get(['id', 'name']),
        ]));
    }

    /**
     * Resolve the range from a preset or an explicit start/end pair, always
     * clamped to MAX_RANGE_DAYS.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolveRange(Request $request): array
    {
        $today = BusinessTime::today();

        if ($request->filled('start') && $request->filled('end')) {
            $validator = validator($request->only('start', 'end'), [
                'start' => ['required', 'date'],
                'end' => ['required', 'date'],
            ]);

            if (! $validator->fails()) {
                try {
                    $tz = BusinessTime::tz();
                    [$start, $end] = $this->clamp(
                        Carbon::parse($request->input('start'), $tz),
                        Carbon::parse($request->input('end'), $tz)
                    );

                    return [$start, $end, 'custom'];
                } catch (\Throwable $e) {
                    // Unparseable despite passing validation — fall through.
                }
            }
        }

        $preset = (string) $request->input('range', '30d');

        if (! in_array($preset, self::PRESETS, true)) {
            $preset = '30d';
        }

        [$start, $end] = match ($preset) {
            '7d' => [$today->copy()->subDays(6), $today->copy()],
            'week' => [$today->copy()->startOfWeek(\App\Models\MonitoringSetting::weekStartDay()), $today->copy()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            '90d' => [$today->copy()->subDays(89), $today->copy()],
            default => [$today->copy()->subDays(29), $today->copy()],
        };

        [$start, $end] = $this->clamp($start, $end);

        return [$start, $end, $preset];
    }

    /**
     * Normalise a range: swap an inverted pair, snap to whole business days and
     * clamp the span to MAX_RANGE_DAYS keeping the most recent window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function clamp(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $span = (int) $start->diffInDays($end) + 1;

        if ($span > InsightsAggregator::MAX_RANGE_DAYS) {
            $start = $end->copy()->subDays(InsightsAggregator::MAX_RANGE_DAYS - 1);
        }

        return [$start, $end->endOfDay()];
    }

    /**
     * The person / team filter, validated against THIS workspace.
     *
     * Both models are org-scoped, so `exists` is expressed as a scoped query
     * rather than a bare table rule: an id belonging to another tenant resolves
     * to null and the page falls back to the whole workspace. A person filter
     * wins over a team filter when both are supplied.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveFilter(Request $request): array
    {
        $userId = (int) $request->input('user_id', 0);
        $teamId = (int) $request->input('team_id', 0);

        if ($userId > 0 && User::query()->whereKey($userId)->exists()) {
            return [$userId, null];
        }

        if ($teamId > 0 && Team::query()->whereKey($teamId)->exists()) {
            return [null, $teamId];
        }

        return [null, null];
    }
}
