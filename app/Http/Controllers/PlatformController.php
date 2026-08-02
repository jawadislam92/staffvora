<?php

namespace App\Http\Controllers;

use App\Billing\PaymentProvider;
use App\Http\Controllers\Platform\AuditController;
use App\Models\Addon;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

/**
 * The platform (landlord) console: the SaaS operator manages every tenant. All
 * queries are cross-tenant — Organization is not org-scoped, and the relations
 * used here bypass the org scope deliberately.
 *
 * ─── ROLE GATES (declared on the routes, listed here so they're auditable) ───
 *   any operator role  index, show, resume, impersonate
 *   admin or owner     suspend, setTrial, recordPayment, updateTenant, plans
 *
 * ─── AUDIT ───────────────────────────────────────────────────────────────────
 * EVERY mutating action below writes a `platform_audit_logs` row — including
 * impersonation, which used to leave no trace at all even though a support
 * operator can sign in as any customer's super-admin. The tenant page renders
 * that history, paginated, on its own tab.
 *
 * ─── ACCESS LOGIC ────────────────────────────────────────────────────────────
 * Nothing in this controller decides access. It writes DATA (plan, overrides,
 * add-on grants) through the single sanitiser in `App\Tenancy\Entitlements`,
 * and reads back through `Organization::entitlementsWithSources()` so the
 * "where did this value come from" panel is the very same resolution the gate
 * uses — not a second implementation of it.
 */
class PlatformController extends Controller
{
    /** Tenants per page. The list must stay bounded at 500+ organizations. */
    private const TENANTS_PER_PAGE = 25;

    /** Audit rows per page on the tenant History tab. */
    private const HISTORY_PER_PAGE = 15;

    // -----------------------------------------------------------------------
    // Tenant list
    // -----------------------------------------------------------------------

    /**
     * The tenant directory. Filtering and pagination are done in SQL: the page
     * has to work at 500+ organizations, and resolving each row's seat limit
     * goes through the real entitlement resolver (one small indexed read per
     * row), so the row count per request must be bounded.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:20'],
            'plan' => ['nullable', 'string', 'max:40'],
        ]);

        $query = Organization::query()
            ->with(['subscription.plan'])
            ->withCount(['users' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)]);

        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('slug', 'like', $term));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['plan'])) {
            // A subquery on the indexed FK, not whereHas() — `subscription` is a
            // latestOfMany relation and is tenant-scoped, neither of which
            // belongs in a cross-tenant list filter.
            $planId = Plan::where('key', $filters['plan'])->value('id');
            $query->whereIn(
                'id',
                DB::table('subscriptions')->where('plan_id', $planId ?: 0)->select('organization_id')
            );
        }

        $page = $query->orderByDesc('id')
            ->paginate(self::TENANTS_PER_PAGE)
            ->withQueryString();

        return Inertia::render('Platform/Index', [
            'organizations' => collect($page->items())->map(fn (Organization $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'status' => $o->status,
                'plan' => $o->subscription?->plan?->name,
                'users' => $o->users_count,
                'seat_limit' => $o->seatLimit(),
                'trial_ends_at' => $o->trial_ends_at?->toDateString(),
                'created_at' => $o->created_at?->toDateString(),
            ])->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
            'filters' => $filters,
            'planOptions' => Plan::ordered()->get(['key', 'name'])
                ->map(fn (Plan $p) => ['key' => $p->key, 'name' => $p->name])->values(),
            // Whole-estate counters, aggregated in SQL — never by loading rows.
            'stats' => $this->tenantStats(),
        ]);
    }

    /** @return array<string, int> */
    private function tenantStats(): array
    {
        $byStatus = Organization::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'tenants' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus[Organization::STATUS_ACTIVE] ?? 0),
            'trialing' => (int) ($byStatus[Organization::STATUS_TRIAL] ?? 0),
            'suspended' => (int) ($byStatus[Organization::STATUS_SUSPENDED] ?? 0),
        ];
    }

    // -----------------------------------------------------------------------
    // Tenant detail
    // -----------------------------------------------------------------------

    public function show(Request $request, Organization $organization)
    {
        $sub = $organization->subscription;
        $plan = $organization->activePlan();
        $activeAddons = $organization->activeAddons()->get();

        return Inertia::render('Platform/Show', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status,
                'created_at' => $organization->created_at?->toDateString(),
                'users' => $organization->users()->withoutGlobalScope(OrganizationScope::class)->count(),
                'owner' => $organization->owner?->only(['name', 'email']),
                'trial_ends_at' => $organization->trial_ends_at?->toDateString(),
                'discount_percent' => $organization->discount_percent,
                'base_price_cents' => $organization->priceCents(),
                'effective_price_cents' => $organization->effectivePriceCents(),
                'currency' => $plan?->currency ?? 'USD',
            ],
            'subscription' => $sub ? [
                'plan_key' => $sub->plan?->key,
                'plan_name' => $sub->plan?->name,
                'status' => $sub->status,
                'trial_ends_at' => $sub->trial_ends_at?->toDateString(),
                'current_period_end' => $sub->current_period_end?->toDateString(),
            ] : null,
            // Archived plans are hidden from the switcher, EXCEPT the one this
            // company is already on (so the select can render its current value).
            'plans' => Plan::ordered()->get()
                ->filter(fn (Plan $p) => ! $p->isArchived() || $p->id === $sub?->plan_id)
                ->map(fn (Plan $p) => [
                    'key' => $p->key,
                    'name' => $p->name,
                    'price_cents' => $p->price_cents,
                    'price_cents_yearly' => $p->price_cents_yearly,
                    'currency' => $p->currency,
                    'visibility' => $p->visibility,
                    'archived' => $p->isArchived(),
                ])->values(),
            'payments' => $organization->payments()
                ->orderByDesc('paid_at')
                ->limit(50)
                ->get()
                ->map(fn ($p) => [
                    'amount_cents' => $p->amount_cents,
                    'currency' => $p->currency,
                    'reference' => $p->reference,
                    'paid_at' => $p->paid_at?->toDateString(),
                    'period_end' => $p->period_end?->toDateString(),
                ]),
            'addons' => $this->addonPanel($organization, $activeAddons),
            'entitlements' => $this->accessPanel($organization, $plan, $activeAddons),
            'history' => $this->history($request, $organization),
        ]);
    }

    /**
     * The add-ons tab: the sellable catalogue plus what this company holds
     * (with the pivot's validity window, so a time-boxed trial is visible).
     *
     * @param  \Illuminate\Support\Collection<int, Addon>  $activeAddons
     */
    private function addonPanel(Organization $organization, $activeAddons): array
    {
        $held = $organization->addons()->get();
        $activeIds = $activeAddons->pluck('id')->all();

        return [
            'catalogue' => Addon::active()->ordered()->get()->map(fn (Addon $a) => [
                'id' => $a->id,
                'key' => $a->key,
                'name' => $a->name,
                'description' => $a->description,
                'price_cents' => $a->price_cents,
                'price_cents_yearly' => $a->price_cents_yearly,
                'currency' => $a->currency ?: 'USD',
                'entitlements' => is_array($a->entitlements) ? $a->entitlements : [],
            ])->values(),
            'attached' => $held->map(fn (Addon $a) => [
                'id' => $a->id,
                'key' => $a->key,
                'name' => $a->name,
                'currency' => $a->currency ?: 'USD',
                'price_cents' => $a->price_cents,
                'price_cents_yearly' => $a->price_cents_yearly,
                'archived' => $a->isArchived(),
                'starts_at' => $a->pivot->starts_at ? Carbon::parse($a->pivot->starts_at)->toDateString() : null,
                'ends_at' => $a->pivot->ends_at ? Carbon::parse($a->pivot->ends_at)->toDateString() : null,
                // "Attached but not currently contributing" — expired, not yet
                // started, or the add-on itself was retired.
                'in_force' => in_array($a->id, $activeIds, true),
                'entitlements' => is_array($a->entitlements) ? $a->entitlements : [],
            ])->values(),
        ];
    }

    /**
     * The access tab. Everything here comes from the ONE resolver:
     *
     *   effective[key] = ['value' => …, 'source' => override|addon|plan|default]
     *
     * plus a `layers` breakdown showing what each layer SAYS, so the operator
     * can see why a value is what it is without us re-deciding anything.
     *
     * @param  \Illuminate\Support\Collection<int, Addon>  $activeAddons
     */
    private function accessPanel(Organization $organization, ?Plan $plan, $activeAddons): array
    {
        $registry = Entitlements::registry();
        $storedOverrides = $organization->entitlement_overrides ?? [];

        $layers = [];
        $overrides = [];

        foreach ($registry as $key => $entry) {
            $overrides[$key] = Entitlements::describeOverride($storedOverrides[$key] ?? null);

            $fromAddons = [];
            foreach ($activeAddons as $addon) {
                $map = is_array($addon->entitlements) ? $addon->entitlements : [];
                // Mirrors the resolver: null / "" says nothing, so it is not shown.
                if (! array_key_exists($key, $map) || $map[$key] === null || $map[$key] === '') {
                    continue;
                }
                $fromAddons[] = ['name' => $addon->name, 'key' => $addon->key, 'value' => $map[$key]];
            }

            $layers[$key] = [
                'addons' => $fromAddons,
                'plan' => $plan?->entitlement($key),
                'has_plan' => $plan !== null,
                'default' => Entitlements::default($key),
            ];
        }

        return [
            'registry' => $registry,
            // What the plan alone would give — the "Inherit (…)" hint.
            // NOT `?? default`: a plan value of `null` MEANS unlimited, and
            // coalescing would quietly redisplay it as the deny default.
            'plan_defaults' => collect(array_keys($registry))
                ->mapWithKeys(fn ($k) => [$k => $plan ? $plan->entitlement($k) : Entitlements::default($k)])
                ->all(),
            'overrides' => $overrides,
            'layers' => $layers,
            'effective' => $organization->entitlementsWithSources(),
            'custom_price_cents' => $organization->custom_price_cents,
            'unlimited_token' => Entitlements::UNLIMITED,
        ];
    }

    /**
     * One page of this company's operator history. Uses its own page parameter
     * (`hpage`) so the History tab can be reloaded on its own with an Inertia
     * partial visit without disturbing anything else on the page.
     */
    private function history(Request $request, Organization $organization): array
    {
        $page = PlatformAuditLog::query()
            ->forOrganization($organization->id)
            ->latestFirst()
            ->paginate(self::HISTORY_PER_PAGE, ['*'], 'hpage')
            ->withQueryString();

        return [
            'data' => collect($page->items())->map(fn (PlatformAuditLog $log) => AuditController::row($log))->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ];
    }

    // -----------------------------------------------------------------------
    // Per-tenant control
    // -----------------------------------------------------------------------

    /**
     * Save per-tenant control: entitlement overrides (grant/limit/REVOKE for a
     * single customer, optionally with a reason and an expiry), a negotiated
     * custom price, and an optional plan switch.
     *
     * Each distinct kind of change is audited SEPARATELY (plan / access / price)
     * so the history reads like a story rather than one opaque blob.
     */
    public function updateTenant(Request $request, Organization $organization, CurrentOrganization $current)
    {
        $data = $request->validate([
            'overrides' => ['nullable', 'array', 'max:200'],
            'custom_price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'plan' => ['nullable', 'string', 'exists:plans,key'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $data['reason'] ?? null;

        // THE one sanitiser. It keeps a deliberate `false` (a revoke), accepts
        // the "unlimited" sentinel, carries {value, expires_at, reason}
        // envelopes, and drops blanks as "inherit" — a blank must never become
        // 0, which on a floor-kind key is the most permissive value there is.
        $clean = Entitlements::sanitizeOverrides($data['overrides'] ?? []);

        $beforeOverrides = $organization->entitlement_overrides ?? [];
        $beforePrice = [
            'custom_price_cents' => $organization->custom_price_cents,
            'discount_percent' => $organization->discount_percent,
        ];
        $beforePlan = $organization->activePlan()?->key;

        if (! empty($data['plan']) && $data['plan'] !== $beforePlan) {
            $plan = Plan::where('key', $data['plan'])->firstOrFail();

            $current->runFor($organization, fn () => $organization->subscription?->update(['plan_id' => $plan->id]));

            PlatformAuditLog::record(
                action: PlatformAuditLog::ACTION_TENANT_PLAN_CHANGED,
                organizationId: $organization->id,
                targetType: 'organization',
                targetId: $organization->id,
                before: ['plan' => $beforePlan],
                after: ['plan' => $plan->key],
                reason: $reason,
            );
        }

        $afterPrice = [
            'custom_price_cents' => $data['custom_price_cents'] ?? null,
            'discount_percent' => ($data['discount_percent'] ?? 0) ?: null,
        ];

        $organization->update([
            'entitlement_overrides' => $clean ?: null,
            'custom_price_cents' => $afterPrice['custom_price_cents'],
            'discount_percent' => $afterPrice['discount_percent'],
        ]);

        if ($clean !== $beforeOverrides && ! (empty($clean) && empty($beforeOverrides))) {
            PlatformAuditLog::record(
                action: PlatformAuditLog::ACTION_TENANT_OVERRIDES_CHANGED,
                organizationId: $organization->id,
                targetType: 'organization',
                targetId: $organization->id,
                before: ['entitlement_overrides' => $beforeOverrides],
                after: ['entitlement_overrides' => $clean],
                reason: $reason,
            );
        }

        if ($afterPrice !== $beforePrice) {
            PlatformAuditLog::record(
                action: PlatformAuditLog::ACTION_TENANT_PRICE_CHANGED,
                organizationId: $organization->id,
                targetType: 'organization',
                targetId: $organization->id,
                before: $beforePrice,
                after: $afterPrice,
                reason: $reason,
            );
        }

        $this->forgetTenantCaches($organization->id);

        return back()->with('success', "Updated controls for {$organization->name}.");
    }

    /**
     * Drop the caches derived from ONE company's access. The catalogue-wide
     * stamp is bumped by whoever edits a plan/feature/add-on; this is the
     * per-company half.
     */
    private function forgetTenantCaches(int $orgId): void
    {
        Entitlements::forgetForOrganization($orgId);
        Cache::forget('branding.shared.'.$orgId);
    }

    // -----------------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------------

    public function suspend(Request $request, Organization $organization)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $before = $organization->status;

        $organization->update(['status' => Organization::STATUS_SUSPENDED]);

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_TENANT_SUSPENDED,
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            before: ['status' => $before],
            after: ['status' => Organization::STATUS_SUSPENDED],
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "{$organization->name} suspended.");
    }

    public function resume(Request $request, Organization $organization)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $before = $organization->status;

        $organization->update(['status' => Organization::STATUS_ACTIVE]);

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_TENANT_RESUMED,
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            before: ['status' => $before],
            after: ['status' => Organization::STATUS_ACTIVE],
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "{$organization->name} resumed.");
    }

    /**
     * Trial controls: extend by N days, end the trial now, or set an exact end
     * date. Keeps the org and its subscription in step.
     */
    public function setTrial(Request $request, Organization $organization, CurrentOrganization $current)
    {
        $data = $request->validate([
            'action' => ['required', 'in:extend,end,set'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $before = [
            'trial_ends_at' => $organization->trial_ends_at?->toIso8601String(),
            'status' => $organization->status,
        ];

        if ($data['action'] === 'end') {
            $organization->update(['trial_ends_at' => now(), 'status' => Organization::STATUS_ACTIVE]);
            $current->runFor($organization, fn () => $organization->subscription?->update(['trial_ends_at' => now()]));

            $this->recordTrialChange($organization, $before, 'end', $data['reason'] ?? null);

            return back()->with('success', "Trial ended for {$organization->name}.");
        }

        $base = $organization->trial_ends_at && $organization->trial_ends_at->isFuture()
            ? $organization->trial_ends_at->copy()
            : Carbon::now();

        $end = $data['action'] === 'extend'
            ? $base->addDays((int) ($data['days'] ?? 14))
            : Carbon::parse($data['date'])->endOfDay();

        $organization->update(['trial_ends_at' => $end, 'status' => Organization::STATUS_TRIAL]);
        $current->runFor($organization, fn () => $organization->subscription?->update(['trial_ends_at' => $end]));

        $this->recordTrialChange($organization, $before, $data['action'], $data['reason'] ?? null);

        return back()->with('success', "{$organization->name}'s trial now ends {$end->toDateString()}.");
    }

    private function recordTrialChange(Organization $organization, array $before, string $action, ?string $reason): void
    {
        $fresh = $organization->fresh();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_TENANT_TRIAL_CHANGED,
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            before: $before,
            after: [
                'trial_ends_at' => $fresh?->trial_ends_at?->toIso8601String(),
                'status' => $fresh?->status,
                'via' => $action,
            ],
            reason: $reason,
        );
    }

    public function recordPayment(Request $request, Organization $organization, PaymentProvider $provider, CurrentOrganization $current)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'plan' => ['nullable', 'string', 'exists:plans,key'],
            'reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $beforePlan = $organization->activePlan()?->key;

        if (! empty($data['plan'])) {
            $plan = Plan::where('key', $data['plan'])->firstOrFail();
            $current->runFor($organization, fn () => $organization->subscription?->update(['plan_id' => $plan->id]));

            if ($plan->key !== $beforePlan) {
                PlatformAuditLog::record(
                    action: PlatformAuditLog::ACTION_TENANT_PLAN_CHANGED,
                    organizationId: $organization->id,
                    targetType: 'organization',
                    targetId: $organization->id,
                    before: ['plan' => $beforePlan],
                    after: ['plan' => $plan->key],
                    reason: $data['reason'] ?? null,
                );
            }
        }

        $existingEnd = $current->runFor($organization, fn () => $organization->subscription?->current_period_end);
        $start = $existingEnd && $existingEnd->isFuture() ? $existingEnd->copy() : Carbon::now();
        $end = $start->copy()->addMonths((int) $data['months']);
        $amountCents = (int) round(((float) $data['amount']) * 100);
        $currency = strtoupper($data['currency'] ?? 'USD');

        $provider->recordPayment(
            org: $organization,
            amountCents: $amountCents,
            currency: $currency,
            periodStart: $start,
            periodEnd: $end,
            reference: $data['reference'] ?? null,
            recordedBy: $request->user()?->id,
        );

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_TENANT_PAYMENT_RECORDED,
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            after: [
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'period_start' => $start->toIso8601String(),
                'period_end' => $end->toIso8601String(),
                'reference' => $data['reference'] ?? null,
            ],
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', 'Payment recorded and subscription extended.');
    }

    // -----------------------------------------------------------------------
    // Impersonation — the most sensitive thing the console can do
    // -----------------------------------------------------------------------

    /**
     * Start impersonation from the operator portal (platform guard, platform
     * host). We can't log into the customer `web` guard here — it lives on a
     * different host with its own session cookie — so we mint a 60-second
     * RELATIVE signed URL and redirect the browser to the customer host, where
     * enterImpersonation() establishes the web session.
     *
     * ⚠️ AUDIT: any operator role, Support included, can sign in as a customer's
     * super-admin and read that company's employee-monitoring data. Both halves
     * of the hand-off are recorded: the REQUEST here (which proves who asked,
     * from which IP, even if the link is never followed) and the ENTRY on the
     * customer host. The operator's id rides inside the signed URL — it is
     * covered by the signature, so it cannot be swapped for someone else's.
     */
    public function impersonate(Request $request, Organization $organization)
    {
        $target = $organization->owner
            ?? User::withoutOrganizationScope()->where('organization_id', $organization->id)->first();

        abort_unless($target, 404, 'This organization has no user to impersonate.');

        $operator = Auth::guard('platform')->user();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_TENANT_IMPERSONATED,
            organizationId: $organization->id,
            targetType: 'user',
            targetId: $target->id,
            after: [
                'stage' => 'requested',
                'target_user' => $target->only(['id', 'name', 'email']),
                'organization' => $organization->name,
            ],
        );

        $relative = URL::temporarySignedRoute(
            'impersonate.enter',
            now()->addSeconds(60),
            ['user' => $target->id, 'op' => $operator?->id],
            absolute: false,
        );

        // config('app.url') is the customer (apex) host; the signed path rides on it.
        // Inertia::location forces a full cross-host browser visit (a plain redirect
        // from an Inertia POST would be swallowed by the XHR, never navigating).
        return Inertia::location(rtrim(config('app.url'), '/').$relative);
    }

    /**
     * Land the impersonation hand-off on the customer host. Authorized purely by
     * the signature (the operator may have no web-guard session here). Logs the
     * target customer in under the `web` guard.
     *
     * The operator id comes from the SIGNED query string, so it is trustworthy
     * without a platform session being present on this host.
     */
    public function enterImpersonation(Request $request)
    {
        $target = User::withoutOrganizationScope()->find($request->integer('user'));
        abort_unless($target, 404);

        Auth::guard('web')->login($target);
        $request->session()->put('platform_impersonator', true);

        $operatorId = $request->integer('op') ?: null;

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_TENANT_IMPERSONATED,
            organizationId: $target->organization_id,
            targetType: 'user',
            targetId: $target->id,
            after: ['stage' => 'entered', 'target_user' => $target->only(['id', 'name', 'email'])],
            actor: $operatorId,
        );

        return redirect()->route('dashboard')->with('success', "Now viewing as {$target->name}.");
    }

    /**
     * Return from impersonation: end the customer web session and send the
     * operator back to the portal (the platform-host session is untouched).
     */
    public function stopImpersonating(Request $request)
    {
        $request->session()->forget(['platform_impersonator', 'impersonator_id']);
        Auth::guard('web')->logout();

        // Cross-host (customer -> platform): force a full browser visit so the
        // Inertia POST actually navigates back to the portal.
        return Inertia::location(route('platform.index'));
    }
}
