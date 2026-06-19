<?php

namespace App\Http\Controllers;

use App\Billing\PaymentProvider;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
            // Per-tenant control panel: the registry (labels/kinds/groups), what
            // the plan grants by default, this tenant's saved overrides, and the
            // effective result — so the platform can grant/limit a single tenant.
            'entitlements' => [
                'registry' => Entitlements::REGISTRY,
                'plan_defaults' => collect(Entitlements::keys())
                    ->mapWithKeys(fn ($k) => [$k => $sub?->plan?->entitlement($k) ?? Entitlements::default($k)])
                    ->all(),
                'overrides' => $organization->entitlement_overrides ?? [],
                'effective' => $organization->entitlements(),
                'custom_price_cents' => $organization->custom_price_cents,
            ],
        ]);
    }

    /**
     * Save per-tenant control: entitlement overrides (grant/limit a single
     * customer), a negotiated custom price, and an optional plan switch. This is
     * how the platform stays in control — "contact us for a bit more at a lower
     * price" becomes one tenant's override, affecting nobody else.
     */
    public function updateTenant(Request $request, Organization $organization, CurrentOrganization $current)
    {
        $data = $request->validate([
            'overrides' => ['nullable', 'array'],
            'custom_price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'plan' => ['nullable', 'string', 'exists:plans,key'],
        ]);

        // Keep only real, known entitlement keys; drop "inherit" (null) entries
        // and coerce each to the right type so a tenant can't be handed garbage.
        $clean = [];
        foreach (($data['overrides'] ?? []) as $key => $value) {
            if (! in_array($key, Entitlements::keys(), true) || $value === null || $value === '') {
                continue;
            }
            $clean[$key] = Entitlements::isFlag($key) ? (bool) $value : max(0, (int) $value);
        }

        if (! empty($data['plan'])) {
            $plan = Plan::where('key', $data['plan'])->first();
            $current->runFor($organization, fn () => $organization->subscription?->update(['plan_id' => $plan->id]));
        }

        $organization->update([
            'entitlement_overrides' => $clean ?: null,
            'custom_price_cents' => $data['custom_price_cents'] ?? null,
        ]);

        $this->forgetTenantCaches($organization->id);

        return back()->with('success', "Updated controls for {$organization->name}.");
    }

    /** The global plan editor: the platform tunes each plan's caps/flags/price. */
    public function plans()
    {
        return Inertia::render('Platform/Plans', [
            'plans' => Plan::orderBy('sort')->get()->map(fn (Plan $p) => [
                'id' => $p->id,
                'key' => $p->key,
                'name' => $p->name,
                'price_cents' => $p->price_cents,
                'currency' => $p->currency,
                'max_users' => $p->max_users,
                'is_public' => $p->is_public,
                'entitlements' => collect(Entitlements::keys())
                    ->mapWithKeys(fn ($k) => [$k => $p->entitlement($k)])
                    ->all(),
            ]),
            'registry' => Entitlements::REGISTRY,
        ]);
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'is_public' => ['required', 'boolean'],
            'entitlements' => ['required', 'array'],
        ]);

        $clean = [];
        foreach (Entitlements::keys() as $key) {
            $value = $data['entitlements'][$key] ?? null;
            if (Entitlements::isFlag($key)) {
                $clean[$key] = (bool) $value;
            } else {
                // Blank = unlimited for max_users; otherwise a non-negative int.
                $clean[$key] = ($value === null || $value === '') ? ($key === 'max_users' ? null : 0) : max(0, (int) $value);
            }
        }

        $plan->update([
            'name' => $data['name'],
            'price_cents' => $data['price_cents'],
            'is_public' => $data['is_public'],
            'max_users' => $clean['max_users'] ?? null,
            'entitlements' => $clean,
        ]);

        // A plan change ripples to every tenant on it — clear their caches.
        Cache::flush();

        return back()->with('success', "{$plan->name} plan updated.");
    }

    private function forgetTenantCaches(int $orgId): void
    {
        Cache::forget('entitlements.shared.'.$orgId);
        Cache::forget('branding.shared.'.$orgId);
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

