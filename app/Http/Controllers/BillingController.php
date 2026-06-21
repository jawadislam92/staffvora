<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use App\Tenancy\Entitlements;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The organization's own billing view: current plan, trial/period status, seat
 * usage, the plan catalogue, recent payments, and how to pay (manual bank
 * transfer for now). No self-serve checkout yet — a platform admin records the
 * transfer via `billing:record-payment`.
 */
class BillingController extends Controller
{
    public function index()
    {
        $org = request()->user()->organization;
        $sub = $org?->subscription;

        return Inertia::render('Billing/Index', [
            'subscription' => $sub ? [
                'status' => $sub->status,
                'plan_key' => $sub->plan->key,
                'plan_name' => $sub->plan->name,
                'on_trial' => $sub->onTrial(),
                'usable' => $sub->isUsable(),
                'trial_ends_at' => $sub->trial_ends_at?->toDateString(),
                'current_period_end' => $sub->current_period_end?->toDateString(),
                // The price this org actually pays (a negotiated custom price wins).
                'price_cents' => $org?->priceCents(),
                'has_custom_price' => $org?->custom_price_cents !== null,
            ] : null,
            'seatsUsed' => User::count(),
            'seatLimit' => $org?->seatLimit(),
            'plans' => Plan::where('is_public', true)->orderBy('sort')->get()->map(fn (Plan $p) => [
                'key' => $p->key,
                'name' => $p->name,
                'price_cents' => $p->price_cents,
                'currency' => $p->currency,
                'max_users' => $p->max_users,
                // Real entitlements (caps + flags) drive the card, not feature names.
                ...Entitlements::describe($p->entitlementMap()),
            ]),
            'payments' => $org
                ? $org->payments()->orderByDesc('paid_at')->limit(12)->get()->map(fn ($p) => [
                    'amount_cents' => $p->amount_cents,
                    'currency' => $p->currency,
                    'method' => $p->method,
                    'reference' => $p->reference,
                    'paid_at' => $p->paid_at?->toDateString(),
                    'period_end' => $p->period_end?->toDateString(),
                ])
                : [],
            'bankDetails' => config('billing.bank_transfer_instructions'),
            'supportEmail' => config('billing.support_email'),
            // Whether self-serve card checkout is wired (a gateway is configured).
            'cardCheckout' => (bool) config('billing.gateway'),
        ]);
    }

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
}
