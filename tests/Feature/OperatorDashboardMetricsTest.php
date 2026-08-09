<?php

namespace Tests\Feature;

use App\Http\Middleware\SharePlatformUser;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Tests\TestCase;

/**
 * The operator dashboard used to be a hollow shell (MRR/Active/Trials always
 * "—", empty charts, "No tenant activity" while tenants existed). These lock in
 * that it now serves REAL, landlord-scoped aggregates.
 */
class OperatorDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Operator',
            'email' => 'op@platform.test',
            'password' => 'operator-secret-pw',
            'role' => 'owner',
        ]);
    }

    public function test_dashboard_serves_real_metrics_not_an_empty_shell(): void
    {
        Cache::flush();
        app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw');
        app(TenantProvisioner::class)->provision('Initech', 'Owner', 'owner@initech.test', 'super-secret-pw');

        $this->actingAs($this->operator(), 'platform')
            ->get(route('platform.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('PlatformPortal/Dashboard')
                ->where('stats.tenants', 2)
                ->has('stats.mrr')
                ->has('stats.active')
                ->has('stats.trials')
                ->has('stats.staff')
                ->has('growthTrend', 6)          // 6-month signup series
                ->has('plans', 1)                // both tenants on the same trial plan
                ->has('tenants', 2));            // recent-tenant activity table populated
    }

    public function test_mrr_counts_only_active_tenants_per_seat(): void
    {
        Cache::flush();
        $org = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['organization'];

        // Make it a paying, active tenant on a custom $10/seat price with 1 seat.
        $org->forceFill([
            'status' => Organization::STATUS_ACTIVE,
            'custom_price_cents' => 1000,
        ])->save();
        $org->subscription->update(['status' => 'active']);

        Cache::flush();

        $this->actingAs($this->operator(), 'platform')
            ->get(route('platform.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('stats.active', 1)
                ->where('stats.mrr', 10)); // $10/seat × 1 owner seat
    }

    public function test_operator_theme_resolves_from_the_cookie_not_the_null_web_user(): void
    {
        // On the platform host the customer `web` user is always null, so
        // SharePlatformUser must re-resolve the operator's theme from tt_theme.
        $this->be($this->operator(), 'platform');

        $request = Request::create('/dashboard', 'GET');
        $request->cookies->set('tt_theme', 'black');

        app(SharePlatformUser::class)->handle($request, fn () => response('ok'));

        $this->assertSame('black', Inertia::getShared('theme'));
    }
}
