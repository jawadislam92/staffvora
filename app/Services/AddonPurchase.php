<?php

namespace App\Services;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ADD-ON PURCHASE PATH — the piece that was missing entirely.
 *
 * ─── WHY IT DOES NOT GRANT ANYTHING ──────────────────────────────────────────
 * NO PAYMENT GATEWAY IS WIRED. There is no Alfa/Stripe/Paddle driver, no
 * webhook, no config — nothing in this codebase can take money. So a customer
 * pressing "Add" must not receive paid features for free.
 *
 * What `request()` therefore does is exactly what a bank transfer does today:
 * it writes a PENDING billing line (`payments.status = 'pending'`) recording
 * what is owed, for how many seats, at which price, prorated to the current
 * period — and stops. The `organization_addon` pivot is NOT written, so the
 * entitlement engine grants nothing and the workspace's access is unchanged.
 *
 * An operator confirms the charge once the money arrives (`confirm()`), and
 * only then is the pivot written — through `Organization::grantAddon()`, which
 * is the existing path, so the entitlement engine picks the add-on up with NO
 * new gate and no second access-control path.
 *
 * ─── WHY THE PRICE IS NEVER PASSED IN ────────────────────────────────────────
 * Every figure comes from `BillingPricing`, which re-derives it from the add-on
 * row and a live seat count. This class accepts an Addon MODEL and a cycle
 * STRING — never an amount, never a seat count. See the tampering test.
 */
class AddonPurchase
{
    public function __construct(
        private BillingPricing $pricing,
        private CurrentOrganization $current,
    ) {}

    /**
     * Record a customer's request to add an add-on, as money OWED.
     *
     * Returns the pending Payment. Throws nothing on a duplicate — the caller
     * checks `pendingFor()` / `holds()` first and shows a friendly message,
     * because a double-click must never create two charges.
     */
    public function request(Organization $organization, Addon $addon, string $cycle, ?User $requestedBy = null): Payment
    {
        $quote = $this->pricing->quoteAddon($organization, $addon, $cycle);

        // A cycle with no list price cannot be self-served — the caller gates
        // on this, and this is the belt-and-braces stop.
        if (! $quote['priced']) {
            throw new \RuntimeException('This add-on has no list price for that billing cycle.');
        }

        return $this->current->runFor($organization, function () use ($organization, $addon, $quote, $requestedBy) {
            return DB::transaction(function () use ($organization, $addon, $quote, $requestedBy) {
                $subscription = $organization->subscription;

                return Payment::create([
                    'subscription_id' => $subscription?->id,
                    'kind' => Payment::KIND_ADDON,
                    'addon_id' => $addon->id,
                    'billing_cycle' => $quote['cycle'],
                    // Snapshotted so the invoice keeps saying what was charged
                    // even after the catalogue price or the team size changes.
                    'seats' => $quote['seats'],
                    'unit_price_cents' => $quote['unit_price_cents'],
                    'amount_cents' => $quote['amount_cents'],
                    'currency' => $quote['currency'],
                    'method' => 'bank_transfer',
                    'reference' => $this->reference(),
                    'description' => sprintf(
                        '%s — %d seat%s, %s (prorated %d/%d days)',
                        $addon->name,
                        $quote['seats'],
                        $quote['seats'] === 1 ? '' : 's',
                        $quote['cycle'],
                        $quote['days_remaining'],
                        $quote['cycle_days'],
                    ),
                    'status' => Payment::STATUS_PENDING,
                    'paid_at' => null,
                    'period_start' => Carbon::now(),
                    'period_end' => $quote['period_end'],
                    'requested_by' => $requestedBy?->id,
                ]);
            });
        });
    }

    /**
     * Confirm a pending add-on charge — money received. THIS is the only place
     * a purchased add-on becomes active: it marks the line paid and writes the
     * pivot through the existing `grantAddon()`, which invalidates the resolved
     * entitlement caches for us.
     *
     * Called by the operator (see `deferred`: the platform-side button and its
     * audit row are outside this task's file ownership) and, when a gateway
     * lands, by its webhook — same method, same effect.
     */
    public function confirm(Payment $payment, ?int $recordedBy = null): Payment
    {
        if (! $payment->isPending() || ! $payment->isAddonCharge() || ! $payment->addon_id) {
            return $payment;
        }

        $organization = Organization::query()->find($payment->organization_id);

        if (! $organization) {
            return $payment;
        }

        return $this->current->runFor($organization, function () use ($payment, $organization, $recordedBy) {
            return DB::transaction(function () use ($payment, $organization, $recordedBy) {
                $payment->forceFill([
                    'status' => Payment::STATUS_CONFIRMED,
                    'paid_at' => Carbon::now(),
                    'recorded_by' => $recordedBy,
                ])->save();

                // The existing grant path — the entitlement engine folds the
                // add-on's map in between the per-company override and the plan.
                // No new gate, no second source of truth.
                $organization->grantAddon($payment->addon_id);

                return $payment->refresh();
            });
        });
    }

    /** Withdraw an unpaid request. The row is kept (canceled), never deleted. */
    public function cancel(Payment $payment): Payment
    {
        if ($payment->isPending()) {
            $payment->forceFill(['status' => Payment::STATUS_CANCELED])->save();
        }

        return $payment;
    }

    /**
     * The workspace's outstanding request for an add-on, if any. Indexed read
     * on (organization_id, addon_id).
     */
    public function pendingFor(Organization $organization, Addon $addon): ?Payment
    {
        return $this->current->runFor(
            $organization,
            fn () => Payment::query()->forAddon($addon)->pending()->latest('id')->first(),
        );
    }

    /** A short, human-quotable reference for the invoice line. */
    private function reference(): string
    {
        return 'ADD-'.Carbon::now()->format('Ymd').'-'.strtoupper(Str::random(6));
    }
}
