<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkHour;
use App\Services\DemoDataService;
use App\Services\GettingStartedChecklist;
use App\Support\BusinessTime;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** How many rows the Top projects card ranks. */
    private const TOP_PROJECTS = 5;

    /**
     * How far back Top projects looks. BOUNDED ON PURPOSE: this is a dashboard
     * card, not a report, and an unbounded SUM over a tenant with a million
     * work-hour rows would be paid for on every single page load. Seven days
     * also sits inside the Free plan's 7-day retention, so no plan ever sees a
     * window it is not entitled to keep data for.
     */
    private const TOP_PROJECTS_DAYS = 7;

    /**
     * Points per KPI sparkline. The trend block always ships exactly this many
     * values per series (zero-filled), and the deltas compare this window
     * against the equally-sized window immediately before it — so the total
     * range read from the database is 2 × KPI_TREND_DAYS, bounded by design.
     */
    private const KPI_TREND_DAYS = 14;

    /**
     * TTL of the cached KPI trend block. Longer than topProjects' 60s because
     * a 28-day four-table aggregate is heavier than the 7-day ranking, and a
     * sparkline being up to five minutes behind is invisible; the live "today"
     * numbers beside it come from the polled today-summary endpoint anyway.
     */
    private const KPI_TREND_TTL_MINUTES = 5;

    public function __construct(private CurrentOrganization $current) {}

    /**
     * The live dashboard fetches its attendance and tracking summary from the
     * dedicated time-entry endpoints. Keeping this initial response lean avoids
     * calculating an unused legacy analytics payload on every page load.
     *
     * Two first-run props ride along, and ONLY for somebody who could act on
     * them (the workspace owner/admin — a member cannot invite people, install
     * software fleet-wide or load sample data, so serving them the checklist
     * would be noise and four wasted queries):
     *
     *  • `gettingStarted` — the four-step checklist, every step's done-state
     *    derived from real rows. See {@see GettingStartedChecklist}.
     *  • `demoData` — whether this workspace currently has sample data loaded,
     *    which is likewise derived, never stored. See {@see DemoDataService}.
     *
     * And `topProjects`, which is the card's ONLY data source — see
     * {@see topProjects()} for why it is now wired instead of decorative.
     */
    public function index(GettingStartedChecklist $checklist, DemoDataService $demo): Response
    {
        $user = auth()->user();
        $canManage = $user->isSuperAdmin() || $user->hasPermission('users.manage');

        $props = [
            'needsOnboarding' => $user->isSuperAdmin()
                && (bool) $user->organization?->needsOnboarding(),
            'topProjects' => $this->topProjects($user),
        ];

        // KPI trend block — ONLY for people who already see the TEAM-WIDE
        // today-summary (dashboard.view_team / attendance.view, which
        // hasAnyPermission also grants to the super admin). The series are
        // team totals, so serving them to a member would both leak team-level
        // figures and add four queries to a page that costs members nothing
        // extra today.
        if ($user->hasAnyPermission(['dashboard.view_team', 'attendance.view'])) {
            $props['kpiTrends'] = $this->kpiTrends();
        }

        if ($canManage) {
            $loaded = $demo->isLoaded();
            $steps = $checklist->build($user);

            $props['gettingStarted'] = $steps;
            $props['demoData'] = array_merge(
                $loaded ? $demo->summary() : ['loaded' => false, 'people' => 0, 'entries' => 0, 'days' => DemoDataService::DAYS],
                // Sample teammates no longer consume plan seats (they are
                // excluded from User::seatUsage), so headroom is a constant and
                // costs nothing to ask for.
                ['seat_headroom' => $loaded ? 0 : $demo->seatHeadroom()]
            );
        }

        return Inertia::render('Dashboard', $props);
    }

    /**
     * TOP PROJECTS — hours per client over a bounded recent window.
     *
     * This card used to render a permanent empty state for every real user
     * because nothing ever fed it: a card that can never fill is worse than no
     * card, so it is now wired to the same rows the work diary and reports read.
     *
     * SCOPE follows PERMISSION, not plan:
     *  • somebody who may already see everyone's hours (reports.view /
     *    work_hours.manage_all / super admin) gets the workspace ranking;
     *  • everybody else gets THEIR OWN projects — the card is still true and
     *    still useful, and no one is shown an hour they could not otherwise see.
     * No entitlement gates work entries or clients (they are core to every
     * plan, Free included), so there is deliberately no entitlement check here.
     *
     * COST on a 1M-row tenant: two aggregates, both bounded to seven days and
     * both served by the existing `work_hours_org_date_idx`
     * (organization_id, date) — or `work_hours_org_user_date_idx` for the
     * personal scope. Nothing is ever summed in PHP, no row is ever hydrated,
     * and the result is memoised for five minutes under an ORG-SCOPED key so a
     * dashboard left open on a wall display does not re-run it every refresh.
     *
     * @return array{scope: string, days: int, items: array<int, array<string, mixed>>}
     */
    private function topProjects(User $user): array
    {
        $orgWide = $user->isSuperAdmin()
            || $user->hasAnyPermission(['reports.view', 'work_hours.manage_all']);

        $orgId = $this->current->id();
        $scope = $orgWide ? 'org' : 'u'.$user->id;

        $items = Cache::remember(
            self::topProjectsCacheKey($orgId, $scope),
            // Deliberately short. The cache exists so a 100-person workspace
            // doesn't re-run the aggregate on every dashboard load, and a
            // bounded 7-day index range scan once a minute is still negligible
            // at 500 orgs. Five minutes bought nothing extra and cost the case
            // that actually matters: a workspace that has just logged its first
            // hour — or just loaded sample data — sat looking empty long enough
            // for the customer to conclude the card was broken. Observed live.
            now()->addSeconds(60),
            fn () => $this->rankProjects($orgWide ? null : (int) $user->id)
        );

        return [
            'scope' => $orgWide ? 'team' : 'self',
            'days' => self::TOP_PROJECTS_DAYS,
            'items' => $items,
        ];
    }

    /**
     * The org id is part of the key, always — a cache entry must never be
     * reachable from another tenant.
     */
    public static function topProjectsCacheKey(int|string $orgId, string $scope): string
    {
        return "dashboard.top-projects.{$orgId}.{$scope}";
    }

    /**
     * Drop the ranking for a whole workspace.
     *
     * Called when something adds or removes a bulk of work hours behind the
     * user's back — loading and removing sample data — where waiting out even a
     * short TTL would show the customer a card that contradicts the banner
     * directly above it. Per-user scopes are keyed individually, so this clears
     * the org-wide entry plus any member scope we know about.
     */
    public static function forgetTopProjects(int|string $orgId, array $userIds = []): void
    {
        Cache::forget(self::topProjectsCacheKey($orgId, 'org'));

        // The KPI trend block reads the same tables over a wider window, so a
        // bulk add/remove of work hours (demo data) invalidates it too.
        Cache::forget(self::kpiTrendsCacheKey($orgId));

        foreach ($userIds as $id) {
            Cache::forget(self::topProjectsCacheKey($orgId, 'u'.$id));
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function rankProjects(?int $userId): array
    {
        $today = CarbonImmutable::today(BusinessTime::tz());
        $from = $today->subDays(self::TOP_PROJECTS_DAYS - 1)->toDateString();
        $to = $today->toDateString();

        // Sargable: a plain range on the indexed `date` column, no function
        // wrapped around it. OrganizationScope adds organization_id, which is
        // the leading column of the composite index.
        $window = fn () => WorkHour::query()
            ->whereBetween('date', [$from, $to])
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId));

        $ranked = $window()
            ->whereNotNull('client_id')
            ->groupBy('client_id')
            ->orderByDesc(DB::raw('SUM(hours)'))
            ->limit(self::TOP_PROJECTS)
            ->get([
                'client_id',
                DB::raw('SUM(hours) as total_hours'),
            ]);

        if ($ranked->isEmpty()) {
            return [];
        }

        // Grand total of the SAME window, so "x% of logged time" is honest
        // rather than a share of the top five. One more bounded aggregate.
        $overall = (float) $window()->sum('hours');
        $leader = (float) $ranked->first()->total_hours;

        // One read for the names — tenant-scoped, so a client id that somehow
        // escaped the join can still never resolve across organizations.
        $names = Client::query()
            ->whereIn('id', $ranked->pluck('client_id')->all())
            ->pluck('name', 'id');

        return $ranked->map(function ($row) use ($names, $overall, $leader) {
            $hours = (float) $row->total_hours;

            return [
                'label' => $names[$row->client_id] ?? 'Unknown',
                'hours' => round($hours, 2),
                // Bar width is relative to the leader; the meta line is the
                // share of everything logged in the window.
                'pct' => $leader > 0 ? (int) round($hours / $leader * 100) : 0,
                'share' => $overall > 0 ? (int) round($hours / $overall * 100) : 0,
            ];
        })->values()->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // KPI TRENDS — the sparklines + deltas behind the dashboard's KPI cards
    // ─────────────────────────────────────────────────────────────────────────

    /** Org-scoped, always — a cached trend must never leak across tenants. */
    public static function kpiTrendsCacheKey(int|string $orgId): string
    {
        return "dashboard.kpi-trends.{$orgId}";
    }

    /**
     * Four daily series over the last KPI_TREND_DAYS business days, each with a
     * delta comparing that window to the window immediately before it.
     *
     * DEFINITIONS deliberately match the numbers already on the dashboard, so a
     * sparkline can never disagree with the big value beside it:
     *
     *  • hours        — SUM(work_hours.hours) per business day: the same table
     *                   and the same SUM the today-summary's "tracked" figure,
     *                   teamActivity's tracked_seconds and Insights' trend all
     *                   read. `date` is already the business day (every writer
     *                   stores Y-m-d, tracker sessions are split per day by
     *                   TrackingSessionService::syncWorkHour), so grouping
     *                   needs no timezone conversion.
     *  • active       — COUNT(DISTINCT user_id) over time_entries per
     *                   action_date: "people with any punch that day".
     *                   action_date is the attendance day the punch was FILED
     *                   under (shift-aware), i.e. the same bucketing the
     *                   attendance grid uses.
     *  • activity     — the tracker's session activity score, weighted by
     *                   tracked time, per day — the same weighting the
     *                   today-summary and teamActivity use. Bucketed by joining
     *                   each session to its per-day work_hours mirror rows, so
     *                   the day split is business-day-correct AND portable
     *                   (no driver-specific date function on a datetime).
     *  • breakMinutes — from the same break_start/break_end punches the
     *                   dashboard's break figure walks, summed per attendance
     *                   day in SQL via the signed-epoch pairing trick below.
     *
     * COST: exactly four grouped aggregates, every one a bounded 28-day range
     * scan riding an (organization_id, date-ish) composite index
     * (work_hours_org_date_idx twice, time_entries_org_action_date_idx twice),
     * memoised for five minutes under an org-scoped key. Row count out of the
     * database is ≤ 28 per query regardless of tenant size.
     */
    private function kpiTrends(): array
    {
        return Cache::remember(
            self::kpiTrendsCacheKey($this->current->id()),
            now()->addMinutes(self::KPI_TREND_TTL_MINUTES),
            fn () => $this->buildKpiTrends()
        );
    }

    private function buildKpiTrends(): array
    {
        $days = self::KPI_TREND_DAYS;
        $today = CarbonImmutable::today(BusinessTime::tz());
        $todayKey = $today->toDateString();

        // One 28-day window covers BOTH the visible series and the comparison
        // window, so each series still costs a single query.
        $start = $today->subDays(2 * $days - 1);
        $startStr = $start->toDateString();
        // Half-open upper bound: sargable, and still matches rows SQLite
        // persisted with a " 00:00:00" suffix through a date cast.
        $endExclusiveStr = $today->addDay()->toDateString();

        // ── Query 1: team hours per day (work_hours) ─────────────────────────
        $hoursByDate = WorkHour::query()
            ->where('date', '>=', $startStr)
            ->where('date', '<', $endExclusiveStr)
            ->selectRaw('date')
            ->selectRaw('SUM(hours) as total_hours')
            ->groupBy('date')
            ->get()
            ->mapWithKeys(fn ($row) => [substr((string) $row->date, 0, 10) => (float) $row->total_hours]);

        // ── Query 2: distinct people with any punch, per attendance day ─────
        $activeByDate = TimeEntry::query()
            ->where('action_date', '>=', $startStr)
            ->where('action_date', '<', $endExclusiveStr)
            ->selectRaw('action_date')
            ->selectRaw('COUNT(DISTINCT user_id) as people')
            ->groupBy('action_date')
            ->get()
            ->mapWithKeys(fn ($row) => [substr((string) $row->action_date, 0, 10) => (int) $row->people]);

        // ── Query 3: tracked-time-weighted activity % per day ────────────────
        // Each tracker session is mirrored into work_hours split per business
        // day (hours = that day's share of the session), so joining the mirror
        // rows back to their session buckets the session's activity score by
        // day with plain date grouping. The join carries an explicit
        // same-organization constraint as defence in depth — OrganizationScope
        // only guards the base table.
        $activityByDate = WorkHour::query()
            ->join('tracking_sessions', function ($join) {
                $join->on('tracking_sessions.id', '=', 'work_hours.tracking_session_id')
                    ->on('tracking_sessions.organization_id', '=', 'work_hours.organization_id');
            })
            ->where('work_hours.date', '>=', $startStr)
            ->where('work_hours.date', '<', $endExclusiveStr)
            ->selectRaw('work_hours.date as date')
            ->selectRaw('SUM(COALESCE(tracking_sessions.activity_percent, 0) * work_hours.hours) as weighted')
            ->selectRaw('SUM(work_hours.hours) as weight')
            ->groupBy('work_hours.date')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->date, 0, 10));

        // ── Query 4: break seconds per day (signed-epoch pairing) ────────────
        // TimeClockRules guarantees break_start/break_end alternate inside a
        // day, so Σ(end epochs) − Σ(start epochs) equals the summed length of
        // every matched pair — one grouped aggregate instead of a per-row
        // state machine. open_breaks counts starts without an end (someone on
        // break RIGHT NOW): for today's bucket each one is completed up to
        // "now", exactly like CalculatesTimeStats counts an ongoing break.
        $epoch = $this->sqlEpochExpression();
        $breaksByDate = TimeEntry::query()
            ->whereIn('action_type', ['break_start', 'break_end'])
            ->where('action_date', '>=', $startStr)
            ->where('action_date', '<', $endExclusiveStr)
            ->selectRaw('action_date')
            ->selectRaw("SUM(CASE WHEN action_type = 'break_end' THEN {$epoch} ELSE -({$epoch}) END) as signed_seconds")
            ->selectRaw("SUM(CASE WHEN action_type = 'break_start' THEN 1 ELSE -1 END) as open_breaks")
            ->groupBy('action_date')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->action_date, 0, 10));

        // "Now" on the SAME basis as the SQL epoch expression (the stored
        // wall-clock string read as UTC), so completing an open break never
        // mixes bases. Storage tz has no DST-sensitive tenants baked in — the
        // two constants cancel for every closed pair regardless.
        $nowEpoch = CarbonImmutable::now(BusinessTime::storageTz())
            ->format('Y-m-d H:i:s');
        $nowEpoch = CarbonImmutable::parse($nowEpoch, 'UTC')->getTimestamp();

        // ── Assemble: dense 28-day walk, split into previous/current windows ─
        $series = ['hours' => [], 'active' => [], 'activity' => [], 'breakMinutes' => []];
        $windows = [
            'prev' => ['hours' => 0.0, 'active' => 0, 'activityW' => 0.0, 'activityWt' => 0.0, 'breakMinutes' => 0],
            'cur' => ['hours' => 0.0, 'active' => 0, 'activityW' => 0.0, 'activityWt' => 0.0, 'breakMinutes' => 0],
        ];

        for ($i = 0; $i < 2 * $days; $i++) {
            $key = $start->addDays($i)->toDateString();
            $bucket = $i < $days ? 'prev' : 'cur';

            $hours = round((float) ($hoursByDate[$key] ?? 0), 2);

            $active = (int) ($activeByDate[$key] ?? 0);

            $act = $activityByDate->get($key);
            $actWeight = $act !== null ? (float) $act->weight : 0.0;
            $actWeighted = $act !== null ? (float) $act->weighted : 0.0;
            $activity = $actWeight > 0 ? round($actWeighted / $actWeight, 1) : 0.0;

            $breaks = $breaksByDate->get($key);
            $breakSeconds = $breaks !== null ? (float) $breaks->signed_seconds : 0.0;
            if ($breaks !== null && $key === $todayKey && (int) $breaks->open_breaks > 0) {
                $breakSeconds += (int) $breaks->open_breaks * $nowEpoch;
            }
            // max(0): an open break left across the day boundary (or a stale
            // never-ended one on a past day) must clamp to zero, never render
            // a negative bar.
            $breakMinutes = max(0, (int) round($breakSeconds / 60));

            $windows[$bucket]['hours'] += $hours;
            $windows[$bucket]['active'] += $active;
            $windows[$bucket]['activityW'] += $actWeighted;
            $windows[$bucket]['activityWt'] += $actWeight;
            $windows[$bucket]['breakMinutes'] += $breakMinutes;

            if ($bucket === 'cur') {
                $series['hours'][] = $hours;
                $series['active'][] = $active;
                $series['activity'][] = $activity;
                $series['breakMinutes'][] = $breakMinutes;
            }
        }

        // Window-level activity is the weighted average over the whole window
        // (not an average of daily averages — a 10-minute day must not outvote
        // a full one).
        $activityFor = fn (array $w) => $w['activityWt'] > 0 ? $w['activityW'] / $w['activityWt'] : 0.0;

        return [
            'days' => $days,
            'series' => $series,
            'deltas' => [
                'hours' => $this->trendDelta($windows['cur']['hours'], $windows['prev']['hours'], true),
                'active' => $this->trendDelta((float) $windows['cur']['active'], (float) $windows['prev']['active'], true),
                'activity' => $this->trendDelta($activityFor($windows['cur']), $activityFor($windows['prev']), true),
                // More break time is rest, not slacking — the UI renders this
                // one neutral (good: null), never red or green.
                'breakMinutes' => $this->trendDelta((float) $windows['cur']['breakMinutes'], (float) $windows['prev']['breakMinutes'], null),
            ],
        ];
    }

    /**
     * Percentage delta between the two windows.
     *
     * pct is the ABSOLUTE change rounded to 1dp (the arrow carries the sign).
     * direction is flat when the change is under 0.05% or both windows are
     * zero; a move from a zero window to a non-zero one reads as a 100% move
     * rather than a division by zero. good says whether the move HELPS
     * ($upIsGood inverted for a down move); null (flat, or a neutral metric
     * like break time) renders muted.
     *
     * @return array{pct: float, direction: string, good: ?bool}
     */
    private function trendDelta(float $current, float $previous, ?bool $upIsGood): array
    {
        if ($current == 0.0 && $previous == 0.0) {
            return ['pct' => 0.0, 'direction' => 'flat', 'good' => null];
        }

        $change = $previous == 0.0
            ? 100.0
            : ($current - $previous) / $previous * 100;

        if (abs($change) < 0.05) {
            return ['pct' => round(abs($change), 1), 'direction' => 'flat', 'good' => null];
        }

        $direction = $change > 0 ? 'up' : 'down';

        return [
            'pct' => round(abs($change), 1),
            'direction' => $direction,
            'good' => $upIsGood === null ? null : ($direction === 'up' ? $upIsGood : ! $upIsGood),
        ];
    }

    /**
     * The punch's stored wall-clock as epoch seconds, per driver.
     *
     * Both expressions read the persisted 'Y-m-d H:i:s' string AS IF it were
     * UTC — SQLite's strftime('%s') does that natively; on MySQL
     * TO_SECONDS() is purely calendrical (no session-timezone interpretation,
     * unlike UNIX_TIMESTAMP()), shifted by the calendar-epoch constant so both
     * drivers land on the same number. The absolute basis is irrelevant for
     * the paired differences and is matched exactly for the ongoing-break
     * "now" term (see buildKpiTrends).
     */
    private function sqlEpochExpression(): string
    {
        return (new TimeEntry)->getConnection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%s', action_timestamp) AS INTEGER)"
            : '(TO_SECONDS(action_timestamp) - 62167219200)';
    }
}
