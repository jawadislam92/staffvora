<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\PricingRegion;
use App\Models\PricingRegionPlan;
use App\Services\RegionPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * REGION PRICING RULES for the operator portal — "which plans may this part of
 * the world buy, and at what price".
 *
 * ROLE GATE: `platform.role:admin` (admin or owner), same as Plans and Add-ons.
 * Support may never change what a customer is offered or charged.
 *
 * ─── THIS CONTROLLER OWNS NO ACCESS LOGIC ────────────────────────────────────
 * A region rule is DATA about VISIBILITY and PRICE. It contributes nothing to
 * `Organization::can()` — there is no entitlements field here, and adding one
 * would be a second access path, which is banned. A visitor's country is
 * attacker-controlled; the worst a forged country can do is show a different
 * (operator-authored) price list.
 *
 * ─── WHAT THE SANITISER ENFORCES ─────────────────────────────────────────────
 *   • Plan rows are accepted ONLY for PUBLIC, non-archived plans. A region can
 *     narrow or re-price the public catalogue; it can never surface a hidden,
 *     custom, legacy or archived plan.
 *   • Money arrives in MAJOR units from the form and is stored as integer minor
 *     units; blank stays NULL ("no local price"), which is not 0 ("free here").
 *   • Country codes are normalised to uppercase ISO alpha-2, de-duplicated and
 *     bounded; anything unparseable is dropped rather than stored.
 *
 * ─── CACHE + AUDIT ───────────────────────────────────────────────────────────
 * The model layer bumps `RegionPricing`'s epoch on every write, so an edit is
 * live on the very next request. Every mutation writes a `platform_audit_logs`
 * row with before/after, so "who re-priced Pakistan, and when" is always
 * answerable.
 *
 * ─── SCALE ───────────────────────────────────────────────────────────────────
 * The catalogue is bounded (MAX_REGIONS) and read with two queries plus one
 * grouped count — never a query per region or per plan.
 */
class RegionController extends Controller
{
    /** Hard ceiling, so the region catalogue stays a catalogue. */
    private const MAX_REGIONS = 60;

    // -----------------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------------

    public function index()
    {
        $rules = $this->planRulesByRegion();
        $tenantCounts = $this->tenantCountsByCountry();

        return Inertia::render('Platform/Regions', [
            'regions' => PricingRegion::ordered()->get()
                ->map(fn (PricingRegion $region) => $this->payload($region, $rules, $tenantCounts))
                ->values(),
            // The set a region may narrow or re-price: the PUBLIC catalogue.
            'plans' => Plan::visible()->ordered()->get()->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'key' => $plan->key,
                'name' => $plan->name,
                'price_cents' => $plan->price_cents,
                'price_cents_yearly' => $plan->price_cents_yearly,
                'currency' => $plan->currency ?: 'USD',
            ])->values(),
            'offerModes' => PricingRegion::OFFER_MODES,
            'maxCountries' => RegionPricing::MAX_COUNTRIES,
            // Seed the simulator with a country that actually has a rule.
            'sample' => $this->sampleResolution(),
        ]);
    }

    /**
     * "Why does this visitor see these prices?" — the simulator behind the
     * operator page. Returns the SAME structure signup and checkout resolve
     * from, so a preview can never disagree with what a customer is charged.
     */
    public function resolve(Request $request)
    {
        $data = $request->validate([
            'country' => ['nullable', 'string', 'max:2'],
        ]);

        return response()->json(RegionPricing::offer($data['country'] ?? null));
    }

    // -----------------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------------

    public function store(Request $request)
    {
        $data = $this->validateRegion($request, null);

        if (PricingRegion::count() >= self::MAX_REGIONS) {
            throw ValidationException::withMessages([
                'name' => 'The region catalogue is full. Archive a region before adding another.',
            ]);
        }

        $region = PricingRegion::create([
            'key' => $data['key'] ?? $this->uniqueKey($data['name']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'country_codes' => RegionPricing::normalizeCountryList($data['countries'] ?? []),
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            'offer_mode' => $data['offer_mode'] ?? 'all_except',
            'priority' => $data['priority'] ?? 100,
            // New rules start INACTIVE — no country is silently re-priced by a
            // half-finished rule.
            'is_active' => false,
            'archived_at' => null,
        ]);

        $this->syncPlanRules($region, $data['plans'] ?? []);

        PlatformAuditLog::record(
            action: PricingRegion::AUDIT_CREATED,
            targetType: 'pricing_region',
            targetId: $region->id,
            after: $this->snapshot($region->fresh()),
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "Region “{$region->name}” created (inactive until you switch it on).");
    }

    public function update(Request $request, PricingRegion $pricingRegion)
    {
        $data = $this->validateRegion($request, $pricingRegion);

        $before = $this->snapshot($pricingRegion);

        $pricingRegion->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'country_codes' => RegionPricing::normalizeCountryList($data['countries'] ?? []),
            'currency' => strtoupper($data['currency'] ?? ($pricingRegion->currency ?: 'USD')),
            'offer_mode' => $data['offer_mode'] ?? $pricingRegion->offer_mode,
            'priority' => $data['priority'] ?? $pricingRegion->priority,
            'is_active' => (bool) ($data['is_active'] ?? $pricingRegion->is_active),
        ])->save();

        $this->syncPlanRules($pricingRegion, $data['plans'] ?? []);

        PlatformAuditLog::record(
            action: PricingRegion::AUDIT_UPDATED,
            targetType: 'pricing_region',
            targetId: $pricingRegion->id,
            before: $before,
            after: $this->snapshot($pricingRegion->fresh()),
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "{$pricingRegion->name} updated.");
    }

    /** Retire a rule. Its countries fall straight back to the public catalogue. */
    public function archive(Request $request, PricingRegion $pricingRegion)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        if ($pricingRegion->isArchived()) {
            return back()->with('info', "{$pricingRegion->name} is already archived.");
        }

        $before = $this->snapshot($pricingRegion);

        $pricingRegion->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        PlatformAuditLog::record(
            action: PricingRegion::AUDIT_ARCHIVED,
            targetType: 'pricing_region',
            targetId: $pricingRegion->id,
            before: $before,
            after: $this->snapshot($pricingRegion->fresh()),
            reason: $data['reason'] ?? null,
        );

        return back()->with(
            'success',
            "{$pricingRegion->name} archived — its countries now see the standard public catalogue."
        );
    }

    public function restore(Request $request, PricingRegion $pricingRegion)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $before = $this->snapshot($pricingRegion);

        // Restored INACTIVE on purpose: switching it back on is a separate,
        // deliberate act, because it re-prices whole countries.
        $pricingRegion->forceFill(['archived_at' => null, 'is_active' => false])->save();

        PlatformAuditLog::record(
            action: PricingRegion::AUDIT_RESTORED,
            targetType: 'pricing_region',
            targetId: $pricingRegion->id,
            before: $before,
            after: $this->snapshot($pricingRegion->fresh()),
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "{$pricingRegion->name} restored (still inactive — switch it on when ready).");
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function validateRegion(Request $request, ?PricingRegion $region): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'key' => [
                $region ? 'prohibited' : 'nullable',
                'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('pricing_regions', 'key'),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'countries' => ['nullable', 'array', 'max:'.RegionPricing::MAX_COUNTRIES],
            // Deliberately permissive: the operator pastes a free-text list and
            // anything that is not a valid alpha-2 code is DROPPED by
            // RegionPricing::normalizeCountryList rather than rejected, so one
            // stray token cannot lose the whole edit. Only the length is bounded.
            'countries.*' => ['string', 'max:16'],
            'currency' => ['nullable', 'string', 'size:3'],
            'offer_mode' => ['nullable', Rule::in(PricingRegion::OFFER_MODES)],
            'priority' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
            'plans' => ['nullable', 'array', 'max:100'],
            'plans.*.plan_id' => ['required', 'integer'],
            'plans.*.is_offered' => ['nullable', 'boolean'],
            // Same ceilings as `plans.price_cents` — both columns are
            // unsignedInteger, so a bigger number would overflow rather than
            // save. Generous even for a low-value currency: 100,000,000 paisa
            // is Rs 1,000,000 per seat per month.
            'plans.*.price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'plans.*.price_cents_yearly' => ['nullable', 'integer', 'min:0', 'max:1200000000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'key.prohibited' => 'A region key cannot be changed once it exists.',
        ]);
    }

    /**
     * Write the per-plan rows, dropping anything that is not a PUBLIC,
     * non-archived plan. This is the invariant that stops a region from ever
     * surfacing a plan the operator has not published.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncPlanRules(PricingRegion $region, array $rows): void
    {
        // One query for the whole allow-list — never a lookup per row.
        $allowed = Plan::visible()->pluck('id')->all();

        $keep = [];

        foreach ($rows as $row) {
            $planId = (int) ($row['plan_id'] ?? 0);

            if (! in_array($planId, $allowed, true) || isset($keep[$planId])) {
                continue;
            }

            $keep[$planId] = [
                'is_offered' => (bool) ($row['is_offered'] ?? true),
                // Blank stays NULL — "no local price" is not the same as "free".
                'price_cents' => $this->nullableCents($row['price_cents'] ?? null),
                'price_cents_yearly' => $this->nullableCents($row['price_cents_yearly'] ?? null),
            ];
        }

        DB::transaction(function () use ($region, $keep) {
            $existing = PricingRegionPlan::where('pricing_region_id', $region->id)->get()->keyBy('plan_id');

            foreach ($keep as $planId => $values) {
                $row = $existing->get($planId) ?: new PricingRegionPlan([
                    'pricing_region_id' => $region->id,
                    'plan_id' => $planId,
                ]);

                $row->pricing_region_id = $region->id;
                $row->plan_id = $planId;
                $row->fill($values)->save();
            }

            // Rows the operator removed (or that point at a plan that is no
            // longer public) stop existing — a stale rule must never re-price.
            $stale = $existing->keys()->diff(array_keys($keep))->all();

            if ($stale !== []) {
                PricingRegionPlan::where('pricing_region_id', $region->id)
                    ->whereIn('plan_id', $stale)
                    ->delete();
            }
        });

        RegionPricing::bump();
    }

    private function nullableCents(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * Every region's plan rows, in ONE query.
     *
     * @return array<int, array<int, array<string, mixed>>> region_id => plan_id => row
     */
    private function planRulesByRegion(): array
    {
        $out = [];

        try {
            foreach (PricingRegionPlan::all() as $row) {
                $out[$row->pricing_region_id][$row->plan_id] = [
                    'plan_id' => $row->plan_id,
                    'is_offered' => (bool) $row->is_offered,
                    'price_cents' => $row->price_cents,
                    'price_cents_yearly' => $row->price_cents_yearly,
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $out;
    }

    /**
     * How many companies sit in each country — ONE grouped query, so the
     * operator can see the blast radius of a rule before switching it on.
     *
     * @return array<string, int>
     */
    private function tenantCountsByCountry(): array
    {
        try {
            return DB::table('organizations')
                ->selectRaw('country, COUNT(*) as aggregate')
                ->whereNotNull('country')
                ->groupBy('country')
                ->pluck('aggregate', 'country')
                ->map(fn ($n) => (int) $n)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $rules
     * @param  array<string, int>  $tenantCounts
     */
    private function payload(PricingRegion $region, array $rules, array $tenantCounts): array
    {
        $countries = $region->countries();

        return [
            'id' => $region->id,
            'key' => $region->key,
            'name' => $region->name,
            'description' => $region->description,
            'countries' => $countries,
            'currency' => $region->currency ?: 'USD',
            'offer_mode' => $region->offer_mode,
            'priority' => (int) $region->priority,
            'is_active' => (bool) $region->is_active,
            'archived_at' => $region->archived_at?->toDateString(),
            'tenants' => array_sum(array_map(fn ($cc) => $tenantCounts[$cc] ?? 0, $countries)),
            'plans' => array_values($rules[$region->id] ?? []),
        ];
    }

    /** A ready-made "this is what a visitor sees" example for the page. */
    private function sampleResolution(): array
    {
        $country = null;

        foreach (RegionPricing::rules() as $rule) {
            $country = $rule['countries'][0] ?? null;

            if ($country !== null) {
                break;
            }
        }

        return RegionPricing::offer($country);
    }

    private function uniqueKey(string $seed): string
    {
        $base = Str::of($seed)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(32, '')->value();
        $base = $base !== '' ? $base : 'region';

        if (! preg_match('/^[a-z]/', $base)) {
            $base = 'region_'.$base;
        }

        $key = $base;
        $n = 2;
        while (PricingRegion::where('key', $key)->exists()) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }

    private function snapshot(?PricingRegion $region): array
    {
        if (! $region) {
            return [];
        }

        return [
            'key' => $region->key,
            'name' => $region->name,
            'description' => $region->description,
            'countries' => $region->countries(),
            'currency' => $region->currency,
            'offer_mode' => $region->offer_mode,
            'priority' => (int) $region->priority,
            'is_active' => (bool) $region->is_active,
            'archived_at' => $region->archived_at?->toIso8601String(),
            'plans' => PricingRegionPlan::where('pricing_region_id', $region->id)
                ->orderBy('plan_id')
                ->get(['plan_id', 'is_offered', 'price_cents', 'price_cents_yearly'])
                ->map(fn (PricingRegionPlan $row) => [
                    'plan_id' => $row->plan_id,
                    'is_offered' => (bool) $row->is_offered,
                    'price_cents' => $row->price_cents,
                    'price_cents_yearly' => $row->price_cents_yearly,
                ])->all(),
        ];
    }
}
