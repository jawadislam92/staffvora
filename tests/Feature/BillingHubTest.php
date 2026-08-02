<?php

namespace Tests\Feature;

use App\Http\Controllers\BillingController;
use App\Models\Addon;
use App\Models\BillingProfile;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\AddonPurchase;
use App\Services\BillingPricing;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The customer billing hub + the add-on purchase path.
 *
 * Two things these tests exist to pin down:
 *
 *  1. THE MONEY IS THE SERVER'S. Every amount is re-derived from the plan/
 *     add-on rows and a live seat count. A request that posts its own price,
 *     seat count or amount changes nothing.
 *  2. NOTHING PAID IS GRANTED FOR FREE. With no payment gateway wired, adding
 *     an add-on records a PENDING charge and grants no entitlement whatsoever;
 *     only an operator confirmation writes the pivot.
 *
 * ROUTES: `routes/web.php` is owned by another agent in this build, so the
 * billing routes may not be registered yet. `ensureRoutes()` registers exactly
 * the lines this task asks to be added, and ONLY when they are missing — so
 * these tests give real HTTP coverage now and switch to the real registration
 * the moment it lands, with no edit.
 */
class BillingHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRoutes();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Register the billing routes if routes/web.php doesn't carry them yet. */
    private function ensureRoutes(): void
    {
        if (Route::has('billing.addons.request')) {
            return;
        }

        Route::middleware(['web', 'auth'])->group(function () {
            if (! Route::has('billing.index')) {
                Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
                Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
            }

            Route::post('/billing/addons', [BillingController::class, 'requestAddon'])
                ->middleware('permission:billing.manage')->name('billing.addons.request');
            Route::delete('/billing/addons/{addon:key}', [BillingController::class, 'removeAddon'])
                ->middleware('permission:billing.manage')->name('billing.addons.remove');
            Route::put('/billing/cycle', [BillingController::class, 'updateCycle'])
                ->middleware('permission:billing.manage')->name('billing.cycle');
            Route::put('/billing/details', [BillingController::class, 'updateDetails'])
                ->middleware('permission:billing.manage')->name('billing.details');
        });

        // The name lookup table was already built when the service provider
        // loaded routes/web.php, so newly-registered names need rebuilding
        // before route() can resolve them.
        Route::getRoutes()->refreshNameLookups();
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    /**
     * A workspace on Growth with a known period and a known head-count.
     *
     * @return array{owner: User, org: Organization}
     */
    private function workspace(int $extraUsers = 0, ?string $periodEnd = null): array
    {
        $owner = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];
        $org = Organization::find($owner->organization_id);

        app(CurrentOrganization::class)->runFor($org, function () use ($org, $extraUsers, $periodEnd) {
            $org->subscription->update([
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'current_period_start' => Carbon::now(),
                'current_period_end' => $periodEnd ? Carbon::parse($periodEnd) : Carbon::now()->addDays(15),
            ]);

            for ($i = 1; $i <= $extraUsers; $i++) {
                User::create([
                    'organization_id' => $org->id,
                    'name' => "User $i",
                    'email' => "user$i@acme.test",
                    'password' => bcrypt('super-secret-pw'),
                    'role' => 'member',
                ]);
            }
        });

        return ['owner' => $owner, 'org' => $org->refresh()];
    }

    private function agencyAddon(): Addon
    {
        return Addon::where('key', 'agency_toolkit')->firstOrFail();
    }

    // -----------------------------------------------------------------------
    // The maths — proration and yearly vs monthly
    // -----------------------------------------------------------------------

    public function test_monthly_addon_quote_is_seats_times_price_prorated_to_the_period_end(): void
    {
        // Frozen so the cycle length is a known 31 days (1 Mar → 1 Apr).
        Carbon::setTestNow('2026-03-01 00:00:00');

        ['org' => $org] = $this->workspace(extraUsers: 3, periodEnd: '2026-03-16 00:00:00');
        $addon = $this->agencyAddon(); // $4.00 / seat / month

        $quote = app(BillingPricing::class)->quoteAddon($org, $addon, 'monthly');

        $this->assertSame(4, $quote['seats']);              // owner + 3
        $this->assertSame(400, $quote['unit_price_cents']);
        $this->assertSame(1600, $quote['full_period_cents']); // 4 × $4.00
        $this->assertSame(31, $quote['cycle_days']);
        $this->assertSame(15, $quote['days_remaining']);
        // round(1600 × 15 / 31) = round(774.19…) = 774
        $this->assertSame(774, $quote['amount_cents']);
        $this->assertTrue($quote['priced']);
    }

    public function test_yearly_addon_quote_uses_the_yearly_column_and_a_year_long_cycle(): void
    {
        Carbon::setTestNow('2026-03-01 00:00:00');

        ['org' => $org] = $this->workspace(extraUsers: 3, periodEnd: '2026-03-16 00:00:00');

        $quote = app(BillingPricing::class)->quoteAddon($org, $this->agencyAddon(), 'yearly');

        $this->assertSame(4000, $quote['unit_price_cents']);   // $40 / seat / YEAR
        $this->assertSame(16000, $quote['full_period_cents']);
        $this->assertSame(365, $quote['cycle_days']);
        $this->assertSame(15, $quote['days_remaining']);
        // round(16000 × 15 / 365) = round(657.53…) = 658
        $this->assertSame(658, $quote['amount_cents']);
    }

    public function test_proration_never_exceeds_one_full_cycle(): void
    {
        Carbon::setTestNow('2026-03-01 00:00:00');

        // A yearly subscription: the period end is 300 days away, but a MONTHLY
        // add-on may never be charged more than one month.
        ['org' => $org] = $this->workspace(periodEnd: '2026-12-26 00:00:00');

        $quote = app(BillingPricing::class)->quoteAddon($org, $this->agencyAddon(), 'monthly');

        $this->assertSame($quote['cycle_days'], $quote['days_remaining']);
        $this->assertSame($quote['full_period_cents'], $quote['amount_cents']);
    }

    public function test_proration_is_never_free_and_never_negative(): void
    {
        Carbon::setTestNow('2026-03-01 00:00:00');

        // The period ended in the past — the remainder is clamped to one day,
        // so a customer can never acquire an add-on for nothing.
        ['org' => $org] = $this->workspace(periodEnd: '2026-02-01 00:00:00');

        $quote = app(BillingPricing::class)->quoteAddon($org, $this->agencyAddon(), 'monthly');

        $this->assertSame(1, $quote['days_remaining']);
        $this->assertGreaterThan(0, $quote['amount_cents']);
        $this->assertLessThanOrEqual($quote['full_period_cents'], $quote['amount_cents']);
    }

    public function test_yearly_plan_price_is_the_yearly_column_not_twelve_monthlies(): void
    {
        ['org' => $org] = $this->workspace(extraUsers: 4);
        $pricing = app(BillingPricing::class);

        // Growth: $9 / seat / month, $90 / seat / YEAR (10 months, not 12).
        $this->assertSame(900, $pricing->planUnitPriceCents($org, 'monthly'));
        $this->assertSame(9000, $pricing->planUnitPriceCents($org, 'yearly'));

        $previews = $pricing->invoicePreviews($org);

        $this->assertSame(5 * 900, $previews['monthly']['total_cents']);
        $this->assertSame(5 * 9000, $previews['yearly']['total_cents']);
        // Twelve monthlies minus one yearly = two months' saving.
        $this->assertSame((5 * 900 * 12) - (5 * 9000), $previews['saving_cents']);
    }

    public function test_a_discount_reduces_the_plan_line_in_integer_cents(): void
    {
        ['org' => $org] = $this->workspace();
        $org->update(['discount_percent' => 25]);

        $this->assertSame(675, app(BillingPricing::class)->planUnitPriceCents($org->refresh(), 'monthly'));
    }

    public function test_a_cycle_with_no_list_price_is_never_priced_at_zero(): void
    {
        ['org' => $org] = $this->workspace();
        $addon = $this->agencyAddon();
        $addon->update(['price_cents_yearly' => null]);

        $quote = app(BillingPricing::class)->quoteAddon($org->refresh(), $addon->refresh(), 'yearly');

        $this->assertFalse($quote['priced']);
        $this->assertNull($quote['unit_price_cents']);
        $this->assertNull($quote['amount_cents']);
    }

    public function test_a_negotiated_custom_price_is_never_extrapolated_into_a_yearly_one(): void
    {
        ['org' => $org] = $this->workspace();
        $org->update(['custom_price_cents' => 333]);
        $org->refresh();

        $pricing = app(BillingPricing::class);

        $this->assertSame(333, $pricing->planUnitPriceCents($org, 'monthly'));
        // No invented "custom × 10" yearly figure — it has to be negotiated.
        $this->assertNull($pricing->planUnitPriceCents($org, 'yearly'));
    }

    public function test_the_invoice_preview_includes_every_active_addon_line(): void
    {
        ['org' => $org] = $this->workspace(extraUsers: 1); // 2 seats
        $org->grantAddon($this->agencyAddon());

        $preview = app(BillingPricing::class)->invoicePreview($org->refresh(), 'monthly');

        $this->assertCount(2, $preview['lines']);
        $this->assertSame(2 * 900, $preview['lines'][0]['amount_cents']);
        $this->assertSame(2 * 400, $preview['lines'][1]['amount_cents']);
        $this->assertSame(2 * 1300, $preview['total_cents']);
    }

    // -----------------------------------------------------------------------
    // The purchase path
    // -----------------------------------------------------------------------

    public function test_requesting_an_addon_records_a_pending_charge_and_grants_nothing(): void
    {
        Carbon::setTestNow('2026-03-01 00:00:00');

        ['owner' => $owner, 'org' => $org] = $this->workspace(extraUsers: 3, periodEnd: '2026-03-16 00:00:00');

        $this->actingAs($owner)
            ->post(route('billing.addons.request'), ['addon' => 'agency_toolkit', 'cycle' => 'monthly'])
            ->assertSessionHasNoErrors();

        $charge = app(CurrentOrganization::class)->runFor($org, fn () => Payment::query()->first());

        $this->assertNotNull($charge);
        $this->assertSame(Payment::STATUS_PENDING, $charge->status);
        $this->assertSame(Payment::KIND_ADDON, $charge->kind);
        $this->assertSame(774, $charge->amount_cents);   // the derived proration
        $this->assertSame(4, $charge->seats);
        $this->assertSame(400, $charge->unit_price_cents);
        $this->assertNull($charge->paid_at);
        $this->assertSame($owner->id, $charge->requested_by);

        // THE POINT: nothing was granted. No pivot row, no entitlement.
        $org->refresh();
        $this->assertSame(0, $org->addons()->count());
        $this->assertFalse($org->can('upwork_profiles'));
        $this->assertFalse($org->can('machine_compliance'));
    }

    public function test_a_tampered_request_cannot_set_its_own_price_seats_or_amount(): void
    {
        Carbon::setTestNow('2026-03-01 00:00:00');

        ['owner' => $owner, 'org' => $org] = $this->workspace(extraUsers: 3, periodEnd: '2026-03-16 00:00:00');

        $this->actingAs($owner)->post(route('billing.addons.request'), [
            'addon' => 'agency_toolkit',
            'cycle' => 'monthly',
            // Everything below is a lie the client is trying to tell us.
            'amount_cents' => 1,
            'unit_price_cents' => 0,
            'price_cents' => 0,
            'seats' => 1,
            'status' => 'confirmed',
            'paid_at' => '2026-03-01',
        ])->assertSessionHasNoErrors();

        $charge = app(CurrentOrganization::class)->runFor($org, fn () => Payment::query()->first());

        $this->assertSame(774, $charge->amount_cents);      // derived, not 1
        $this->assertSame(400, $charge->unit_price_cents);  // derived, not 0
        $this->assertSame(4, $charge->seats);               // counted, not 1
        $this->assertSame(Payment::STATUS_PENDING, $charge->status); // not 'confirmed'
        $this->assertNull($charge->paid_at);
    }

    public function test_confirming_the_charge_is_what_grants_the_entitlement(): void
    {
        ['owner' => $owner, 'org' => $org] = $this->workspace();

        $this->actingAs($owner)->post(route('billing.addons.request'), [
            'addon' => 'agency_toolkit', 'cycle' => 'monthly',
        ])->assertSessionHasNoErrors();

        $charge = app(CurrentOrganization::class)->runFor($org, fn () => Payment::query()->first());

        $this->assertFalse($org->refresh()->can('upwork_profiles'));

        app(AddonPurchase::class)->confirm($charge, recordedBy: 99);

        $charge->refresh();
        $this->assertSame(Payment::STATUS_CONFIRMED, $charge->status);
        $this->assertNotNull($charge->paid_at);
        $this->assertSame(99, (int) $charge->recorded_by);

        // Granted through the ONE engine — the pivot, not a new gate.
        $org->refresh();
        $this->assertSame(1, $org->activeAddons()->count());
        $this->assertTrue($org->can('upwork_profiles'));
        $this->assertTrue($org->can('machine_compliance'));
    }

    public function test_a_duplicate_request_never_creates_a_second_charge(): void
    {
        ['owner' => $owner, 'org' => $org] = $this->workspace();

        $payload = ['addon' => 'agency_toolkit', 'cycle' => 'monthly'];

        $this->actingAs($owner)->post(route('billing.addons.request'), $payload);
        $this->actingAs($owner)->post(route('billing.addons.request'), $payload);

        $count = app(CurrentOrganization::class)->runFor($org, fn () => Payment::query()->count());
        $this->assertSame(1, $count);
    }

    public function test_a_non_public_addon_cannot_be_self_served(): void
    {
        ['owner' => $owner, 'org' => $org] = $this->workspace();
        $this->agencyAddon()->update(['visibility' => 'custom']);

        $this->actingAs($owner)
            ->post(route('billing.addons.request'), ['addon' => 'agency_toolkit', 'cycle' => 'monthly'])
            ->assertSessionHasErrors('addon');

        $this->assertSame(0, app(CurrentOrganization::class)->runFor($org, fn () => Payment::query()->count()));
    }

    public function test_removing_an_addon_revokes_it_and_withdraws_any_unpaid_charge(): void
    {
        ['owner' => $owner, 'org' => $org] = $this->workspace();

        $this->actingAs($owner)->post(route('billing.addons.request'), [
            'addon' => 'agency_toolkit', 'cycle' => 'monthly',
        ]);

        $charge = app(CurrentOrganization::class)->runFor($org, fn () => Payment::query()->first());
        app(AddonPurchase::class)->confirm($charge);
        $this->assertTrue($org->refresh()->can('upwork_profiles'));

        $this->actingAs($owner)
            ->delete(route('billing.addons.remove', 'agency_toolkit'))
            ->assertSessionHasNoErrors();

        $org->refresh();
        $this->assertSame(0, $org->addons()->count());
        $this->assertFalse($org->can('upwork_profiles'));
    }

    // -----------------------------------------------------------------------
    // Access gating
    // -----------------------------------------------------------------------

    public function test_billing_mutations_require_the_billing_permission(): void
    {
        ['owner' => $owner, 'org' => $org] = $this->workspace();

        $member = app(CurrentOrganization::class)->runFor($org, fn () => User::create([
            'organization_id' => $org->id,
            'name' => 'Member',
            'email' => 'member@acme.test',
            'password' => bcrypt('super-secret-pw'),
            'role' => 'member',
        ]));

        // Reading stays open — an expired workspace is redirected here.
        $this->actingAs($member)->get(route('billing.index'))->assertOk();

        // Writing does not.
        $this->actingAs($member)
            ->post(route('billing.addons.request'), ['addon' => 'agency_toolkit', 'cycle' => 'monthly'])
            ->assertForbidden();
        $this->actingAs($member)->put(route('billing.cycle'), ['cycle' => 'yearly'])->assertForbidden();
        $this->actingAs($member)->put(route('billing.details'), ['city' => 'Swabi'])->assertForbidden();

        $this->assertSame(0, app(CurrentOrganization::class)->runFor($org, fn () => Payment::query()->count()));
    }

    public function test_the_page_tells_the_ui_whether_the_viewer_may_manage_billing(): void
    {
        ['owner' => $owner] = $this->workspace();

        $this->actingAs($owner)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', true)
                ->has('addons.0.quotes.monthly.amount_cents')
                ->has('previews.monthly.total_cents')
                ->has('invoiceCounts')
            );
    }

    // -----------------------------------------------------------------------
    // Cycle, invoices, details
    // -----------------------------------------------------------------------

    public function test_switching_the_billing_cycle_is_recorded_and_repriced(): void
    {
        ['owner' => $owner, 'org' => $org] = $this->workspace(extraUsers: 4);

        $this->actingAs($owner)
            ->put(route('billing.cycle'), ['cycle' => 'yearly'])
            ->assertSessionHasNoErrors();

        $org->refresh();
        $this->assertSame('yearly', $org->subscription->cycle());
        $this->assertSame(5 * 9000, app(BillingPricing::class)->invoicePreview($org, 'yearly')['total_cents']);
    }

    public function test_a_cycle_the_plan_has_no_price_for_is_refused(): void
    {
        ['owner' => $owner, 'org' => $org] = $this->workspace();

        // Enterprise publishes a monthly floor but no yearly list price.
        $enterprise = Plan::where('key', 'enterprise')->firstOrFail();
        app(CurrentOrganization::class)->runFor($org, fn () => $org->subscription->update(['plan_id' => $enterprise->id]));

        $this->actingAs($owner)
            ->put(route('billing.cycle'), ['cycle' => 'yearly'])
            ->assertSessionHasErrors('cycle');

        $this->assertSame('monthly', $org->refresh()->subscription->cycle());
    }

    public function test_an_unknown_cycle_is_rejected_outright(): void
    {
        ['owner' => $owner] = $this->workspace();

        $this->actingAs($owner)
            ->put(route('billing.cycle'), ['cycle' => 'weekly'])
            ->assertSessionHasErrors('cycle');
    }

    public function test_invoices_are_paginated_and_never_show_another_workspace(): void
    {
        ['owner' => $owner, 'org' => $org] = $this->workspace();

        app(CurrentOrganization::class)->runFor($org, function () {
            for ($i = 1; $i <= 15; $i++) {
                Payment::create([
                    'amount_cents' => 1000 + $i,
                    'currency' => 'USD',
                    'method' => 'bank_transfer',
                    'reference' => "MINE-$i",
                    'status' => Payment::STATUS_CONFIRMED,
                    'paid_at' => now()->subDays($i),
                ]);
            }
        });

        // A second workspace's payment must never appear.
        $otherOwner = app(TenantProvisioner::class)->provision('Rival', 'Boss', 'boss@rival.test', 'super-secret-pw')['owner'];
        app(CurrentOrganization::class)->runFor($otherOwner->organization_id, fn () => Payment::create([
            'amount_cents' => 999999,
            'currency' => 'USD',
            'method' => 'bank_transfer',
            'reference' => 'THEIRS-1',
            'status' => Payment::STATUS_CONFIRMED,
            'paid_at' => now(),
        ]));

        $this->actingAs($owner)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoiceCounts.all', 15)
                ->has('invoices.data', 12) // bounded page, not all 15
            )
            ->assertDontSee('THEIRS-1');
    }

    public function test_the_unpaid_filter_shows_only_outstanding_charges(): void
    {
        ['owner' => $owner, 'org' => $org] = $this->workspace();

        $this->actingAs($owner)->post(route('billing.addons.request'), [
            'addon' => 'agency_toolkit', 'cycle' => 'monthly',
        ]);

        app(CurrentOrganization::class)->runFor($org, fn () => Payment::create([
            'amount_cents' => 5000,
            'currency' => 'USD',
            'method' => 'bank_transfer',
            'reference' => 'PAID-1',
            'status' => Payment::STATUS_CONFIRMED,
            'paid_at' => now(),
        ]));

        $this->actingAs($owner)
            ->get(route('billing.index', ['invoice_status' => 'unpaid']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoiceCounts.paid', 1)
                ->where('invoiceCounts.unpaid', 1)
                ->has('invoices.data', 1)
                ->where('invoices.data.0.status', Payment::STATUS_PENDING)
            );
    }

    public function test_billing_details_are_saved_against_the_workspace(): void
    {
        ['owner' => $owner, 'org' => $org] = $this->workspace();

        $this->actingAs($owner)->put(route('billing.details'), [
            'billing_name' => 'Acme Pvt Ltd',
            'billing_email' => 'accounts@acme.test',
            'tax_id' => 'NTN-1234567-8',
            'address_line1' => '2nd Floor',
            'city' => 'Swabi',
            'country' => 'pk',
        ])->assertSessionHasNoErrors();

        $profile = app(CurrentOrganization::class)->runFor($org, fn () => BillingProfile::query()->first());

        $this->assertSame('Acme Pvt Ltd', $profile->billing_name);
        $this->assertSame('PK', $profile->country); // normalised server-side
        $this->assertSame($org->id, $profile->organization_id);

        // Saving again updates the same row rather than creating a second.
        $this->actingAs($owner)->put(route('billing.details'), ['billing_name' => 'Acme 2']);
        $this->assertSame(1, app(CurrentOrganization::class)->runFor($org, fn () => BillingProfile::query()->count()));
    }
}
