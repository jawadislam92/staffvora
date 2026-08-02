<?php

namespace App\Http\Controllers;

use App\Models\Addon;
use App\Models\BillingProfile;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AddonPurchase;
use App\Services\BillingPricing;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The CUSTOMER BILLING HUB — four tabs:
 *
 *   1. Plan & add-ons        current plan, seats, plan catalogue, and the
 *                            add-on cards (per-seat price monthly/yearly, what
 *                            each unlocks, Add / Remove).
 *   2. Invoices              the billing history, paginated in SQL, filterable
 *                            All / Paid / Unpaid.
 *   3. Subscription settings monthly ⇄ yearly with a LIVE INVOICE PREVIEW of
 *                            what the next charge becomes.
 *   4. Billing details       the company's bill-to name, tax id and address.
 *
 * ─── MONEY IS SERVER-SIDE, ALWAYS ────────────────────────────────────────────
 * No action here reads a price, an amount or a seat count from the request.
 * The only things a customer may send are an add-on KEY, a CYCLE and their own
 * address fields; every figure is re-derived by `App\Services\BillingPricing`
 * from the plan/add-on rows and a live seat count. Both cycles' previews and
 * every add-on quote are computed here and shipped as props, so the "live"
 * preview needs zero client-side arithmetic — the browser only picks which
 * server-derived number to show.
 *
 * ─── NOTHING IS GRANTED WITHOUT PAYMENT ──────────────────────────────────────
 * There is NO payment gateway wired in this codebase. Adding an add-on writes a
 * PENDING billing line (money owed) and grants nothing; an operator confirms it
 * when the transfer arrives, and only then is the `organization_addon` pivot
 * written — so the entitlement engine picks it up through the ONE existing
 * path, with no new gate. See `App\Services\AddonPurchase`.
 *
 * ─── ACCESS ──────────────────────────────────────────────────────────────────
 * VIEWING is open to any authenticated tenant user on purpose: an expired or
 * suspended workspace is redirected here by `OrganizationAccess`, and gating
 * the landing page would trap a non-manager in a redirect loop. Every MUTATION
 * is gated by `permission:billing.manage` at the route, and the page is
 * rendered read-only when the viewer lacks it.
 */
class BillingController extends Controller
{
    /** Invoice rows per page. The list must stay bounded at years of history. */
    private const INVOICES_PER_PAGE = 12;

    public function __construct(
        private BillingPricing $pricing,
        private AddonPurchase $purchases,
        private CurrentOrganization $current,
    ) {}

    // -----------------------------------------------------------------------
    // The hub
    // -----------------------------------------------------------------------

    public function index(Request $request)
    {
        $user = $request->user();
        $org = $user->organization;

        $filters = $request->validate([
            'tab' => ['nullable', 'string', Rule::in(['plan', 'invoices', 'settings', 'details'])],
            'invoice_status' => ['nullable', 'string', Rule::in(['all', 'paid', 'unpaid'])],
        ]);

        $invoiceStatus = $filters['invoice_status'] ?? 'all';

        if (! $org) {
            // Fail readable rather than fatal: a user with no workspace is
            // already blocked everywhere else (see OrganizationAccess).
            return Inertia::render('Billing/Index', [
                'subscription' => null,
                'canManage' => false,
                'seatsUsed' => 0,
                'seatLimit' => null,
                'plans' => [],
                'addons' => [],
                'invoices' => ['data' => [], 'links' => []],
                'invoiceStatus' => $invoiceStatus,
                'invoiceCounts' => ['all' => 0, 'paid' => 0, 'unpaid' => 0],
                'previews' => null,
                'billingDetails' => null,
                'tab' => $filters['tab'] ?? 'plan',
                'bankDetails' => config('billing.bank_transfer_instructions'),
                'supportEmail' => config('billing.support_email') ?: config('brand.support_email'),
                'cardCheckout' => (bool) config('billing.gateway'),
            ]);
        }

        $sub = $org->subscription;
        $seats = $this->pricing->billableSeats($org);

        return Inertia::render('Billing/Index', [
            'canManage' => (bool) $user->hasPermission('billing.manage'),
            'tab' => $filters['tab'] ?? 'plan',

            'subscription' => $sub ? [
                'status' => $sub->status,
                'plan_key' => $sub->plan?->key,
                'plan_name' => $sub->plan?->name,
                'on_trial' => $sub->onTrial(),
                'usable' => $sub->isUsable(),
                'cycle' => $sub->cycle(),
                'trial_ends_at' => $sub->trial_ends_at?->toDateString(),
                'current_period_end' => $sub->current_period_end?->toDateString(),
                // The price this org actually pays for its CURRENT cycle — a
                // negotiated custom price and any discount are already folded in.
                'price_cents' => $this->pricing->planUnitPriceCents($org, $sub->cycle()),
                'currency' => $sub->plan?->currency ?: 'USD',
                'has_custom_price' => $org->custom_price_cents !== null,
                'discount_percent' => (int) ($org->discount_percent ?? 0),
            ] : null,

            'seatsUsed' => $seats,
            'seatLimit' => $org->seatLimit(),

            'plans' => Plan::query()->visible()->ordered()->get()->map(fn (Plan $p) => [
                'key' => $p->key,
                'name' => $p->name,
                'price_cents' => $p->price_cents,
                'price_cents_yearly' => $p->price_cents_yearly,
                'currency' => $p->currency,
                'max_users' => $p->max_users,
                // Real entitlements (caps + flags) drive the card, not names.
                ...Entitlements::describe($p->entitlementMap()),
            ]),

            'addons' => $this->addonCards($org),

            // Tab 2 — paginated in SQL, never in PHP. Bound explicitly to this
            // workspace so the tenant scope is applied even if the request
            // context ever resolves differently.
            'invoices' => $this->current->runFor($org, fn () => $this->invoices($invoiceStatus)),
            'invoiceStatus' => $invoiceStatus,
            'invoiceCounts' => $this->current->runFor($org, fn () => $this->invoiceCounts()),

            // Tab 3 — both cycles derived server-side so the toggle is instant
            // and the browser never computes a price.
            'previews' => $this->pricing->invoicePreviews($org),

            // Tab 4
            'billingDetails' => $this->billingDetails($org),

            'bankDetails' => config('billing.bank_transfer_instructions'),
            'supportEmail' => config('billing.support_email') ?: config('brand.support_email'),
            // Whether self-serve card checkout is wired (a gateway is configured).
            'cardCheckout' => (bool) config('billing.gateway'),
        ]);
    }

    // -----------------------------------------------------------------------
    // Tab 1 — add-on cards
    // -----------------------------------------------------------------------

    /**
     * Every add-on this workspace may see, with BOTH cycles priced and its
     * current state. The state is the honest one:
     *
     *   active     the pivot is written — the entitlements are live
     *   pending    a charge is recorded and owed; nothing is granted yet
     *   available  not held
     *
     * @return array<int, array<string, mixed>>
     */
    private function addonCards(Organization $org): array
    {
        // Bounded: the visible catalogue plus whatever this org already holds
        // (so an operator-granted hidden add-on is still shown and removable).
        $catalogue = Addon::query()->visible()->ordered()->get();
        $held = $org->addons()->get();

        $addons = $catalogue->concat($held)->unique('id')->sortBy([['sort', 'asc'], ['id', 'asc']])->values();

        $activeIds = $org->activeAddons()->pluck('addons.id')->all();

        // One indexed read for every outstanding add-on charge, rather than one
        // query per card.
        $pending = $this->current->runFor($org, fn () => Payment::query()
            ->where('kind', Payment::KIND_ADDON)
            ->pending()
            ->get(['id', 'addon_id', 'amount_cents', 'currency', 'billing_cycle', 'seats', 'reference', 'created_at'])
            ->keyBy('addon_id'));

        return $addons->map(function (Addon $addon) use ($org, $activeIds, $pending) {
            $monthly = $this->pricing->quoteAddon($org, $addon, Subscription::CYCLE_MONTHLY);
            $yearly = $this->pricing->quoteAddon($org, $addon, Subscription::CYCLE_YEARLY);
            $charge = $pending->get($addon->id);

            return [
                'key' => $addon->key,
                'name' => $addon->name,
                'description' => $addon->description,
                'currency' => $addon->currency ?: 'USD',
                'price_cents' => $addon->price_cents,
                'price_cents_yearly' => $addon->price_cents_yearly,
                // What it unlocks, read off the live feature catalogue so an
                // owner-added feature appears here with no code change.
                'unlocks' => $this->unlockLabels($addon),
                'state' => in_array($addon->id, $activeIds, true)
                    ? 'active'
                    : ($charge ? 'pending' : 'available'),
                'quotes' => ['monthly' => $monthly, 'yearly' => $yearly],
                'pending_charge' => $charge ? [
                    'amount_cents' => (int) $charge->amount_cents,
                    'currency' => $charge->currency,
                    'cycle' => $charge->billing_cycle,
                    'seats' => (int) $charge->seats,
                    'reference' => $charge->reference,
                    'requested_at' => $charge->created_at?->toDateString(),
                ] : null,
            ];
        })->values()->all();
    }

    /** Human labels for the entitlements an add-on grants (flags on, caps raised). */
    private function unlockLabels(Addon $addon): array
    {
        $labels = [];

        foreach ((array) $addon->entitlements as $key => $value) {
            if ($value === false || $value === 0 || $value === null) {
                continue;
            }
            $entry = Entitlements::entry($key);
            $labels[] = $entry['label'] ?? $key;
        }

        return $labels;
    }

    // -----------------------------------------------------------------------
    // Tab 2 — invoices
    // -----------------------------------------------------------------------

    /** The billing history, paginated and filtered in SQL. */
    private function invoices(string $status)
    {
        $query = Payment::query()
            ->with('addon:id,key,name')
            ->orderByDesc('id');

        if ($status === 'paid') {
            $query->confirmed();
        } elseif ($status === 'unpaid') {
            $query->pending();
        }

        return $query->paginate(self::INVOICES_PER_PAGE)
            ->withQueryString()
            ->through(fn (Payment $p) => [
                'id' => $p->id,
                'kind' => $p->kind,
                'status' => $p->status,
                'label' => $p->description
                    ?: ($p->addon?->name ? $p->addon->name : 'Subscription'),
                'amount_cents' => (int) $p->amount_cents,
                'currency' => $p->currency,
                'method' => $p->method,
                'reference' => $p->reference,
                'seats' => $p->seats,
                'unit_price_cents' => $p->unit_price_cents,
                'billing_cycle' => $p->billing_cycle,
                'paid_at' => $p->paid_at?->toDateString(),
                'issued_at' => $p->created_at?->toDateString(),
                'period_start' => $p->period_start?->toDateString(),
                'period_end' => $p->period_end?->toDateString(),
            ]);
    }

    /** Row counts per filter — one grouped aggregate, not three queries. */
    private function invoiceCounts(): array
    {
        $byStatus = Payment::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'all' => (int) $byStatus->sum(),
            'paid' => (int) $byStatus->get(Payment::STATUS_CONFIRMED, 0),
            'unpaid' => (int) $byStatus->get(Payment::STATUS_PENDING, 0),
        ];
    }

    // -----------------------------------------------------------------------
    // Tab 4 — billing details
    // -----------------------------------------------------------------------

    private function billingDetails(Organization $org): array
    {
        $profile = $this->current->runFor($org, fn () => BillingProfile::query()->first());

        return [
            'billing_name' => $profile?->billing_name ?? $org->name,
            'billing_email' => $profile?->billing_email,
            'tax_id' => $profile?->tax_id,
            'address_line1' => $profile?->address_line1,
            'address_line2' => $profile?->address_line2,
            'city' => $profile?->city,
            'state' => $profile?->state,
            'postal_code' => $profile?->postal_code,
            'country' => $profile?->country,
            'notes' => $profile?->notes,
        ];
    }

    // -----------------------------------------------------------------------
    // Mutations (all route-gated by permission:billing.manage)
    // -----------------------------------------------------------------------

    /**
     * Card-checkout entry point. We NEVER collect or store card data ourselves —
     * card details go straight to the gateway's PCI-DSS-compliant hosted checkout.
     * Until a gateway is connected this is a no-op that points to the manual path,
     * so no card capture can happen here by design.
     */
    public function checkout(Request $request)
    {
        $request->validate(['plan' => ['required', 'string', 'exists:plans,key']]);

        if (! config('billing.gateway')) {
            return back()->with('info', 'Online card payments are coming soon. For now, pick a plan by bank transfer or contact us and we’ll set you up.');
        }

        // When a gateway is connected, build its hosted-checkout session here and
        // redirect — e.g. return Inertia::location($provider->checkoutUrl($plan)).
        return back()->with('info', 'Redirecting to secure checkout…');
    }

    /**
     * Request an add-on for this workspace.
     *
     * The request carries an add-on KEY and a CYCLE — nothing else. Any
     * `amount_cents` / `seats` / `price` a client invents is ignored outright:
     * the charge is derived from the add-on row and a live seat count.
     */
    public function requestAddon(Request $request, AddonPurchase $purchases)
    {
        $data = $request->validate([
            'addon' => ['required', 'string', 'max:64'],
            'cycle' => ['required', 'string', Rule::in(Subscription::CYCLES)],
        ]);

        $org = $request->user()->organization;
        abort_unless($org !== null, 403);

        // Only a PUBLIC, unarchived add-on can be self-served. A hidden/custom/
        // legacy one is operator-assigned by definition, so buying it by key
        // must not be possible.
        $addon = Addon::query()->visible()->where('key', $data['addon'])->first();

        if (! $addon) {
            throw ValidationException::withMessages([
                'addon' => 'That add-on is not available for your workspace.',
            ]);
        }

        if ($org->activeAddons()->where('addons.id', $addon->id)->exists()) {
            return back()->with('info', $addon->name.' is already active on your workspace.');
        }

        if ($purchases->pendingFor($org, $addon)) {
            return back()->with('info', 'We already have a request for '.$addon->name.' waiting to be confirmed.');
        }

        $quote = $this->pricing->quoteAddon($org, $addon, $data['cycle']);

        if (! $quote['priced']) {
            throw ValidationException::withMessages([
                'cycle' => $addon->name.' has no '.$data['cycle'].' list price. Contact us and we’ll set it up.',
            ]);
        }

        $payment = $purchases->request($org, $addon, $data['cycle'], $request->user());

        return back()->with(
            'success',
            sprintf(
                '%s requested. We’ve recorded %s for %d seat%s (reference %s). It switches on as soon as we confirm your payment — nothing is charged automatically.',
                $addon->name,
                $this->money((int) $payment->amount_cents, $payment->currency),
                (int) $payment->seats,
                (int) $payment->seats === 1 ? '' : 's',
                $payment->reference,
            ),
        );
    }

    /**
     * Remove an add-on — or withdraw an unpaid request for one.
     *
     * Removal only ever REDUCES access, so it takes effect immediately: the
     * pivot is detached through the existing path, which invalidates the
     * resolved entitlement caches.
     */
    public function removeAddon(Request $request, Addon $addon, AddonPurchase $purchases)
    {
        $org = $request->user()->organization;
        abort_unless($org !== null, 403);

        if ($pending = $purchases->pendingFor($org, $addon)) {
            $purchases->cancel($pending);
        }

        $org->revokeAddon($addon);

        return back()->with('success', $addon->name.' removed from your workspace.');
    }

    /**
     * Switch the billing cycle (monthly ⇄ yearly).
     *
     * Only the CYCLE comes from the request; the price it implies is derived.
     * A cycle the workspace's plan has no list price for is refused rather than
     * silently priced at zero.
     */
    public function updateCycle(Request $request)
    {
        $data = $request->validate([
            'cycle' => ['required', 'string', Rule::in(Subscription::CYCLES)],
        ]);

        $org = $request->user()->organization;
        abort_unless($org !== null, 403);

        $sub = $org->subscription;

        if (! $sub) {
            return back()->with('info', 'There is no subscription on this workspace yet.');
        }

        if ($this->pricing->planUnitPriceCents($org, $data['cycle']) === null) {
            throw ValidationException::withMessages([
                'cycle' => 'Your plan has no '.$data['cycle'].' list price. Contact us and we’ll quote it.',
            ]);
        }

        if ($sub->cycle() === $data['cycle']) {
            return back();
        }

        $this->current->runFor($org, fn () => $sub->update(['billing_cycle' => $data['cycle']]));

        $preview = $this->pricing->invoicePreview($org->refresh(), $data['cycle']);

        return back()->with('success', sprintf(
            'Billing cycle set to %s. Your next invoice will be %s for %d seat%s%s.',
            $data['cycle'],
            $preview['total_cents'] === null ? 'quoted separately' : $this->money((int) $preview['total_cents'], $preview['currency']),
            $preview['seats'],
            $preview['seats'] === 1 ? '' : 's',
            $preview['renews_at'] ? ' on '.$preview['renews_at'] : '',
        ));
    }

    /** Save the company's bill-to details (presentation only — gates nothing). */
    public function updateDetails(Request $request)
    {
        $data = $request->validate([
            'billing_name' => ['nullable', 'string', 'max:255'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $org = $request->user()->organization;
        abort_unless($org !== null, 403);

        if (! empty($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }

        $this->current->runFor($org, function () use ($data) {
            $profile = BillingProfile::query()->first() ?? new BillingProfile;
            $profile->fill($data)->save();
        });

        return back()->with('success', 'Billing details saved.');
    }

    /** Format cents for a flash message. Display only — never used in maths. */
    private function money(int $cents, ?string $currency): string
    {
        return ($currency ?: 'USD').' '.number_format($cents / 100, 2);
    }
}
