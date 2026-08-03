<?php

namespace App\Services;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * THE server-side money authority for the customer billing hub.
 *
 * ─── THE RULE THIS CLASS EXISTS TO ENFORCE ───────────────────────────────────
 * A price, a seat count and an amount are NEVER accepted from a request. Every
 * figure below is re-derived from the plan row, the add-on row and a live SQL
 * count of the workspace's seats. The controller passes an add-on KEY and a
 * CYCLE and nothing else; the numbers come from here. A tampered request that
 * posts `amount_cents` or `seats` changes nothing, because nothing reads them.
 *
 * ─── MONEY ───────────────────────────────────────────────────────────────────
 * Integer minor units (cents) throughout — no floats anywhere near a total.
 * Every currency is explicit and carried on each line; lines in a different
 * currency from the subscription are never silently summed (see `mixed_currency`).
 * A NULL price is meaningful and is NOT zero: it means "no list price for this
 * cycle — negotiated" (Enterprise's yearly price, for instance).
 *
 * ─── PRORATION ───────────────────────────────────────────────────────────────
 * An add-on aligns to the subscription's existing renewal date so a company
 * never ends up with two billing anniversaries. The customer therefore pays
 * only for the slice of the cycle they will actually get before that date:
 *
 *     full     = unit price for the chosen cycle × billable seats
 *     prorated = round(full × days_remaining / cycle_days)
 *
 * where `cycle_days` is the real length of one chosen cycle from today (28–31
 * for monthly, 365/366 for yearly) and `days_remaining` is capped at it — so a
 * monthly add-on on a yearly subscription is simply charged in full, never more.
 *
 * ─── DISCOUNTS ───────────────────────────────────────────────────────────────
 * `organizations.discount_percent` is a negotiated discount on the PLAN, which
 * is what `Organization::effectivePriceCents()` has always modelled. It is
 * deliberately NOT applied to add-ons: silently discounting a new revenue line
 * because of an old plan negotiation is a decision for the operator to make
 * explicitly (per-company add-on pricing is listed under `deferred`).
 *
 * ─── SCALE ───────────────────────────────────────────────────────────────────
 * Seats are one indexed `COUNT(*)`, MEMOISED per organization for the life of
 * this instance; add-on lines come from the already-loaded `activeAddons`
 * relation. Nothing here loops over users or payments.
 */
class BillingPricing
{
    /** Seconds in a day — proration is measured in whole days. */
    private const DAY = 86400;

    /**
     * Memoised seat counts, keyed by organization id.
     *
     * The billing hub asks for the seat count once per add-on quote (two per
     * card, one per cycle), twice more for the two invoice previews and once
     * directly — five identical `COUNT(*)` on `users` with the small add-on
     * catalogue, and ~13 with the full one. They cannot disagree inside one
     * request, so they are answered once.
     *
     * INSTANCE state, deliberately not static: the container hands out a fresh
     * BillingPricing per resolution, so the cache cannot outlive the request
     * that built it, cannot leak between tenants in a queue worker, and cannot
     * be shared with the mutation path (AddonPurchase resolves its own), which
     * must always price against a live count. Keyed by organization id so an
     * operator context that prices several workspaces stays correct.
     *
     * @var array<int, int>
     */
    private array $seatCache = [];

    // -----------------------------------------------------------------------
    // Inputs
    // -----------------------------------------------------------------------

    /**
     * The seats this workspace is billed for.
     *
     * WHAT COUNTS IS NOT DECIDED HERE. This asks {@see User::seatUsageFor()},
     * which is the same scope the PLAN LIMIT counts — because this class used
     * to run its own `COUNT(*) WHERE is_active` and that count included
     * sample-data teammates. The product tells the customer in two places that
     * demo teammates are free, and the plan-limit counter agreed, so clicking
     * "Load sample data" silently added three seats to the plan line and to
     * every per-seat add-on line on the invoice preview. Billing a customer for
     * something we called free is a refund, not a rounding error, so the two
     * counters now share ONE definition and SeatCountersAgreeTest fails if they
     * ever drift apart again.
     *
     * One indexed COUNT(*), explicitly unscoped and re-scoped to the target org
     * so it is correct whether it runs in a tenant request or an operator one,
     * and memoised per organization for the life of this instance (see
     * `$seatCache` — a page that quotes ten add-on cards must not issue ten
     * identical counts).
     *
     * Never below 1 — an account always has at least its owner, and a workspace
     * whose owner has somehow been deactivated must not price at zero. That
     * floor is the ONLY sanctioned difference from the plan-limit counter.
     */
    public function billableSeats(Organization $organization): int
    {
        $organizationId = (int) $organization->id;

        if (array_key_exists($organizationId, $this->seatCache)) {
            return $this->seatCache[$organizationId];
        }

        return $this->seatCache[$organizationId] = max(1, User::seatUsageFor($organizationId));
    }

    /**
     * Drop the memoised seat count(s).
     *
     * Only needed by a caller that CHANGES the seat count and then re-prices
     * with the same instance — activating a user and immediately quoting, say.
     * Nothing in the read path needs it; it exists so that "memoised" can never
     * quietly become "wrong".
     */
    public function forgetSeats(?Organization $organization = null): void
    {
        if ($organization === null) {
            $this->seatCache = [];

            return;
        }

        unset($this->seatCache[(int) $organization->id]);
    }

    /** Normalise anything into one of the two cycles the catalogue prices. */
    public function normalizeCycle(?string $cycle): string
    {
        return in_array($cycle, Subscription::CYCLES, true) ? $cycle : Subscription::CYCLE_MONTHLY;
    }

    // -----------------------------------------------------------------------
    // Unit prices (per seat)
    // -----------------------------------------------------------------------

    /**
     * The per-seat plan price this workspace pays for a cycle, after any
     * negotiated discount. NULL = no list price for that cycle (talk to us).
     *
     * A negotiated `custom_price_cents` replaces the MONTHLY list price only —
     * it is a monthly figure. We do NOT extrapolate it into a yearly number:
     * inventing "custom × 10" would quietly bill a customer something nobody
     * agreed to, so a custom-priced workspace has no self-serve yearly price.
     */
    public function planUnitPriceCents(Organization $organization, string $cycle): ?int
    {
        $plan = $organization->activePlan();

        if (! $plan) {
            return null;
        }

        $base = $this->normalizeCycle($cycle) === Subscription::CYCLE_YEARLY
            ? ($organization->custom_price_cents !== null ? null : $plan->price_cents_yearly)
            : ($organization->custom_price_cents ?? $plan->price_cents);

        return $this->applyDiscount($base, (int) ($organization->discount_percent ?? 0));
    }

    /**
     * The per-seat list price of an add-on for a cycle. NULL yearly price means
     * the add-on is monthly-only / negotiated — never free.
     */
    public function addonUnitPriceCents(Addon $addon, string $cycle): ?int
    {
        return $this->normalizeCycle($cycle) === Subscription::CYCLE_YEARLY
            ? ($addon->price_cents_yearly === null ? null : (int) $addon->price_cents_yearly)
            : (int) $addon->price_cents;
    }

    /** Percentage discount applied to a base, in integer cents. */
    private function applyDiscount(?int $baseCents, int $percent): ?int
    {
        if ($baseCents === null) {
            return null;
        }

        $percent = max(0, min(100, $percent));

        return $percent > 0
            ? (int) round($baseCents * (100 - $percent) / 100)
            : $baseCents;
    }

    // -----------------------------------------------------------------------
    // Proration
    // -----------------------------------------------------------------------

    /**
     * How much of one `$cycle` remains before the subscription's renewal date.
     *
     * @return array{cycle_days:int, days_remaining:int, period_end:?string}
     */
    public function remainder(?Subscription $subscription, string $cycle, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::instance($now) : Carbon::now();
        $cycle = $this->normalizeCycle($cycle);

        $cycleEnd = $cycle === Subscription::CYCLE_YEARLY
            ? $now->copy()->addYearNoOverflow()
            : $now->copy()->addMonthNoOverflow();

        $cycleDays = max(1, $this->wholeDaysBetween($now, $cycleEnd));

        $periodEnd = $subscription?->periodEndsAt();

        // No renewal date on file (a subscription that was never paid) ⇒ the
        // add-on simply runs a full cycle from today.
        $remaining = $periodEnd === null
            ? $cycleDays
            : $this->wholeDaysBetween($now, $periodEnd);

        // At least one day (nobody gets an add-on for free), never more than a
        // cycle (a monthly add-on on a yearly subscription is charged in full).
        $remaining = max(1, min($cycleDays, $remaining));

        return [
            'cycle_days' => $cycleDays,
            'days_remaining' => $remaining,
            'period_end' => $periodEnd?->toDateString(),
        ];
    }

    /** Whole days between two instants, rounded up, never negative. */
    private function wholeDaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $seconds = $to->getTimestamp() - $from->getTimestamp();

        return $seconds <= 0 ? 0 : (int) ceil($seconds / self::DAY);
    }

    // -----------------------------------------------------------------------
    // Quotes
    // -----------------------------------------------------------------------

    /**
     * What adding `$addon` costs this workspace right now, on `$cycle`.
     *
     * Everything is derived: seats from SQL, unit price from the add-on row,
     * proration from the subscription's own renewal date. `amount_cents` is
     * what would actually be charged today; `full_period_cents` is what it
     * costs per cycle from the next renewal on.
     *
     * @return array{
     *   addon_key:string, addon_name:string, cycle:string, currency:string,
     *   seats:int, unit_price_cents:?int, full_period_cents:?int,
     *   amount_cents:?int, days_remaining:int, cycle_days:int,
     *   period_end:?string, priced:bool
     * }
     */
    public function quoteAddon(Organization $organization, Addon $addon, string $cycle, ?CarbonInterface $now = null): array
    {
        $cycle = $this->normalizeCycle($cycle);
        $seats = $this->billableSeats($organization);
        $unit = $this->addonUnitPriceCents($addon, $cycle);
        $remainder = $this->remainder($organization->subscription, $cycle, $now);

        $full = $unit === null ? null : $unit * $seats;
        $amount = $full === null
            ? null
            : $this->prorate($full, $remainder['days_remaining'], $remainder['cycle_days']);

        return [
            'addon_key' => $addon->key,
            'addon_name' => $addon->name,
            'cycle' => $cycle,
            'currency' => $addon->currency ?: 'USD',
            'seats' => $seats,
            'unit_price_cents' => $unit,
            'full_period_cents' => $full,
            'amount_cents' => $amount,
            'days_remaining' => $remainder['days_remaining'],
            'cycle_days' => $remainder['cycle_days'],
            'period_end' => $remainder['period_end'],
            // false ⇒ no list price for this cycle; the UI must say "talk to us"
            // rather than render a zero.
            'priced' => $unit !== null,
        ];
    }

    /** full × remaining / cycle, in integer cents, clamped into [0, full]. */
    public function prorate(int $fullCents, int $daysRemaining, int $cycleDays): int
    {
        if ($cycleDays <= 0) {
            return $fullCents;
        }

        $amount = (int) round($fullCents * $daysRemaining / $cycleDays);

        return max(0, min($fullCents, $amount));
    }

    /**
     * The LIVE INVOICE PREVIEW: what the next invoice becomes on `$cycle`.
     *
     * The plan line plus one line per add-on currently in force, all at the
     * current seat count. This is a preview of the FULL next period — it is not
     * prorated, because the next invoice is not.
     *
     * @return array{
     *   cycle:string, currency:string, seats:int, renews_at:?string,
     *   lines:array<int, array{label:string, kind:string, unit_price_cents:?int, seats:int, amount_cents:?int, currency:string, priced:bool}>,
     *   total_cents:?int, mixed_currency:bool, priced:bool
     * }
     */
    public function invoicePreview(Organization $organization, string $cycle, ?CarbonInterface $now = null): array
    {
        $cycle = $this->normalizeCycle($cycle);
        $seats = $this->billableSeats($organization);
        $plan = $organization->activePlan();
        $currency = $plan?->currency ?: 'USD';

        $lines = [];

        $planUnit = $this->planUnitPriceCents($organization, $cycle);
        $lines[] = [
            'label' => $plan?->name ?? 'No plan',
            'kind' => 'plan',
            'unit_price_cents' => $planUnit,
            'seats' => $seats,
            'amount_cents' => $planUnit === null ? null : $planUnit * $seats,
            'currency' => $currency,
            'priced' => $planUnit !== null,
        ];

        foreach ($organization->activeAddons()->get() as $addon) {
            $unit = $this->addonUnitPriceCents($addon, $cycle);
            $lines[] = [
                'label' => $addon->name,
                'kind' => 'addon',
                'unit_price_cents' => $unit,
                'seats' => $seats,
                'amount_cents' => $unit === null ? null : $unit * $seats,
                'currency' => $addon->currency ?: 'USD',
                'priced' => $unit !== null,
            ];
        }

        // Never sum across currencies. If a line is priced in something other
        // than the subscription currency the total is withheld and flagged,
        // rather than quietly producing a wrong number.
        $mixed = collect($lines)->contains(fn (array $line) => $line['currency'] !== $currency);
        $allPriced = collect($lines)->every(fn (array $line) => $line['priced']);

        $total = ($mixed || ! $allPriced)
            ? null
            : (int) collect($lines)->sum(fn (array $line) => (int) $line['amount_cents']);

        return [
            'cycle' => $cycle,
            'currency' => $currency,
            'seats' => $seats,
            'renews_at' => $organization->subscription?->periodEndsAt()?->toDateString(),
            'lines' => $lines,
            'total_cents' => $total,
            'mixed_currency' => $mixed,
            'priced' => $allPriced && ! $mixed,
        ];
    }

    /**
     * Both cycles' previews side by side, so the settings tab can switch
     * monthly ⇄ yearly instantly with NO client-side price arithmetic and no
     * extra round trip.
     *
     * @return array{monthly:array<string,mixed>, yearly:array<string,mixed>, saving_cents:?int}
     */
    public function invoicePreviews(Organization $organization, ?CarbonInterface $now = null): array
    {
        $monthly = $this->invoicePreview($organization, Subscription::CYCLE_MONTHLY, $now);
        $yearly = $this->invoicePreview($organization, Subscription::CYCLE_YEARLY, $now);

        // What a year on the yearly price saves against twelve monthly ones.
        $saving = ($monthly['total_cents'] !== null && $yearly['total_cents'] !== null)
            ? ($monthly['total_cents'] * 12) - $yearly['total_cents']
            : null;

        return [
            'monthly' => $monthly,
            'yearly' => $yearly,
            'saving_cents' => $saving !== null && $saving > 0 ? $saving : null,
        ];
    }
}
