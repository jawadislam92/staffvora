<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
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
            ] : null,
            'seatsUsed' => User::count(),
            'seatLimit' => $org?->seatLimit(),
            'plans' => Plan::where('is_public', true)->orderBy('sort')->get()->map(fn (Plan $p) => [
                'key' => $p->key,
                'name' => $p->name,
                'price_cents' => $p->price_cents,
                'currency' => $p->currency,
                'max_users' => $p->max_users,
                'features' => $p->features,
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
        ]);
    }
}
