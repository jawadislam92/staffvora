<?php

namespace App\Services;

use App\Models\MonitoringSetting;
use App\Models\Shift;
use App\Models\ShiftOverride;
use App\Models\TrackingSession;
use App\Models\User;
use App\Models\WorkCategory;
use App\Models\WorkHour;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * INSIGHTS — the aggregation engine behind /insights.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE ONE RULE: EVERY NUMBER IS A SQL AGGREGATE
 * ─────────────────────────────────────────────────────────────────────────────
 * This surface exists to answer "how is the team doing over the last quarter",
 * which on a 100-person workspace is hundreds of thousands of work_hours rows
 * and millions of tracking rows. Nothing here ever hydrates a time row: every
 * figure comes back from the database already SUMmed/COUNTed/GROUPed, so the
 * PHP-side result set is proportional to
 *
 *      people  +  days-in-range  +  work categories
 *
 * and never to the amount of time tracked. The whole page is a FIXED number of
 * queries (9) regardless of range length or org size:
 *
 *   1  people directory (bounded by headcount)
 *   2  named shifts referenced by those people (bounded by the shift list)
 *   3  work_hours   GROUP BY user_id          — hours / billable / tracked / days
 *   4  work_hours   GROUP BY date             — the trend series (<= 92 rows)
 *   5  work_hours   GROUP BY work_category_id — the category split
 *   6  work_categories names (bounded by the catalogue)
 *   7  tracking_sessions GROUP BY user_id     — activity, whole range
 *   8  tracking_sessions GROUP BY user_id     — activity, FIRST half
 *   9  tracking_sessions GROUP BY user_id     — activity, SECOND half
 *  (+1 shift_overrides GROUP BY user_id when the workspace uses overrides)
 *
 * The two half-range activity queries are how "most improved" is computed
 * without a per-day GROUP BY on a datetime column — which would need a
 * driver-specific date function (and the business timezone) and is not portable
 * between SQLite and MySQL. Two extra grouped aggregates are cheaper and exact.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TENANCY
 * ─────────────────────────────────────────────────────────────────────────────
 * Every model touched here uses BelongsToOrganization, so OrganizationScope
 * injects `organization_id = <active org>` into all nine queries. No id is ever
 * hardcoded and no raw table query bypasses the scope. The caller resolves the
 * person/team filter to a set of ids drawn from THIS org's user list, so a
 * foreign user_id can never widen the result — it simply matches nobody.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IS AND IS NOT MEASURABLE
 * ─────────────────────────────────────────────────────────────────────────────
 * WORKED hours come from `work_hours` — the one table both capture paths land
 * in (the desktop tracker mirrors sessions into it; manual entries are typed
 * into it). ACTIVITY comes from `tracking_sessions.activity_percent`, which
 * only tracked time has: manual time carries no activity signal at all, so the
 * gauge is explicitly labelled as covering tracked time only rather than
 * pretending manual hours were 0% active.
 */
class InsightsAggregator
{
    /**
     * Inclusive calendar-day ceiling on a range, matching the cap the rest of
     * the read paths enforce (TeamController::MAX_RANGE_DAYS). A quarter is the
     * longest window this page will aggregate in one request.
     */
    public const MAX_RANGE_DAYS = 92;

    /**
     * Activity bands used for the gauge's threshold reading. These are reading
     * aids, not judgements: the tracker's activity score measures input
     * density, and plenty of real work (calls, reading, design review) is quiet.
     */
    public const ACTIVITY_HEALTHY = 50;

    public const ACTIVITY_LOW = 30;

    /** Utilization at or above this counts as "on target". */
    public const UTILIZATION_ON_TARGET = 90;

    /** Below this, a person is called out as under target. */
    public const UTILIZATION_UNDER = 80;

    /** Sessions shorter than this are deletion crumbs, never mirrored to work_hours. */
    private const MIN_SESSION_SECONDS = 60;

    /**
     * Build the whole payload for one range + filter.
     *
     * @param  Carbon  $start  start of the first business day in the range
     * @param  Carbon  $end  END of the last business day in the range
     * @param  int|null  $userId  restrict to one person (already validated as in-org)
     * @param  int|null  $teamId  restrict to one team (already validated as in-org)
     */
    public function build(Carbon $start, Carbon $end, ?int $userId = null, ?int $teamId = null): array
    {
        $startDate = $start->copy()->startOfDay();
        $endDate = $end->copy()->startOfDay();

        // Half-open [start, endExclusive) on the DATE column. Sargable against
        // work_hours_org_date_idx, and correct on SQLite where a cast can
        // persist "Y-m-d 00:00:00" that an inclusive upper bound would drop.
        $startStr = $startDate->toDateString();
        $endExclusiveStr = $endDate->copy()->addDay()->toDateString();

        $people = $this->people($userId, $teamId);
        $userIds = $people->pluck('id')->all();

        if ($userIds === []) {
            return $this->emptyPayload($startDate, $endDate);
        }

        $workingDays = $this->workingDays($startDate, $endDate);

        $work = $this->workHoursByUser($userIds, $startStr, $endExclusiveStr);
        $activity = $this->activityByUser($userIds, $startDate, $endDate);
        [$firstHalf, $secondHalf] = $this->activityHalves($userIds, $startDate, $endDate);
        $overrides = $this->shiftOverrideAdjustments($userIds, $startStr, $endExclusiveStr);

        $rows = $this->rows($people, $workingDays, $work, $activity, $overrides);

        $trend = $this->dailyTrend($userIds, $startStr, $endExclusiveStr, $startDate, $endDate);
        $categories = $this->byCategory($userIds, $startStr, $endExclusiveStr);

        $totals = $this->totals($rows, $workingDays);
        $ribbon = $this->ribbon($totals);

        return [
            'range' => [
                'start' => $startStr,
                'end' => $endDate->toDateString(),
                'days' => (int) $startDate->diffInDays($endDate) + 1,
                'working_days' => count($workingDays),
                'max_days' => self::MAX_RANGE_DAYS,
            ],
            'rows' => $rows->values()->all(),
            'totals' => $totals,
            'trend' => $trend,
            'ribbon' => $ribbon,
            'categories' => $categories,
            'highlights' => $this->highlights($rows, $totals, $trend, $categories, $firstHalf, $secondHalf),
            'thresholds' => [
                'activity_healthy' => self::ACTIVITY_HEALTHY,
                'activity_low' => self::ACTIVITY_LOW,
                'utilization_on_target' => self::UTILIZATION_ON_TARGET,
                'utilization_under' => self::UTILIZATION_UNDER,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // People + targets
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The people this range is measured over: ACTIVE users only. Deactivated
     * people keep their history everywhere else in the product, but a target
     * for someone who has left is meaningless, so they are excluded here (and
     * the UI says so). Bounded by headcount, not by tracked time.
     */
    private function people(?int $userId, ?int $teamId): Collection
    {
        $users = User::query()
            ->active()
            ->when($userId !== null, fn ($q) => $q->where('id', $userId))
            ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'avatar', 'designation', 'team_id', 'shift_id', 'shift_hours', 'joining_date']);

        // A named shift may carry the timing for everyone assigned to it, which
        // then WINS over the per-user column (User::effectiveShiftFor). Fetched
        // as one keyed lookup instead of touching the relation per user — and,
        // because Shift is org-scoped, a stale cross-tenant shift_id resolves to
        // nothing rather than leaking another workspace's schedule.
        $shiftIds = $users->pluck('shift_id')->filter()->unique()->values();

        $shiftHours = $shiftIds->isEmpty()
            ? collect()
            : Shift::whereIn('id', $shiftIds->all())->pluck('hours', 'id');

        return $users->map(function (User $user) use ($shiftHours) {
            $fromShift = $user->shift_id !== null ? $shiftHours->get($user->shift_id) : null;
            $daily = $fromShift !== null ? (float) $fromShift : ($user->shift_hours !== null ? (float) $user->shift_hours : null);

            return (object) [
                'id' => (int) $user->id,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'designation' => $user->designation,
                'team_id' => $user->team_id,
                // null = NO TARGET SET. Never silently defaulted to 8h: an
                // invented target would make every utilization number a lie.
                'daily_target' => $daily !== null && $daily > 0 ? $daily : null,
                'joining_date' => $user->joining_date?->toDateString(),
            ];
        });
    }

    /**
     * Every business day in the range that the workspace expects work on, using
     * the SAME attendance policy the attendance grid uses (week-off days +
     * holidays, Sunday-off by default). At most MAX_RANGE_DAYS entries, so this
     * loop is over DAYS — never over rows.
     *
     * @return array<int, string> Y-m-d, ascending
     */
    private function workingDays(Carbon $start, Carbon $end): array
    {
        $settings = MonitoringSetting::current();

        $weekOffRaw = $settings->attendance_week_off_days;
        $weekOff = is_array($weekOffRaw) && $weekOffRaw !== []
            ? array_map('intval', $weekOffRaw)
            : [Carbon::SUNDAY];

        $holidays = is_array($settings->attendance_holidays) ? array_flip($settings->attendance_holidays) : [];

        $days = [];
        $cursor = $start->copy();

        while ($cursor->lessThanOrEqualTo($end) && count($days) < self::MAX_RANGE_DAYS) {
            $key = $cursor->toDateString();

            if (! in_array($cursor->dayOfWeek, $weekOff, true) && ! isset($holidays[$key])) {
                $days[] = $key;
            }

            $cursor->addDay();
        }

        return $days;
    }

    // ─────────────────────────────────────────────────────────────────────
    // SQL aggregates
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Worked / billable / tracked hours + days logged, per person, in ONE
     * grouped query. Result rows = number of people who logged anything.
     */
    private function workHoursByUser(array $userIds, string $startStr, string $endExclusiveStr): Collection
    {
        return WorkHour::query()
            ->whereIn('user_id', $userIds)
            ->where('date', '>=', $startStr)
            ->where('date', '<', $endExclusiveStr)
            ->selectRaw('user_id')
            ->selectRaw('SUM(hours) as worked_hours')
            ->selectRaw('SUM(CASE WHEN is_billable = 1 THEN hours ELSE 0 END) as billable_hours')
            ->selectRaw(
                'SUM(CASE WHEN tracking_session_id IS NOT NULL OR source = ? THEN hours ELSE 0 END) as tracked_hours',
                [WorkHour::SOURCE_TRACKER]
            )
            ->selectRaw('COUNT(DISTINCT date) as days_logged')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
    }

    /**
     * Weighted activity per person over a window. `activity_percent` is a
     * per-session score, so it is weighted by each session's own length —
     * a 6-hour 70% session must not be averaged flat against a 10-minute 20%
     * one. COALESCE keeps a session whose agent never reported activity at 0
     * rather than dropping the row and inflating everyone else's average.
     */
    private function activityAggregate(array $userIds, Carbon $from, Carbon $to): Collection
    {
        [$utcFrom, $utcTo] = BusinessTime::utcRange($from->copy()->startOfDay(), $to->copy()->endOfDay());

        return TrackingSession::query()
            ->whereIn('user_id', $userIds)
            ->where('started_at', '>=', $utcFrom)
            ->where('started_at', '<=', $utcTo)
            ->where('total_seconds', '>=', self::MIN_SESSION_SECONDS)
            ->selectRaw('user_id')
            ->selectRaw('SUM(total_seconds) as tracked_seconds')
            ->selectRaw('SUM(COALESCE(activity_percent, 0) * total_seconds) as weighted_activity')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
    }

    private function activityByUser(array $userIds, Carbon $start, Carbon $end): Collection
    {
        return $this->activityAggregate($userIds, $start, $end);
    }

    /**
     * The range split down the middle, as two grouped aggregates. Feeds the
     * "most improved activity" highlight without a per-day datetime GROUP BY.
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function activityHalves(array $userIds, Carbon $start, Carbon $end): array
    {
        $days = (int) $start->diffInDays($end) + 1;

        // Under 4 days there is no meaningful "before and after" to compare.
        if ($days < 4) {
            return [collect(), collect()];
        }

        $half = intdiv($days, 2);
        $firstEnd = $start->copy()->addDays($half - 1);
        $secondStart = $start->copy()->addDays($half);

        return [
            $this->activityAggregate($userIds, $start, $firstEnd),
            $this->activityAggregate($userIds, $secondStart, $end),
        ];
    }

    /**
     * Per-person target adjustment from one-day shift overrides, as ONE grouped
     * aggregate: `SUM(shift_hours)` and `COUNT(*)` over the range.
     *
     * Each override day is treated as REPLACING one standard day at its own
     * length, so the adjustment is `SUM(shift_hours) - COUNT(*) * daily_target`
     * (applied by the caller, which knows each person's base). An override that
     * happens to land on a week-off day therefore shifts the target by the
     * signed difference rather than adding a whole extra day — deliberate, and
     * bounded by the (rare) number of overrides.
     */
    private function shiftOverrideAdjustments(array $userIds, string $startStr, string $endExclusiveStr): Collection
    {
        return ShiftOverride::query()
            ->whereIn('user_id', $userIds)
            ->whereNotNull('shift_hours')
            ->where('date', '>=', $startStr)
            ->where('date', '<', $endExclusiveStr)
            ->selectRaw('user_id')
            ->selectRaw('COUNT(*) as override_days')
            ->selectRaw('SUM(shift_hours) as override_hours')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
    }

    /**
     * Worked hours per business day — at most MAX_RANGE_DAYS rows out of the
     * database no matter how much time the range contains. `work_hours.date` is
     * already the business day (every writer stores Y-m-d), so grouping needs no
     * timezone conversion and no driver-specific date function.
     */
    private function dailyTrend(array $userIds, string $startStr, string $endExclusiveStr, Carbon $start, Carbon $end): array
    {
        $byDate = WorkHour::query()
            ->whereIn('user_id', $userIds)
            ->where('date', '>=', $startStr)
            ->where('date', '<', $endExclusiveStr)
            ->selectRaw('date')
            ->selectRaw('SUM(hours) as hours')
            ->groupBy('date')
            ->get()
            // substr() because a SQLite date cast can hand back "Y-m-d 00:00:00".
            ->mapWithKeys(fn ($row) => [substr((string) $row->date, 0, 10) => round((float) $row->hours, 2)]);

        // Densify so days with no work render as real zeros in the trend rather
        // than closing the gap and implying continuous work.
        $series = [];
        $cursor = $start->copy();

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            $series[] = [
                'date' => $key,
                'label' => $cursor->format('M j'),
                'value' => (float) ($byDate[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Hours by WORK CATEGORY — the split the product could not show before
     * work_category_id existed. One grouped aggregate; result rows are bounded
     * by the workspace's own category list.
     */
    private function byCategory(array $userIds, string $startStr, string $endExclusiveStr): array
    {
        $grouped = WorkHour::query()
            ->whereIn('user_id', $userIds)
            ->where('date', '>=', $startStr)
            ->where('date', '<', $endExclusiveStr)
            ->selectRaw('work_category_id')
            ->selectRaw('SUM(hours) as hours')
            ->selectRaw('SUM(CASE WHEN is_billable = 1 THEN hours ELSE 0 END) as billable_hours')
            ->groupBy('work_category_id')
            ->get();

        if ($grouped->isEmpty()) {
            return [];
        }

        $names = WorkCategory::whereIn('id', $grouped->pluck('work_category_id')->filter()->all())
            ->pluck('name', 'id');

        $total = (float) $grouped->sum('hours');

        return $grouped
            ->map(fn ($row) => [
                'id' => $row->work_category_id !== null ? (int) $row->work_category_id : null,
                'name' => $row->work_category_id !== null
                    ? ($names[$row->work_category_id] ?? 'Removed category')
                    : 'Uncategorised',
                'hours' => round((float) $row->hours, 2),
                'billable_hours' => round((float) $row->billable_hours, 2),
                'share' => $total > 0 ? round((float) $row->hours / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('hours')
            ->values()
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Assembly
    // ─────────────────────────────────────────────────────────────────────

    /**
     * One row per person, stitched from the grouped aggregates. Arithmetic only
     * — the summing already happened in SQL.
     */
    private function rows(Collection $people, array $workingDays, Collection $work, Collection $activity, Collection $overrides): Collection
    {
        // Working days remaining ON OR AFTER each date, so a person who joined
        // mid-range gets a pro-rated target with an O(1) lookup instead of a
        // per-person rescan of the day list.
        $remaining = [];
        $count = 0;

        foreach (array_reverse($workingDays) as $day) {
            $count++;
            $remaining[$day] = $count;
        }

        $totalWorkingDays = count($workingDays);
        $firstDay = $workingDays[0] ?? null;

        return $people->map(function ($person) use ($work, $activity, $overrides, $remaining, $workingDays, $totalWorkingDays, $firstDay) {
            $w = $work->get($person->id);
            $a = $activity->get($person->id);
            $o = $overrides->get($person->id);

            $worked = $w !== null ? round((float) $w->worked_hours, 2) : 0.0;
            $billable = $w !== null ? round((float) $w->billable_hours, 2) : 0.0;
            $tracked = $w !== null ? round((float) $w->tracked_hours, 2) : 0.0;
            $daysLogged = $w !== null ? (int) $w->days_logged : 0;

            $trackedSeconds = $a !== null ? (int) $a->tracked_seconds : 0;
            $activityPercent = $trackedSeconds > 0
                ? (int) round((float) $a->weighted_activity / $trackedSeconds)
                : null;

            // Working days this person could actually have worked: the whole
            // range unless they joined inside it.
            $eligibleDays = $totalWorkingDays;

            if ($person->joining_date !== null && $firstDay !== null && $person->joining_date > $firstDay) {
                $eligibleDays = $remaining[$person->joining_date]
                    ?? $this->remainingFrom($workingDays, $remaining, $person->joining_date);
            }

            $target = null;

            if ($person->daily_target !== null) {
                $base = $person->daily_target * $eligibleDays;
                $adjustment = $o !== null
                    ? ((float) $o->override_hours - ((int) $o->override_days * $person->daily_target))
                    : 0.0;
                $target = round(max(0.0, $base + $adjustment), 2);
            }

            return [
                'id' => $person->id,
                'name' => $person->name,
                'avatar_url' => $person->avatar_url,
                'designation' => $person->designation,
                'team_id' => $person->team_id,
                'worked_hours' => $worked,
                'billable_hours' => $billable,
                'non_billable_hours' => round(max(0, $worked - $billable), 2),
                'tracked_hours' => $tracked,
                'manual_hours' => round(max(0, $worked - $tracked), 2),
                'days_logged' => $daysLogged,
                'target_hours' => $target,
                'expected_daily_hours' => $person->daily_target,
                'eligible_days' => $eligibleDays,
                // null when no shift length is set — the UI shows "No target set"
                // rather than a fabricated percentage.
                'utilization' => ($target !== null && $target > 0) ? round($worked / $target * 100, 1) : null,
                'billable_ratio' => $worked > 0 ? round($billable / $worked * 100, 1) : null,
                'activity_percent' => $activityPercent,
                'tracked_seconds' => $trackedSeconds,
            ];
        });
    }

    /**
     * Working days on or after a date that is not itself a working day (someone
     * who joined on a Sunday). Walks the pre-built ASCENDING day list and reads
     * the suffix count of the first working day at or after it — never the
     * database, and never more than MAX_RANGE_DAYS steps.
     */
    private function remainingFrom(array $workingDays, array $remaining, string $date): int
    {
        foreach ($workingDays as $day) {
            if ($day >= $date) {
                return $remaining[$day];
            }
        }

        return 0;
    }

    private function totals(Collection $rows, array $workingDays): array
    {
        $worked = round((float) $rows->sum('worked_hours'), 2);
        $billable = round((float) $rows->sum('billable_hours'), 2);
        $tracked = round((float) $rows->sum('tracked_hours'), 2);

        $withTarget = $rows->filter(fn ($r) => $r['target_hours'] !== null && $r['target_hours'] > 0);
        $targetTotal = round((float) $withTarget->sum('target_hours'), 2);

        // Team activity: weighted by tracked SECONDS, so the busiest trackers
        // move the number the most — a flat average over people would let a
        // 10-minute session outvote a full week.
        $trackedSeconds = (int) $rows->sum('tracked_seconds');
        $weighted = $rows->reduce(
            fn ($carry, $r) => $carry + (($r['activity_percent'] ?? 0) * $r['tracked_seconds']),
            0
        );

        return [
            'people' => $rows->count(),
            'people_with_time' => $rows->filter(fn ($r) => $r['worked_hours'] > 0)->count(),
            'people_with_target' => $withTarget->count(),
            'people_without_target' => $rows->count() - $withTarget->count(),
            'worked_hours' => $worked,
            'billable_hours' => $billable,
            'non_billable_hours' => round(max(0, $worked - $billable), 2),
            'tracked_hours' => $tracked,
            'manual_hours' => round(max(0, $worked - $tracked), 2),
            'target_hours' => $withTarget->isEmpty() ? null : $targetTotal,
            // Team utilization counts ONLY the people who have a target — mixing
            // in target-less people would drag the percentage down for a reason
            // that has nothing to do with how much anyone worked.
            'utilization' => $targetTotal > 0
                ? round((float) $withTarget->sum('worked_hours') / $targetTotal * 100, 1)
                : null,
            'billable_ratio' => $worked > 0 ? round($billable / $worked * 100, 1) : null,
            'activity_percent' => $trackedSeconds > 0 ? (int) round($weighted / $trackedSeconds) : null,
            'tracked_seconds' => $trackedSeconds,
            'working_days' => count($workingDays),
            'avg_hours_per_person' => $rows->count() > 0 ? round($worked / $rows->count(), 2) : 0.0,
        ];
    }

    /**
     * The composition ribbon: how the range's capacity actually broke down.
     * Active/idle come from the activity score applied to TRACKED hours only;
     * manual hours are their own segment (they carry no activity signal, so
     * calling them idle would be a lie); "untracked" is the shortfall against
     * the team target, and is omitted entirely when no target exists.
     */
    private function ribbon(array $totals): array
    {
        $tracked = (float) $totals['tracked_hours'];
        $activity = $totals['activity_percent'];

        $segments = [];

        if ($activity !== null && $tracked > 0) {
            $active = round($tracked * $activity / 100, 2);
            $segments[] = ['label' => 'Active', 'pct' => $active, 'tone' => 'tracked'];
            $segments[] = ['label' => 'Tracked, low input', 'pct' => round($tracked - $active, 2), 'tone' => 'idle'];
        } elseif ($tracked > 0) {
            $segments[] = ['label' => 'Tracked', 'pct' => $tracked, 'tone' => 'tracked'];
        }

        if ($totals['manual_hours'] > 0) {
            $segments[] = ['label' => 'Manual', 'pct' => (float) $totals['manual_hours'], 'tone' => 'manual'];
        }

        if ($totals['target_hours'] !== null) {
            $gap = round((float) $totals['target_hours'] - (float) $totals['worked_hours'], 2);

            if ($gap > 0) {
                $segments[] = ['label' => 'Unfilled vs target', 'pct' => $gap, 'tone' => 'untracked'];
            }
        }

        return $segments;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Highlights
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A short list of things that are ACTUALLY true of this range. Every entry
     * is derived from the aggregates above; nothing is hardcoded, and each rule
     * carries its own minimum-evidence guard so a workspace with two hours of
     * data gets an honest empty list instead of a confident-sounding sentence
     * about noise.
     */
    private function highlights(Collection $rows, array $totals, array $trend, array $categories, Collection $firstHalf, Collection $secondHalf): array
    {
        $out = [];

        // Not enough worked time anywhere to say anything at all.
        if ($totals['worked_hours'] < 1) {
            return $out;
        }

        // ── Most improved activity (first half vs second half) ──────────────
        // Needs >= 4h tracked in BOTH halves so a single long day can't create
        // a fake "improvement", and a >= 5pp swing to be worth mentioning.
        $minHalfSeconds = 4 * 3600;
        $bestImprovement = null;

        foreach ($rows as $row) {
            $a = $firstHalf->get($row['id']);
            $b = $secondHalf->get($row['id']);

            if ($a === null || $b === null) {
                continue;
            }

            $aSeconds = (int) $a->tracked_seconds;
            $bSeconds = (int) $b->tracked_seconds;

            if ($aSeconds < $minHalfSeconds || $bSeconds < $minHalfSeconds) {
                continue;
            }

            $before = (int) round((float) $a->weighted_activity / $aSeconds);
            $after = (int) round((float) $b->weighted_activity / $bSeconds);
            $delta = $after - $before;

            if ($delta >= 5 && ($bestImprovement === null || $delta > $bestImprovement['delta'])) {
                $bestImprovement = ['name' => $row['name'], 'delta' => $delta, 'before' => $before, 'after' => $after];
            }
        }

        if ($bestImprovement !== null) {
            $out[] = [
                'key' => 'most_improved_activity',
                'tone' => 'success',
                'title' => $bestImprovement['name'].' improved most on activity',
                'detail' => sprintf(
                    '%d%% in the first half of the range, %d%% in the second — up %d points.',
                    $bestImprovement['before'],
                    $bestImprovement['after'],
                    $bestImprovement['delta']
                ),
            ];
        }

        // ── Highest billable ratio ─────────────────────────────────────────
        // Only when there is a real billable split to talk about (some
        // non-billable time exists) and the person logged >= 8h.
        if ($totals['non_billable_hours'] > 0 && $totals['billable_hours'] > 0) {
            $topBillable = $rows
                ->filter(fn ($r) => $r['worked_hours'] >= 8 && $r['billable_ratio'] !== null)
                ->sortByDesc('billable_ratio')
                ->first();

            if ($topBillable !== null && $topBillable['billable_ratio'] > ($totals['billable_ratio'] ?? 0)) {
                $out[] = [
                    'key' => 'top_billable_ratio',
                    'tone' => 'info',
                    'title' => $topBillable['name'].' has the highest billable share',
                    'detail' => sprintf(
                        '%.0f%% of %s billable, against a team average of %.0f%%.',
                        $topBillable['billable_ratio'],
                        $this->hours($topBillable['worked_hours']),
                        $totals['billable_ratio'] ?? 0
                    ),
                ];
            }
        }

        // ── Consistently under target ──────────────────────────────────────
        // Needs a real target and at least 3 working days, so a two-day range
        // never brands anyone.
        if ($totals['working_days'] >= 3) {
            $under = $rows
                ->filter(fn ($r) => $r['utilization'] !== null && $r['utilization'] < self::UTILIZATION_UNDER)
                ->sortBy('utilization')
                ->values();

            if ($under->isNotEmpty()) {
                $worst = $under->first();
                $out[] = [
                    'key' => 'under_target',
                    'tone' => 'warning',
                    'title' => $under->count() === 1
                        ? $worst['name'].' is under target'
                        : $under->count().' people are under target',
                    'detail' => sprintf(
                        '%s logged %s of a %s target (%.0f%%) over %d working days.',
                        $worst['name'],
                        $this->hours($worst['worked_hours']),
                        $this->hours($worst['target_hours']),
                        $worst['utilization'],
                        $totals['working_days']
                    ),
                ];
            }
        }

        // ── Team activity against the threshold ────────────────────────────
        // Needs >= 20h of tracked time before the gauge means anything.
        if ($totals['activity_percent'] !== null && $totals['tracked_seconds'] >= 20 * 3600) {
            $activity = $totals['activity_percent'];
            $out[] = [
                'key' => 'team_activity',
                'tone' => $activity >= self::ACTIVITY_HEALTHY ? 'success' : ($activity >= self::ACTIVITY_LOW ? 'info' : 'warning'),
                'title' => sprintf('Team activity is %d%% across tracked time', $activity),
                'detail' => $activity >= self::ACTIVITY_HEALTHY
                    ? sprintf('At or above the %d%% healthy band over %s of tracked work.', self::ACTIVITY_HEALTHY, $this->hours($totals['tracked_hours']))
                    : sprintf('Below the %d%% healthy band over %s of tracked work. Quiet work (calls, reading, review) scores low, so read this with the trend, not on its own.', self::ACTIVITY_HEALTHY, $this->hours($totals['tracked_hours'])),
            ];
        }

        // ── Busiest day ────────────────────────────────────────────────────
        // Only when there is more than one day WITH work to compare.
        $withWork = array_values(array_filter($trend, fn ($d) => $d['value'] > 0));

        if (count($withWork) >= 2) {
            usort($withWork, fn ($a, $b) => $b['value'] <=> $a['value']);
            $peak = $withWork[0];
            $mean = array_sum(array_column($withWork, 'value')) / count($withWork);

            if ($mean > 0 && $peak['value'] >= $mean * 1.25) {
                $out[] = [
                    'key' => 'busiest_day',
                    'tone' => 'info',
                    'title' => $peak['label'].' was the busiest day',
                    'detail' => sprintf(
                        '%s logged, against a %s daily average across days with work.',
                        $this->hours($peak['value']),
                        $this->hours(round($mean, 2))
                    ),
                ];
            }
        }

        // ── Dominant work category ─────────────────────────────────────────
        // Only when the workspace actually splits its time across >= 2
        // categories, and one of them takes more than half the range.
        if (count($categories) >= 2 && $categories[0]['share'] >= 50) {
            $out[] = [
                'key' => 'dominant_category',
                'tone' => 'info',
                'title' => $categories[0]['name'].' took '.round($categories[0]['share']).'% of the time',
                'detail' => sprintf(
                    '%s of %s, spread across %d categories.',
                    $this->hours($categories[0]['hours']),
                    $this->hours($totals['worked_hours']),
                    count($categories)
                ),
            ];
        }

        // ── People with no target ──────────────────────────────────────────
        // Actionable, and the reason utilization may look incomplete.
        if ($totals['people_without_target'] > 0 && $totals['people_with_target'] > 0) {
            $out[] = [
                'key' => 'missing_targets',
                'tone' => 'warning',
                'title' => $totals['people_without_target'].' of '.$totals['people'].' people have no target',
                'detail' => 'Utilization is calculated only for people whose shift sets a length. Set shift hours on the person or their named shift to include them.',
            ];
        }

        return $out;
    }

    private function hours(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 1, '.', ','), '0'), '.').'h';
    }

    /**
     * The shape a brand-new (or fully filtered-out) workspace gets. Same keys as
     * a full payload so the page never has to null-check its way through.
     */
    private function emptyPayload(Carbon $start, Carbon $end): array
    {
        $trend = [];
        $cursor = $start->copy();

        while ($cursor->lessThanOrEqualTo($end)) {
            $trend[] = ['date' => $cursor->toDateString(), 'label' => $cursor->format('M j'), 'value' => 0.0];
            $cursor->addDay();
        }

        return [
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => (int) $start->diffInDays($end) + 1,
                'working_days' => count($this->workingDays($start, $end)),
                'max_days' => self::MAX_RANGE_DAYS,
            ],
            'rows' => [],
            'totals' => [
                'people' => 0,
                'people_with_time' => 0,
                'people_with_target' => 0,
                'people_without_target' => 0,
                'worked_hours' => 0.0,
                'billable_hours' => 0.0,
                'non_billable_hours' => 0.0,
                'tracked_hours' => 0.0,
                'manual_hours' => 0.0,
                'target_hours' => null,
                'utilization' => null,
                'billable_ratio' => null,
                'activity_percent' => null,
                'tracked_seconds' => 0,
                'working_days' => count($this->workingDays($start, $end)),
                'avg_hours_per_person' => 0.0,
            ],
            'trend' => $trend,
            'ribbon' => [],
            'categories' => [],
            'highlights' => [],
            'thresholds' => [
                'activity_healthy' => self::ACTIVITY_HEALTHY,
                'activity_low' => self::ACTIVITY_LOW,
                'utilization_on_target' => self::UTILIZATION_ON_TARGET,
                'utilization_under' => self::UTILIZATION_UNDER,
            ],
        ];
    }
}
