<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Tenancy\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * ADD-ONS — sellable bundles of entitlements that attach to a company on top of
 * its plan (the Agency toolkit: Upwork profiles + Machine Compliance).
 *
 * ROLE GATE: `platform.role:admin` for everything here (catalogue CRUD and
 * per-company attach/detach). Support may not change what a customer has paid
 * for.
 *
 * ─── NO SECOND ACCESS PATH ───────────────────────────────────────────────────
 * An add-on is not a gate. It contributes an `entitlements` map — the same shape
 * as `plans.entitlements` — that the ONE resolver folds in:
 *
 *     per-company override → add-on → plan → catalogue default (DENY)
 *
 * ─── WHY THE ADD-ON SANITISER IS NOT `sanitizePlanValues` ────────────────────
 * A PLAN map is complete: it states a value for every key, `false` included.
 * An ADD-ON map must be SPARSE — it may only ever ADD. If we stored `false` for
 * every flag the operator left off, that `false` would sit in the add-on LAYER,
 * which resolves ABOVE the plan, and buying the Agency toolkit would silently
 * switch off everything the customer's plan already granted. So a flag that is
 * off is OMITTED (it "says nothing" and falls through to the plan), and only
 * grants are written. See `sanitizeAddonEntitlements()`.
 *
 * A numeric value on an add-on REPLACES the plan's value for that key rather
 * than being merged with it, so the editor tells the operator to use it only to
 * RAISE a limit (or to type "unlimited").
 */
class AddonController extends Controller
{
    private const MAX_ADDONS = 40;

    // -----------------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------------

    public function index()
    {
        $holderCounts = $this->holderCounts();

        return Inertia::render('Platform/Addons', [
            'addons' => Addon::ordered()->get()->map(fn (Addon $addon) => [
                'id' => $addon->id,
                'key' => $addon->key,
                'name' => $addon->name,
                'description' => $addon->description,
                'price_cents' => $addon->price_cents,
                'price_cents_yearly' => $addon->price_cents_yearly,
                'currency' => $addon->currency ?: 'USD',
                'visibility' => $addon->visibility ?: 'public',
                'archived_at' => $addon->archived_at?->toDateString(),
                'sort' => (int) $addon->sort,
                'holders' => $holderCounts[$addon->id] ?? 0,
                // Sparse on purpose: only the keys this add-on speaks about.
                'entitlements' => is_array($addon->entitlements) ? $addon->entitlements : [],
            ])->values(),
            'registry' => Entitlements::registry(),
            'visibilities' => Addon::VISIBILITIES,
        ]);
    }

    /**
     * How many companies hold each add-on — ONE grouped query, never a count
     * per add-on.
     *
     * @return array<int, int>
     */
    private function holderCounts(): array
    {
        try {
            return DB::table('organization_addon')
                ->selectRaw('addon_id, COUNT(*) as aggregate')
                ->groupBy('addon_id')
                ->pluck('aggregate', 'addon_id')
                ->map(fn ($n) => (int) $n)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Catalogue CRUD
    // -----------------------------------------------------------------------

    public function store(Request $request)
    {
        $data = $this->validateAddon($request, null);

        if (Addon::count() >= self::MAX_ADDONS) {
            throw ValidationException::withMessages(['name' => 'The add-on catalogue is full.']);
        }

        $addon = Addon::create([
            'key' => $data['key'] ?? $this->uniqueKey($data['name']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_cents' => $data['price_cents'] ?? 0,
            'price_cents_yearly' => $data['price_cents_yearly'] ?? null,
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            // New add-ons start hidden — nothing is published by accident.
            'visibility' => $data['visibility'] ?? 'hidden',
            'entitlements' => $this->sanitizeAddonEntitlements($data['entitlements'] ?? []),
            'sort' => ((int) Addon::max('sort')) + 10,
            'archived_at' => null,
        ]);

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_ADDON_CREATED,
            targetType: 'addon',
            targetId: $addon->id,
            after: $this->snapshot($addon),
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "Add-on “{$addon->name}” created.");
    }

    public function update(Request $request, Addon $addon)
    {
        $data = $this->validateAddon($request, $addon);

        $before = $this->snapshot($addon);

        $addon->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_cents' => $data['price_cents'] ?? 0,
            'price_cents_yearly' => $data['price_cents_yearly'] ?? null,
            'currency' => strtoupper($data['currency'] ?? ($addon->currency ?: 'USD')),
            'visibility' => $data['visibility'] ?? ($addon->visibility ?: 'hidden'),
            'entitlements' => $this->sanitizeAddonEntitlements($data['entitlements'] ?? []),
        ])->save();

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_ADDON_UPDATED,
            targetType: 'addon',
            targetId: $addon->id,
            before: $before,
            after: $this->snapshot($addon->fresh()),
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "{$addon->name} updated.");
    }

    /** Retire an add-on. Companies keep the row, but it stops contributing. */
    public function archive(Request $request, Addon $addon)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        if ($addon->isArchived()) {
            return back()->with('info', "{$addon->name} is already archived.");
        }

        $before = $this->snapshot($addon);

        $addon->forceFill(['archived_at' => now()])->save();

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_ADDON_UPDATED,
            targetType: 'addon',
            targetId: $addon->id,
            before: $before,
            after: $this->snapshot($addon->fresh()),
            reason: $data['reason'] ?? null,
        );

        return back()->with(
            'success',
            "{$addon->name} archived — it no longer grants anything, including to companies that hold it."
        );
    }

    public function restore(Request $request, Addon $addon)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $before = $this->snapshot($addon);

        $addon->forceFill(['archived_at' => null])->save();

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_ADDON_UPDATED,
            targetType: 'addon',
            targetId: $addon->id,
            before: $before,
            after: $this->snapshot($addon->fresh()),
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "{$addon->name} restored.");
    }

    // -----------------------------------------------------------------------
    // Per-company grants
    // -----------------------------------------------------------------------

    /** Give ONE company an add-on, optionally time-boxed (a trial that expires). */
    public function attach(Request $request, Organization $organization)
    {
        $data = $request->validate([
            'addon_id' => ['required', 'integer', Rule::exists('addons', 'id')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Never trust the request id beyond existence: re-read the row and
        // refuse a retired add-on, which would grant nothing anyway.
        $addon = Addon::findOrFail((int) $data['addon_id']);

        if ($addon->isArchived()) {
            throw ValidationException::withMessages([
                'addon_id' => 'That add-on is archived. Restore it before granting it.',
            ]);
        }

        $startsAt = ! empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : null;
        $endsAt = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at'])->endOfDay() : null;

        // grantAddon() also invalidates the resolved-entitlement caches — a raw
        // pivot attach fires no model events and would serve a stale UI map.
        $organization->grantAddon($addon, $startsAt, $endsAt);

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_ADDON_GRANTED,
            organizationId: $organization->id,
            targetType: 'addon',
            targetId: $addon->id,
            after: [
                'addon' => $addon->key,
                'starts_at' => $startsAt?->toIso8601String(),
                'ends_at' => $endsAt?->toIso8601String(),
                'entitlements' => $addon->entitlements,
            ],
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "{$addon->name} granted to {$organization->name}.");
    }

    public function detach(Request $request, Organization $organization, Addon $addon)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $organization->revokeAddon($addon);

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_ADDON_REVOKED,
            organizationId: $organization->id,
            targetType: 'addon',
            targetId: $addon->id,
            before: ['addon' => $addon->key, 'entitlements' => $addon->entitlements],
            reason: $data['reason'] ?? null,
        );

        return back()->with('success', "{$addon->name} removed from {$organization->name}.");
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function validateAddon(Request $request, ?Addon $addon): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'key' => [
                $addon ? 'prohibited' : 'nullable',
                'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('addons', 'key'),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'price_cents_yearly' => ['nullable', 'integer', 'min:0', 'max:1200000000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'visibility' => ['nullable', Rule::in(Addon::VISIBILITIES)],
            'entitlements' => ['nullable', 'array', 'max:200'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'key.prohibited' => 'An add-on key cannot be changed once it exists.',
        ]);
    }

    /**
     * Turn the add-on editor's form into a SPARSE grant map.
     *
     * Rules (see the class docblock for why this is not sanitizePlanValues):
     *   • flag OFF                 → key OMITTED. It must never be stored as
     *                                `false`: the add-on layer resolves above
     *                                the plan, so a stored `false` would REVOKE
     *                                what the customer's plan already grants.
     *   • flag ON                  → true.
     *   • numeric blank            → OMITTED ("says nothing", inherit the plan).
     *   • numeric "unlimited"      → the unlimited sentinel.
     *   • numeric value            → a non-negative integer.
     *   • unknown / retired key    → dropped.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function sanitizeAddonEntitlements(array $input): array
    {
        $clean = [];

        foreach (Entitlements::registry() as $key => $entry) {
            $raw = $input[$key] ?? null;

            if ($entry['kind'] === 'flag') {
                if (filter_var($raw, FILTER_VALIDATE_BOOLEAN)) {
                    $clean[$key] = true;
                }

                continue;
            }

            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }

            if (Entitlements::meansUnlimited($raw)) {
                $clean[$key] = Entitlements::UNLIMITED;

                continue;
            }

            if (! is_numeric($raw)) {
                continue;
            }

            $clean[$key] = max(0, (int) $raw);
        }

        return $clean;
    }

    private function uniqueKey(string $seed): string
    {
        $base = Str::of($seed)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(32, '')->value();
        $base = $base !== '' ? $base : 'addon';

        if (! preg_match('/^[a-z]/', $base)) {
            $base = 'addon_'.$base;
        }

        $key = $base;
        $n = 2;
        while (Addon::where('key', $key)->exists()) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }

    private function snapshot(?Addon $addon): array
    {
        if (! $addon) {
            return [];
        }

        return [
            'key' => $addon->key,
            'name' => $addon->name,
            'description' => $addon->description,
            'price_cents' => $addon->price_cents,
            'price_cents_yearly' => $addon->price_cents_yearly,
            'currency' => $addon->currency,
            'visibility' => $addon->visibility,
            'archived_at' => $addon->archived_at?->toIso8601String(),
            'entitlements' => $addon->entitlements,
        ];
    }
}
