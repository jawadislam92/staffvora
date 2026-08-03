<?php

namespace App\Http\Controllers;

use App\Models\Client;
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
}
