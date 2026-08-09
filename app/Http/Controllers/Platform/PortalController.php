<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\Scopes\OrganizationScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * The operator portal's own pages (dashboard, later: staff management). The
 * tenant/plan/website console pages get mounted under the portal in milestone 2.
 */
class PortalController extends Controller
{
    public function dashboard(): \Inertia\Response
    {
        $metrics = $this->metrics();

        return Inertia::render('PlatformPortal/Dashboard', [
            // Staff count stays outside the cached block so an invite/revoke is
            // reflected immediately; it is a single cheap COUNT.
            'stats' => array_merge($metrics['stats'], ['staff' => PlatformUser::count()]),
            'growthTrend' => $metrics['growthTrend'],
            'plans' => $metrics['plans'],
            'tenants' => $metrics['tenants'],
        ]);
    }

    /**
     * Whole-estate operator metrics, landlord-scoped (NOT tenant-scoped) and
     * cached for 60s. The organizations table has one row per tenant (bounded by
     * the number of customers, not by the ~1M activity rows), so a single pass
     * with eager-loaded subscription/plan + a seat-count subquery is safe and
     * avoids N+1. Every figure below is real: no metric is faked with a dash.
     *
     * @return array{stats: array<string, int>, growthTrend: list<array{label: string, value: int}>, plans: list<array{label: string, value: int, meta: ?string}>, tenants: list<array<string, mixed>>}
     */
    private function metrics(): array
    {
        return Cache::remember('platform:dashboard:metrics:v1', 60, function () {
            $orgs = Organization::query()
                ->with('subscription.plan')
                ->withCount(['users' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
                ->get();

            $byStatus = $orgs->groupBy('status')->map->count();
            $tenants = $orgs->count();
            $active = (int) ($byStatus[Organization::STATUS_ACTIVE] ?? 0);
            $trialing = (int) ($byStatus[Organization::STATUS_TRIAL] ?? 0);

            // MRR — the monthly run-rate over ACTIVE tenants only. Prices are
            // per-seat (see /plans "USD/seat"), so a company's monthly recurring
            // charge is effective per-seat price × occupied seats. Trials and
            // suspended tenants contribute nothing.
            $mrrCents = 0;
            foreach ($orgs as $o) {
                if ($o->status !== Organization::STATUS_ACTIVE) {
                    continue;
                }
                $perSeat = $o->effectivePriceCents();
                if (! $perSeat) {
                    continue;
                }
                $mrrCents += $perSeat * (int) $o->users_count;
            }

            // Plan distribution across every subscribed tenant.
            $planCounts = [];
            foreach ($orgs as $o) {
                $name = $o->subscription?->plan?->name;
                if ($name) {
                    $planCounts[$name] = ($planCounts[$name] ?? 0) + 1;
                }
            }
            $subscribed = array_sum($planCounts);
            $plans = collect($planCounts)
                ->map(fn ($count, $name) => [
                    'label' => $name,
                    'value' => $count,
                    'meta' => $subscribed ? round($count / $subscribed * 100).'% of tenants' : null,
                ])
                ->sortByDesc('value')
                ->values()
                ->all();

            // 6-month signup trend. Bucketed in PHP (not SQL) so the query is
            // identical on SQLite tests and MySQL prod — no DATE_FORMAT/strftime
            // dialect split. Safe because the org row count is small.
            $months = collect(range(5, 0))->map(fn ($i) => Carbon::now()->startOfMonth()->subMonths($i));
            $counts = array_fill(0, 6, 0);
            foreach ($orgs as $o) {
                if (! $o->created_at) {
                    continue;
                }
                $bucket = $o->created_at->copy()->startOfMonth();
                $idx = $months->search(fn ($m) => $m->equalTo($bucket));
                if ($idx !== false) {
                    $counts[$idx]++;
                }
            }
            $growthTrend = $months->map(fn ($m, $i) => [
                'label' => $m->format('M'),
                'value' => $counts[$i],
            ])->values()->all();

            // 5 newest tenants for the activity table.
            $recent = $orgs
                ->sortByDesc(fn ($o) => $o->created_at?->getTimestamp() ?? 0)
                ->take(5)
                ->map(fn ($o) => [
                    'name' => $o->name,
                    'plan' => $o->subscription?->plan?->name ?? '—',
                    'seats' => (int) $o->users_count,
                    'mrr' => $o->status === Organization::STATUS_ACTIVE && $o->effectivePriceCents()
                        ? (int) round($o->effectivePriceCents() * (int) $o->users_count / 100)
                        : 0,
                    'status' => $o->status,
                    'joined' => $o->created_at?->format('M j') ?? '—',
                ])
                ->values()
                ->all();

            return [
                'stats' => [
                    'tenants' => $tenants,
                    'active' => $active,
                    'trials' => $trialing,
                    'mrr' => (int) round($mrrCents / 100),
                ],
                'growthTrend' => $growthTrend,
                'plans' => $plans,
                'tenants' => $recent,
            ];
        });
    }
}
