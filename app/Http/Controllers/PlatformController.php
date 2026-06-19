<?php

namespace App\Http\Controllers;

use App\Billing\PaymentProvider;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * The platform (landlord) console: the SaaS operator manages every tenant. All
 * queries are cross-tenant — Organization is not org-scoped, and the relations
 * used here bypass the org scope deliberately.
 */
class PlatformController extends Controller
{
    public function index()
    {
        $orgs = Organization::query()
            ->with(['subscription.plan'])
            ->withCount(['users' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Organization $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'status' => $o->status,
                'plan' => $o->subscription?->plan?->name,
                'users' => $o->users_count,
                'seat_limit' => $o->seatLimit(),
                'trial_ends_at' => $o->trial_ends_at?->toDateString(),
                'created_at' => $o->created_at?->toDateString(),
            ]);

        return Inertia::render('Platform/Index', [
            'organizations' => $orgs,
            'stats' => [
                'tenants' => $orgs->count(),
                'active' => $orgs->where('status', Organization::STATUS_ACTIVE)->count(),
                'trialing' => $orgs->where('status', Organization::STATUS_TRIAL)->count(),
                'suspended' => $orgs->where('status', Organization::STATUS_SUSPENDED)->count(),
            ],
        ]);
    }

    public function show(Organization $organization)
    {
        $sub = $organization->subscription;

        return Inertia::render('Platform/Show', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status,
                'created_at' => $organization->created_at?->toDateString(),
                'users' => $organization->users()->withoutGlobalScope(OrganizationScope::class)->count(),
                'owner' => $organization->owner?->only(['name', 'email']),
            ],
            'subscription' => $sub ? [
                'plan_key' => $sub->plan->key,
                'plan_name' => $sub->plan->name,
                'status' => $sub->status,
                'trial_ends_at' => $sub->trial_ends_at?->toDateString(),
                'current_period_end' => $sub->current_period_end?->toDateString(),
            ] : null,
            'plans' => Plan::orderBy('sort')->get(['key', 'name', 'price_cents', 'currency']),
            'payments' => $organization->payments()->orderByDesc('paid_at')->get()->map(fn ($p) => [
                'amount_cents' => $p->amount_cents,
                'currency' => $p->currency,
                'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toDateString(),
                'period_end' => $p->period_end?->toDateString(),
            ]),
        ]);
    }

    public function suspend(Organization $organization)
    {
        $organization->update(['status' => Organization::STATUS_SUSPENDED]);

        return back()->with('success', "{$organization->name} suspended.");
    }

    public function resume(Organization $organization)
    {
        $organization->update(['status' => Organization::STATUS_ACTIVE]);

        return back()->with('success', "{$organization->name} resumed.");
    }

    public function recordPayment(Request $request, Organization $organization, PaymentProvider $provider, CurrentOrganization $current)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'plan' => ['nullable', 'string', 'exists:plans,key'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['plan'])) {
            $plan = Plan::where('key', $data['plan'])->first();
            $current->runFor($organization, fn () => $organization->subscription?->update(['plan_id' => $plan->id]));
        }

        $existingEnd = $current->runFor($organization, fn () => $organization->subscription?->current_period_end);
        $start = $existingEnd && $existingEnd->isFuture() ? $existingEnd->copy() : Carbon::now();
        $end = $start->copy()->addMonths((int) $data['months']);

        $provider->recordPayment(
            org: $organization,
            amountCents: (int) round(((float) $data['amount']) * 100),
            currency: strtoupper($data['currency'] ?? 'USD'),
            periodStart: $start,
            periodEnd: $end,
            reference: $data['reference'] ?? null,
            recordedBy: $request->user()->id,
        );

        return back()->with('success', 'Payment recorded and subscription extended.');
    }

    public function impersonate(Request $request, Organization $organization)
    {
        $target = $organization->owner
            ?? User::withoutOrganizationScope()->where('organization_id', $organization->id)->first();

        abort_unless($target, 404, 'This organization has no user to impersonate.');

        $request->session()->put('impersonator_id', $request->user()->id);
        Auth::login($target);

        return redirect()->route('dashboard')->with('success', "Now viewing as {$target->name}.");
    }

    /**
     * Return to the platform admin account. Lives outside the platform gate
     * because the impersonated user is (correctly) not a platform admin.
     */
    public function stopImpersonating(Request $request)
    {
        $id = $request->session()->pull('impersonator_id');
        if ($id) {
            Auth::loginUsingId($id);
        }

        return redirect()->route('platform.index');
    }
}

