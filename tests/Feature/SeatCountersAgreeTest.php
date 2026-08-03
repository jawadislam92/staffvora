<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\BillingPricing;
use App\Services\DemoDataService;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REGRESSION: the PLAN-LIMIT counter and the BILLING counter may never disagree.
 *
 * This is the inversion of the audit's proof test. The hole it proved: the
 * product says in two places that sample-data teammates are free, and
 * {@see User::seatUsage()} agreed — but {@see BillingPricing::billableSeats()}
 * ran its own `COUNT(*) WHERE is_active` that counted them, so clicking "Load
 * sample data" silently added three seats to the plan line AND to every
 * per-seat add-on line on the invoice preview. That is charging a customer for
 * something we told them was free.
 *
 * Both counters are now built from one scope, {@see User::scopeSeatConsuming()}.
 * The only sanctioned difference is the billing floor of 1 (a workspace always
 * has at least its owner), which is asserted explicitly rather than tolerated.
 */
class SeatCountersAgreeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** A fresh BillingPricing every time — the memo cache must not hide a drift. */
    private function billed(Organization $organization): int
    {
        return app()->makeWith(BillingPricing::class, [])->billableSeats($organization->fresh());
    }

    private function assertCountersAgree(Organization $organization, string $shape): void
    {
        $limitCounter = app(CurrentOrganization::class)->runFor($organization, fn () => User::seatUsage());

        $this->assertSame(
            max(1, $limitCounter),
            $this->billed($organization),
            "The billing counter and the plan-limit counter disagree ({$shape}). ".
            'They must both be built from User::scopeSeatConsuming().',
        );
    }

    public function test_loading_sample_data_does_not_raise_the_invoice(): void
    {
        $result = app(TenantProvisioner::class)
            ->provision('Acme Co', 'Owner', 'owner@acme.test', 'super-secret-pw', null, 14, 'general');
        $org = $result['organization'];
        app(CurrentOrganization::class)->set($org);

        $this->assertSame(1, $this->billed($org));

        app(DemoDataService::class)->load();

        // The plan-limit counter ignores the sample...
        $this->assertSame(1, User::seatUsage());
        // ...and so, now, does the money.
        $this->assertSame(1, $this->billed($org), 'demo teammates must not be billed as real seats');

        $preview = app()->makeWith(BillingPricing::class, [])->invoicePreview($org->fresh(), 'monthly');
        $this->assertSame(1, $preview['seats']);

        // Removing the sample changes nothing either — it was never priced.
        app(DemoDataService::class)->remove();
        $this->assertSame(1, $this->billed($org));
    }

    public function test_the_two_counters_agree_across_every_workspace_shape(): void
    {
        $result = app(TenantProvisioner::class)
            ->provision('Acme Co', 'Owner', 'owner@acme.test', 'super-secret-pw', null, 14, 'general');
        $org = $result['organization'];
        app(CurrentOrganization::class)->set($org);

        $this->assertCountersAgree($org, 'owner only');

        foreach (['one@acme.test', 'two@acme.test'] as $email) {
            User::create([
                'name' => 'Hire '.$email,
                'email' => $email,
                'password' => 'a-very-long-password',
                'role' => 'member',
            ]);
        }
        $this->assertSame(3, User::seatUsage());
        $this->assertCountersAgree($org, 'owner + 2 real hires');

        app(DemoDataService::class)->load();
        $this->assertCountersAgree($org, 'sample data loaded');

        // Someone leaves: neither billed nor counted against the plan.
        User::where('email', 'two@acme.test')->firstOrFail()->update(['is_active' => false]);
        $this->assertSame(2, User::seatUsage());
        $this->assertCountersAgree($org, 'one person deactivated');

        // ...and comes back.
        User::where('email', 'two@acme.test')->firstOrFail()->update(['is_active' => true]);
        $this->assertCountersAgree($org, 'reactivated');

        app(DemoDataService::class)->remove();
        $this->assertCountersAgree($org, 'sample data removed');
    }

    /**
     * The floor of 1 is the ONLY difference allowed, and it belongs to billing
     * (a workspace still costs money even if every row is deactivated); the
     * plan limit must not inherit it, or a full plan would read as having a
     * seat spare.
     */
    public function test_only_the_billing_floor_may_differ(): void
    {
        $result = app(TenantProvisioner::class)
            ->provision('Acme Co', 'Owner', 'owner@acme.test', 'super-secret-pw', null, 14, 'general');
        $org = $result['organization'];
        app(CurrentOrganization::class)->set($org);

        User::query()->update(['is_active' => false]);

        $this->assertSame(0, User::seatUsage());
        $this->assertSame(1, $this->billed($org));
    }

    /** Neither counter may see another tenant's people. */
    public function test_both_counters_stay_inside_one_tenant(): void
    {
        $a = app(TenantProvisioner::class)
            ->provision('Acme Co', 'Owner', 'owner@acme.test', 'super-secret-pw', null, 14, 'general')['organization'];
        $b = app(TenantProvisioner::class)
            ->provision('Beta Co', 'Owner', 'owner@beta.test', 'super-secret-pw', null, 14, 'general')['organization'];

        app(CurrentOrganization::class)->runFor($b, fn () => User::create([
            'name' => 'Beta Hire',
            'email' => 'hire@beta.test',
            'password' => 'a-very-long-password',
            'role' => 'member',
        ]));

        $this->assertCountersAgree($a, 'tenant A');
        $this->assertCountersAgree($b, 'tenant B');
        $this->assertSame(1, $this->billed($a));
        $this->assertSame(2, $this->billed($b));
    }
}
