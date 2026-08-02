<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Tenancy\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * THE FEATURE CATALOGUE editor — this is what makes "add or remove features
 * from any plan" real. Adding a row here makes a brand-new entitlement key
 * appear in the plan editor, the add-on editor, the per-company override editor
 * and the UI lock map, with no code change anywhere.
 *
 * ROLE GATE: `platform.role:owner` — OWNER ONLY, including the read view.
 * This is deliberately stricter than the rest of the operator panel: the
 * catalogue defines what the product IS. Deactivating a row here revokes that
 * capability for EVERY tenant on the next request (the resolver denies any key
 * that is not in the active catalogue, without even reading the plan), so it is
 * the single most far-reaching switch in the console. Pricing and per-company
 * exceptions stay at admin; the shape of the product does not.
 *
 * ─── WHAT IS AND IS NOT EDITABLE ─────────────────────────────────────────────
 *  • `key` is IMMUTABLE once created. It is the identifier the gate, the plan
 *    maps, the add-on maps and every stored override are written against;
 *    renaming it would silently orphan all of them. "Rename a feature" means
 *    changing its LABEL, which is free.
 *  • Rows are never deleted, only DEACTIVATED, so historic plan/override maps
 *    stay interpretable and the audit trail keeps meaning something.
 *  • `default_value` must be the DENY value. A `max` feature may not default to
 *    unlimited — a default is what applies when nobody granted anything, so an
 *    unlimited default would hand the whole product away for free.
 */
class FeatureController extends Controller
{
    /** Sanity ceiling — the catalogue is a product definition, not a data store. */
    private const MAX_FEATURES = 120;

    // -----------------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------------

    public function index()
    {
        // Both collections are tiny (a handful of rows each) and are the ONLY
        // things that can reference a feature key, so usage is computed in PHP
        // rather than with a query per feature.
        $plans = Plan::ordered()->get(['id', 'key', 'name', 'entitlements', 'archived_at']);
        $addons = Addon::ordered()->get(['id', 'key', 'name', 'entitlements', 'archived_at']);

        $features = Feature::ordered()->get()->map(fn (Feature $feature) => [
            'id' => $feature->id,
            'key' => $feature->key,
            'label' => $feature->label,
            'help' => $feature->help,
            'kind' => $feature->kind,
            'group' => $feature->group,
            'unit' => $feature->unit,
            'default_value' => $feature->default_value,
            'sort' => (int) $feature->sort,
            'is_active' => (bool) $feature->is_active,
            'granted_by' => [
                'plans' => $this->grantingNames($plans, $feature),
                'addons' => $this->grantingNames($addons, $feature),
            ],
        ])->values();

        return Inertia::render('Platform/Features', [
            'features' => $features,
            'kinds' => Feature::KINDS,
            'groups' => $features->pluck('group')->unique()->sort()->values(),
        ]);
    }

    /**
     * Which of these plans/add-ons actually grant a feature (flag on, or a
     * numeric that is not the deny default) — so the owner can see the blast
     * radius before deactivating a row.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $rows
     * @return array<int, string>
     */
    private function grantingNames($rows, Feature $feature): array
    {
        return $rows->filter(function ($row) use ($feature) {
            $map = is_array($row->entitlements) ? $row->entitlements : [];

            if (! array_key_exists($feature->key, $map)) {
                return false;
            }

            $value = $map[$feature->key];

            if ($feature->kind === 'flag') {
                return (bool) $value;
            }

            // null = unlimited, and anything above the deny default is a grant.
            return $value === null || $value !== $feature->defaultValue();
        })->pluck('name')->values()->all();
    }

    // -----------------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------------

    /** Add a brand-new gated capability to the product. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('features', 'key')],
            'label' => ['required', 'string', 'max:80'],
            'help' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', Rule::in(Feature::KINDS)],
            'group' => ['required', 'string', 'max:40'],
            'unit' => ['nullable', 'string', 'max:20'],
            'default_value' => ['nullable'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'key.regex' => 'A feature key must be lower_snake_case and start with a letter.',
        ]);

        if (Feature::count() >= self::MAX_FEATURES) {
            throw ValidationException::withMessages(['key' => 'The feature catalogue is full.']);
        }

        $feature = Feature::create([
            'key' => $data['key'],
            'label' => $data['label'],
            'help' => $data['help'] ?? null,
            'kind' => $data['kind'],
            'group' => trim($data['group']),
            'unit' => ($data['unit'] ?? null) ?: null,
            'default_value' => $this->denyDefault($data['kind'], $data['default_value'] ?? null),
            'sort' => $data['sort'] ?? $this->nextSort(),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_FEATURE_CREATED,
            targetType: 'feature',
            targetId: $feature->id,
            after: $this->snapshot($feature),
            reason: $data['reason'] ?? null,
        );

        return back()->with(
            'success',
            "Feature “{$feature->label}” added. It is off on every plan until you turn it on."
        );
    }

    /**
     * Edit a feature's presentation, kind, deny default or active state.
     * `key` is intentionally NOT accepted — see the class docblock.
     */
    public function update(Request $request, Feature $feature)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'help' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', Rule::in(Feature::KINDS)],
            'group' => ['required', 'string', 'max:40'],
            'unit' => ['nullable', 'string', 'max:20'],
            'default_value' => ['nullable'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $before = $this->snapshot($feature);

        $feature->fill([
            'label' => $data['label'],
            'help' => $data['help'] ?? null,
            'kind' => $data['kind'],
            'group' => trim($data['group']),
            'unit' => ($data['unit'] ?? null) ?: null,
            'default_value' => $this->denyDefault($data['kind'], $data['default_value'] ?? null),
            'sort' => $data['sort'] ?? $feature->sort,
            'is_active' => (bool) $data['is_active'],
        ])->save();

        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_FEATURE_UPDATED,
            targetType: 'feature',
            targetId: $feature->id,
            before: $before,
            after: $this->snapshot($feature->fresh()),
            reason: $data['reason'] ?? null,
        );

        $note = $feature->is_active
            ? "Feature “{$feature->label}” updated."
            : "Feature “{$feature->label}” deactivated — it is now denied for every workspace.";

        return back()->with('success', $note);
    }

    /** Write the catalogue display order (the order every editor renders in). */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'max:'.self::MAX_FEATURES],
            'order.*' => ['integer'],
        ]);

        // Never trust request ids — intersect with what exists.
        $known = Feature::whereIn('id', $data['order'])->pluck('key', 'id');

        $ordered = collect($data['order'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $known->has($id))
            ->unique()
            ->values();

        if ($ordered->isEmpty()) {
            return back()->with('info', 'Nothing to reorder.');
        }

        $before = Feature::ordered()->pluck('key', 'id')->all();

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $position => $id) {
                Feature::whereKey($id)->update(['sort' => ($position + 1) * 10]);
            }
        });

        // Feature::booted() flushes the catalogue cache on model saves, but a
        // mass update() fires no model events — bump explicitly.
        Feature::flushCatalogue();
        Entitlements::bump();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_FEATURE_UPDATED,
            targetType: 'feature',
            before: ['order' => $before],
            after: ['order' => Feature::ordered()->pluck('key', 'id')->all()],
        );

        return back()->with('success', 'Feature order saved.');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Coerce the submitted default into a genuine DENY value for the kind.
     *
     * flag → strict bool. max/min → a non-negative integer. `null` is refused on
     * numeric kinds because null means UNLIMITED: a feature whose default is
     * "unlimited" would be granted to everyone who was never granted anything,
     * which is the exact opposite of default-deny.
     */
    private function denyDefault(string $kind, mixed $value): int|bool
    {
        if ($kind === 'flag') {
            return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if ($value === null || $value === '' || ! is_numeric($value)) {
            // A ceiling denies at 0; a floor with no stated default denies at 0
            // too (the operator can raise it — see the field help).
            return 0;
        }

        return max(0, (int) $value);
    }

    private function nextSort(): int
    {
        return ((int) Feature::max('sort')) + 10;
    }

    private function snapshot(?Feature $feature): array
    {
        if (! $feature) {
            return [];
        }

        return [
            'key' => $feature->key,
            'label' => $feature->label,
            'help' => $feature->help,
            'kind' => $feature->kind,
            'group' => $feature->group,
            'unit' => $feature->unit,
            'default_value' => $feature->default_value,
            'sort' => (int) $feature->sort,
            'is_active' => (bool) $feature->is_active,
        ];
    }
}
