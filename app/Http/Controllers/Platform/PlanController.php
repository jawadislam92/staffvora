<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Tenancy\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * PLAN LIFECYCLE for the operator portal — create, rename, duplicate, archive,
 * restore, reorder, price (monthly AND yearly), visibility, default-trial.
 *
 * ROLE GATE: every action here is `platform.role:admin` (admin or owner).
 * Support may never touch pricing.
 *
 * ─── WHY THIS CONTROLLER OWNS NO ACCESS LOGIC ────────────────────────────────
 * A plan is DATA. This controller writes `plans.entitlements` through the ONE
 * sanitiser (`Entitlements::sanitizePlanValues`) and never decides anything
 * about access itself; `Organization::can()/entitlement()` remains the single
 * decision path. Nothing here — and nothing anywhere else — may branch on a
 * plan KEY: `is_default_trial` is what signup reads, `visibility` is what the
 * pricing page reads, and both are operator-editable columns.
 *
 * ─── CACHE ───────────────────────────────────────────────────────────────────
 * A plan edit ripples to every tenant on that plan, so each write ends with
 * `Entitlements::bump()`. That advances the catalogue stamp the shared
 * `entitlements` Inertia prop is keyed on, invalidating every company at once
 * WITHOUT enumerating them (and without the old `Cache::flush()`, which threw
 * away unrelated caches across the whole app).
 *
 * ─── AUDIT ───────────────────────────────────────────────────────────────────
 * Every mutation writes a `platform_audit_logs` row with before/after, so the
 * owner can always answer "who changed this and when".
 */
class PlanController extends Controller
{
    /** Hard ceiling on how many plans may exist, so the catalogue stays a catalogue. */
    private const MAX_PLANS = 40;

    // -----------------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------------

    /** The plan editor page: the whole catalogue, archived rows included. */
    public function index()
    {
        $subscriberCounts = $this->subscriberCounts();

        return Inertia::render('Platform/Plans', [
            'plans' => Plan::ordered()->get()
                ->map(fn (Plan $plan) => $this->payload($plan, $subscriberCounts))
                ->values(),
            'registry' => Entitlements::registry(),
            'visibilities' => Plan::VISIBILITIES,
        ]);
    }

    /**
     * How many subscriptions sit on each plan — ONE grouped query, never a
     * per-plan count in a loop. Read straight off the table because
     * Subscription is tenant-scoped and this is a cross-tenant landlord view.
     *
     * @return array<int, int> plan_id => count
     */
    private function subscriberCounts(): array
    {
        try {
            return DB::table('subscriptions')
                ->selectRaw('plan_id, COUNT(*) as aggregate')
                ->groupBy('plan_id')
                ->pluck('aggregate', 'plan_id')
                ->map(fn ($n) => (int) $n)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @param  array<int, int>  $subscriberCounts */
    private function payload(Plan $plan, array $subscriberCounts = []): array
    {
        return [
            'id' => $plan->id,
            'key' => $plan->key,
            'name' => $plan->name,
            'price_cents' => $plan->price_cents,
            'price_cents_yearly' => $plan->price_cents_yearly,
            'currency' => $plan->currency ?: 'USD',
            'trial_days' => $plan->trial_days,
            'visibility' => $plan->visibility ?: 'public',
            'is_public' => (bool) $plan->is_public,
            'is_default_trial' => (bool) $plan->is_default_trial,
            'archived_at' => $plan->archived_at?->toDateString(),
            'sort' => (int) $plan->sort,
            'subscribers' => $subscriberCounts[$plan->id] ?? 0,
            'entitlements' => collect(Entitlements::keys())
                ->mapWithKeys(fn ($key) => [$key => $plan->entitlement($key)])
                ->all(),
        ];
    }

    // -----------------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------------

    /** Create a plan. New plans start HIDDEN so nothing is published by accident. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'key' => ['nullable', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('plans', 'key')],
            'price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'price_cents_yearly' => ['nullable', 'integer', 'min:0', 'max:1200000000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'visibility' => ['nullable', Rule::in(Plan::VISIBILITIES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (Plan::count() >= self::MAX_PLANS) {
            throw ValidationException::withMessages([
                'name' => 'The plan catalogue is full. Archive a plan before adding another.',
            ]);
        }

        $visibility = $data['visibility'] ?? 'hidden';

        $plan = Plan::create([
            'key' => $data['key'] ?? $this->uniqueKey($data['name']),
            'name' => $data['name'],
            'price_cents' => $data['price_cents'] ?? 0,
            'price_cents_yearly' => $data['price_cents_yearly'] ?? null,
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            'trial_days' => $data['trial_days'] ?? null,
            'visibility' => $visibility,
            'is_public' => $visibility === 'public',
            'is_default_trial' => false,
            'archived_at' => null,
            'sort' => $this->nextSort(),
            'entitlements' => $this->denyMap(),
            'max_users' => Entitlements::default('max_users'),
        ]);

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_PLAN_CREATED,
            targetType: 'plan',
            targetId: $plan->id,
            after: $this->snapshot($plan),
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "Plan “{$plan->name}” created (hidden until you publish it).");
    }

    /**
     * Rename / re-price / re-scope a plan and rewrite its entitlement map.
     *
     * `max_users` is mirrored onto its own column because a lot of the app
     * reads `plans.max_users` directly; `entitlements.max_users` stays the
     * source of truth and the column follows it.
     */
    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'price_cents_yearly' => ['nullable', 'integer', 'min:0', 'max:1200000000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'visibility' => ['required', Rule::in(Plan::VISIBILITIES)],
            'is_default_trial' => ['nullable', 'boolean'],
            'entitlements' => ['required', 'array', 'max:200'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $this->snapshot($plan);

        // The ONE sanitiser: blank ceiling = unlimited, blank floor = inherit the
        // catalogue default (never 0, which would be the most permissive floor).
        $entitlements = Entitlements::sanitizePlanValues($data['entitlements']);

        $wantsDefaultTrial = (bool) ($data['is_default_trial'] ?? $plan->is_default_trial);

        if ($wantsDefaultTrial && $plan->isArchived()) {
            throw ValidationException::withMessages([
                'is_default_trial' => 'An archived plan cannot be the default trial plan.',
            ]);
        }

        DB::transaction(function () use ($plan, $data, $entitlements, $wantsDefaultTrial) {
            $plan->fill([
                'name' => $data['name'],
                'price_cents' => $data['price_cents'],
                'price_cents_yearly' => $data['price_cents_yearly'] ?? null,
                'currency' => strtoupper($data['currency'] ?? ($plan->currency ?: 'USD')),
                'trial_days' => $data['trial_days'] ?? null,
                'visibility' => $data['visibility'],
                // The legacy boolean is kept in step so nothing that still reads
                // it can disagree with `visibility`.
                'is_public' => $data['visibility'] === 'public',
                'entitlements' => $entitlements,
                'max_users' => $entitlements['max_users'] ?? null,
                'is_default_trial' => $wantsDefaultTrial,
            ])->save();

            if ($wantsDefaultTrial) {
                $this->demoteOtherTrialPlans($plan);
            }
        });

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_PLAN_UPDATED,
            targetType: 'plan',
            targetId: $plan->id,
            before: $before,
            after: $this->snapshot($plan->fresh()),
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "{$plan->name} plan updated.");
    }

    /** Copy a plan (entitlements and prices included) as a hidden draft. */
    public function duplicate(Request $request, Plan $plan)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        if (Plan::count() >= self::MAX_PLANS) {
            throw ValidationException::withMessages([
                'name' => 'The plan catalogue is full. Archive a plan before duplicating another.',
            ]);
        }

        $copy = Plan::create([
            'key' => $this->uniqueKey($plan->key.'_copy'),
            'name' => Str::limit($plan->name.' (copy)', 60, ''),
            'price_cents' => $plan->price_cents,
            'price_cents_yearly' => $plan->price_cents_yearly,
            'currency' => $plan->currency ?: 'USD',
            'trial_days' => $plan->trial_days,
            // Never inherit publication or the trial flag — a copy is a draft.
            'visibility' => 'hidden',
            'is_public' => false,
            'is_default_trial' => false,
            'archived_at' => null,
            'sort' => $this->nextSort(),
            'entitlements' => $plan->entitlements ?: $this->denyMap(),
            'max_users' => $plan->max_users,
            'features' => $plan->features,
        ]);

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_PLAN_CREATED,
            targetType: 'plan',
            targetId: $copy->id,
            before: ['duplicated_from' => ['id' => $plan->id, 'key' => $plan->key, 'name' => $plan->name]],
            after: $this->snapshot($copy),
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "Duplicated {$plan->name} as “{$copy->name}”.");
    }

    /**
     * Retire a plan. Existing subscriptions keep resolving through it — the row
     * stays — but it disappears from every picker and the pricing page.
     */
    public function archive(Request $request, Plan $plan)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        if ($plan->isArchived()) {
            return back()->with('info', "{$plan->name} is already archived.");
        }

        if ($plan->is_default_trial) {
            throw ValidationException::withMessages([
                'plan' => 'This is the default trial plan. Make another plan the default first.',
            ]);
        }

        if (Plan::notArchived()->count() <= 1) {
            throw ValidationException::withMessages([
                'plan' => 'You cannot archive the last active plan — new signups would have nowhere to land.',
            ]);
        }

        $before = $this->snapshot($plan);

        $plan->forceFill(['archived_at' => now(), 'is_public' => false])->save();

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_PLAN_ARCHIVED,
            targetType: 'plan',
            targetId: $plan->id,
            before: $before,
            after: $this->snapshot($plan->fresh()),
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "{$plan->name} archived. Existing subscribers keep it.");
    }

    /** Bring a retired plan back. It returns HIDDEN, never straight to public. */
    public function restore(Request $request, Plan $plan)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $before = $this->snapshot($plan);

        $plan->forceFill([
            'archived_at' => null,
            'visibility' => $plan->visibility === 'public' ? 'hidden' : ($plan->visibility ?: 'hidden'),
            'is_public' => false,
        ])->save();

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_PLAN_UPDATED,
            targetType: 'plan',
            targetId: $plan->id,
            before: $before,
            after: $this->snapshot($plan->fresh()),
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "{$plan->name} restored (hidden — publish it when you're ready).");
    }

    /**
     * Write the display order. The payload is a list of plan ids in the order
     * the operator dragged them into; ids that are not real plans are ignored
     * rather than trusted.
     */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'max:'.self::MAX_PLANS],
            'order.*' => ['integer'],
        ]);

        // Never trust request ids: intersect with what actually exists.
        $known = Plan::whereIn('id', $data['order'])->pluck('name', 'id');

        $ordered = collect($data['order'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $known->has($id))
            ->unique()
            ->values();

        if ($ordered->isEmpty()) {
            return back()->with('info', 'Nothing to reorder.');
        }

        $before = Plan::ordered()->pluck('key', 'id')->all();

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $position => $id) {
                Plan::whereKey($id)->update(['sort' => ($position + 1) * 10]);
            }
        });

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_PLAN_REORDERED,
            targetType: 'plan',
            before: ['order' => $before],
            after: ['order' => Plan::ordered()->pluck('key', 'id')->all()],
        );

        return back()->with('success', 'Plan order saved.');
    }

    /**
     * Make this the plan every new signup lands on. Replaces the old hardcoded
     * `where('key', 'pro')` lookup — exactly one plan may carry the flag.
     */
    public function makeDefaultTrial(Request $request, Plan $plan)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        if ($plan->isArchived()) {
            throw ValidationException::withMessages([
                'plan' => 'Restore this plan before making it the default trial plan.',
            ]);
        }

        $before = Plan::where('is_default_trial', true)->pluck('key', 'id')->all();

        DB::transaction(function () use ($plan) {
            $plan->forceFill(['is_default_trial' => true])->save();
            $this->demoteOtherTrialPlans($plan);
        });

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_PLAN_UPDATED,
            targetType: 'plan',
            targetId: $plan->id,
            before: ['is_default_trial' => $before],
            after: ['is_default_trial' => [$plan->id => $plan->key]],
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "New signups now start on {$plan->name}.");
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * A brand-new plan's entitlement map: the catalogue's DENY value for every
     * key — false for flags, the strict default for a ceiling or a floor.
     *
     * Deliberately NOT `Entitlements::sanitizePlanValues([])`: in the plan
     * EDITOR a blank ceiling means "unlimited", which is the right reading of an
     * operator clearing a box, but it is the exact wrong reading of "this plan
     * was just created and nobody has said anything yet". A new plan must grant
     * nothing until the operator turns something on.
     *
     * @return array<string, int|bool|null>
     */
    private function denyMap(): array
    {
        $map = [];
        foreach (Entitlements::registry() as $key => $entry) {
            $map[$key] = $entry['default'];
        }

        return $map;
    }

    private function demoteOtherTrialPlans(Plan $plan): void
    {
        Plan::where('is_default_trial', true)
            ->whereKeyNot($plan->id)
            ->update(['is_default_trial' => false]);
    }

    private function nextSort(): int
    {
        return ((int) Plan::max('sort')) + 10;
    }

    /** A stable, collision-free plan key derived from a name. */
    private function uniqueKey(string $seed): string
    {
        $base = Str::of($seed)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(32, '')->value();
        $base = $base !== '' ? $base : 'plan';

        if (! preg_match('/^[a-z]/', $base)) {
            $base = 'plan_'.$base;
        }

        $key = $base;
        $n = 2;
        while (Plan::where('key', $key)->exists()) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }

    /** The audit "before"/"after" shape for a plan. */
    private function snapshot(?Plan $plan): array
    {
        if (! $plan) {
            return [];
        }

        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'price_cents' => $plan->price_cents,
            'price_cents_yearly' => $plan->price_cents_yearly,
            'currency' => $plan->currency,
            'trial_days' => $plan->trial_days,
            'visibility' => $plan->visibility,
            'is_public' => (bool) $plan->is_public,
            'is_default_trial' => (bool) $plan->is_default_trial,
            'archived_at' => $plan->archived_at?->toIso8601String(),
            'sort' => (int) $plan->sort,
            'max_users' => $plan->max_users,
            'entitlements' => $plan->entitlements,
        ];
    }
}
