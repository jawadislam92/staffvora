<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WHAT the work was — a workspace-owned, editable, sortable, soft-archivable
 * list (exactly like Clients are), replacing the old flat `work_type` enum.
 *
 * The old `WorkHourController::WORK_TYPES` list conflated three different
 * questions into one column. They are now three separate things:
 *
 *   A. CAPTURE METHOD  — tracked (desktop app) vs manual (typed in). System-set,
 *                        never chosen by a person. {@see WorkHour::captureMethod()}
 *   B. WORK CATEGORY   — this model. One row per category per workspace.
 *   C. BILLABLE        — a yes/no flag on the time entry (work_hours.is_billable),
 *                        seeded from this category's `is_billable_default`.
 *
 * ONE MECHANISM, ONE CODE PATH (binding owner decision Q5). An Agency workspace
 * and a General workspace differ ONLY in which rows are seeded at signup — there
 * is no `if (agency)` branch anywhere in the work-category logic, no parallel
 * enum and no second label map. Sparking Asia's "Bidding" / "Outside Upwork" are
 * ordinary editable rows, identical in kind to any other customer's categories.
 *
 * TENANCY: nullable, indexed organization_id + the OrganizationScope does the
 * filtering (never hardcode an id). Slugs are therefore unique PER WORKSPACE,
 * not globally, and are only a stable key for seeding/backfill — the NAME is
 * what a workspace edits, so a renamed row keeps its slug and its history.
 */
class WorkCategory extends Model
{
    use BelongsToOrganization;
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'is_billable_default',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_billable_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The approved starter set every workspace gets (owner decision Q3).
     * Order here IS the seeded sort_order.
     */
    public const GENERAL_DEFAULTS = [
        ['slug' => 'client_work', 'name' => 'Client Work', 'is_billable_default' => true],
        ['slug' => 'internal_admin', 'name' => 'Internal/Admin', 'is_billable_default' => false],
        ['slug' => 'business_development', 'name' => 'Business Development', 'is_billable_default' => false],
        ['slug' => 'training_onboarding', 'name' => 'Training/Onboarding', 'is_billable_default' => false],
        ['slug' => 'meetings', 'name' => 'Meetings', 'is_billable_default' => false],
    ];

    /**
     * Extra starter rows an AGENCY workspace gets on top of the General set, so
     * Sparking Asia keeps its exact vocabulary. These are plain rows — editable,
     * renameable and archivable like every other one.
     */
    public const AGENCY_EXTRA_DEFAULTS = [
        ['slug' => 'bidding', 'name' => 'Bidding', 'is_billable_default' => false],
        ['slug' => 'outside_upwork', 'name' => 'Outside Upwork', 'is_billable_default' => true],
        ['slug' => 'trial_test_task', 'name' => 'Trial/Test task', 'is_billable_default' => false],
    ];

    /**
     * LEGACY MAP — every historical `work_hours.work_type` value → the category
     * slug that preserves its meaning. Used by the backfill migration, by the
     * transition-period controller input, and by the tracker mirror path (which
     * still writes `work_type`). `fixed` was a BILLING concept, not a category:
     * it becomes Client Work, and Client Work's is_billable_default (true) is
     * what carries the "this bills" meaning forward.
     */
    public const LEGACY_WORK_TYPE_MAP = [
        'tracker' => 'client_work',
        'manual' => 'client_work',
        'tracker_manual' => 'client_work',
        'fixed' => 'client_work',
        'office_work' => 'internal_admin',
        'upwork_bidding' => 'bidding',
        'outside_of_upwork' => 'outside_upwork',
        'test_task' => 'trial_test_task',
    ];

    /**
     * The reverse map, used only to keep the legacy `work_type` column readable
     * for the old label maps that have not been retired yet. `client_work` is
     * deliberately absent: it resolves to tracker/manual from the entry's
     * CAPTURE METHOD instead {@see WorkHour::legacyWorkTypeFromCategory()}.
     */
    public const LEGACY_WORK_TYPE_BY_SLUG = [
        'internal_admin' => 'office_work',
        'bidding' => 'upwork_bidding',
        'outside_upwork' => 'outside_of_upwork',
        'trial_test_task' => 'test_task',
    ];

    /** Per-process slug => [id, is_billable_default] map, keyed by organization id. */
    private static array $resolverCache = [];

    protected static function booted(): void
    {
        // Any write to the catalogue invalidates the in-process resolver map, so
        // a long-running queue worker can never hand out a stale category id.
        static::saved(fn () => static::flushResolverCache());
        static::deleted(fn () => static::flushResolverCache());
    }

    /** Not archived — the only rows offered when logging NEW time. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Archived rows only (the "Archived" tab); history keeps rendering them. */
    public function scopeArchived($query)
    {
        return $query->where('is_active', false);
    }

    /** Display order: the workspace's own sort, then name as a stable tiebreak. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    public function workHours()
    {
        return $this->hasMany(WorkHour::class);
    }

    /**
     * The starter rows for a workspace type, already carrying their sort_order.
     * PURE — no database access — so the backfill migration can share this exact
     * list with the runtime seeder (one seed mechanism, per owner decision Q5).
     *
     * @return array<int, array{slug: string, name: string, is_billable_default: bool, sort_order: int}>
     */
    public static function defaultsFor(?string $workspaceType): array
    {
        $definitions = self::GENERAL_DEFAULTS;

        if (($workspaceType ?: 'general') === 'agency') {
            $definitions = array_merge($definitions, self::AGENCY_EXTRA_DEFAULTS);
        }

        return array_map(
            fn (array $definition, int $index) => $definition + ['sort_order' => $index],
            $definitions,
            array_keys($definitions)
        );
    }

    /**
     * One known starter definition by slug — including the agency-only ones, so
     * the backfill can materialise a category for a legacy value a workspace
     * actually used even when its workspace_type wouldn't seed it.
     *
     * @return array{slug: string, name: string, is_billable_default: bool}|null
     */
    public static function definition(string $slug): ?array
    {
        foreach (array_merge(self::GENERAL_DEFAULTS, self::AGENCY_EXTRA_DEFAULTS) as $definition) {
            if ($definition['slug'] === $slug) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Give the ACTIVE workspace its starter categories the first time a surface
     * that needs them is opened. Idempotent, a no-op once the workspace has any
     * category of its own (even archived ones — an emptied list is a choice, not
     * a missing seed), and a no-op when no tenant is bound so we can never
     * create orphan rows visible to nobody. Mirrors Shift::ensureSeeded().
     */
    public static function ensureSeeded(): void
    {
        $organizationId = app(CurrentOrganization::class)->id();

        if ($organizationId === null) {
            return;
        }

        if (static::query()->exists()) {
            return;
        }

        $workspaceType = Organization::query()->whereKey($organizationId)->value('workspace_type');

        foreach (static::defaultsFor($workspaceType) as $definition) {
            static::create($definition);
        }

        static::flushResolverCache();
    }

    /**
     * The category a legacy `work_type` value means for the ACTIVE workspace, or
     * null when no tenant is bound / nothing matches. Used on the write path so
     * rows created by callers that still speak `work_type` (the desktop tracker
     * mirror, the API, old form posts) land in the right category automatically.
     *
     * Costs at most ONE query per process per workspace: the whole catalogue is
     * memoised, and the first miss lazily seeds the workspace.
     */
    public static function resolveForLegacyWorkType(?string $workType): ?self
    {
        if ($workType === null || $workType === '') {
            return null;
        }

        $slug = self::LEGACY_WORK_TYPE_MAP[$workType] ?? null;

        if ($slug === null) {
            return null;
        }

        return static::resolveBySlug($slug);
    }

    /** The active workspace's category with this slug (lazily seeding if empty). */
    public static function resolveBySlug(string $slug): ?self
    {
        $organizationId = app(CurrentOrganization::class)->id();

        if ($organizationId === null) {
            return null;
        }

        if (! array_key_exists($organizationId, self::$resolverCache)) {
            $map = static::query()->ordered()->get(['id', 'slug', 'is_billable_default', 'name', 'sort_order', 'is_active', 'organization_id']);

            if ($map->isEmpty()) {
                static::ensureSeeded();
                $map = static::query()->ordered()->get(['id', 'slug', 'is_billable_default', 'name', 'sort_order', 'is_active', 'organization_id']);
            }

            self::$resolverCache[$organizationId] = $map->keyBy('slug');
        }

        return self::$resolverCache[$organizationId][$slug] ?? null;
    }

    /** Drop the memoised catalogue (called on every write; also useful in tests). */
    public static function flushResolverCache(): void
    {
        self::$resolverCache = [];
    }
}
