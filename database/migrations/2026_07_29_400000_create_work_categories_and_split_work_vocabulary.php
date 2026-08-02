<?php

use App\Models\WorkCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the old flat `work_hours.work_type` enum into the three separate things
 * it was conflating (binding spec: documents/STAFFVORA_WORK_VOCABULARY.md §4):
 *
 *   A. CAPTURE METHOD — tracked vs manual. Already derivable from
 *      tracking_session_id / source, so NO new column: WorkHour::captureMethod()
 *      is the one reader.
 *   B. WORK CATEGORY  — the new `work_categories` table: one editable, sortable,
 *      soft-archivable row per category per workspace, plus
 *      `work_hours.work_category_id`.
 *   C. BILLABLE       — `work_hours.is_billable`.
 *
 * ONE MECHANISM (owner decision Q5, verbatim: "please ensure they use the SAME
 * NEW UNDERLYING STRUCTURE as every other company so we are not maintaining a
 * separate system"). Agency vs General differ ONLY in which starter ROWS are
 * seeded — the list lives in one place, WorkCategory::defaultsFor(), shared by
 * this migration and by the runtime WorkCategory::ensureSeeded(). No `if
 * (agency)` branch in any work-category logic, no parallel enum.
 *
 * BACKFILL (preserves the meaning of every existing row):
 *   office_work       → Internal/Admin
 *   upwork_bidding    → Bidding
 *   outside_of_upwork → Outside Upwork
 *   test_task         → Trial/Test task
 *   fixed             → Client Work, is_billable = true   (billing, not category)
 *   tracker / manual  → Client Work                       (capture, not category)
 * `work_type` itself is KEPT and stays readable (WorkHour::work_type accessor);
 * nothing new depends on it.
 *
 * SCALE: the backfill never loads a full table — organizations are chunked, and
 * each workspace's rows are walked with chunkById(1000) issuing at most one
 * bounded UPDATE per category per chunk. Safe to run against ~1M rows.
 *
 * CONVENTION: no DB-level foreign keys (plain unsignedBigInteger + index) and
 * every step is guarded with hasTable/hasColumn/hasIndex, so it is idempotent
 * and identical on SQLite (local) and MySQL (prod).
 */
return new class extends Migration
{
    /** Rows walked per bounded UPDATE batch during the backfill. */
    private const BACKFILL_CHUNK = 1000;

    public function up(): void
    {
        if (! Schema::hasTable('work_categories')) {
            Schema::create('work_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->string('name');
                // Stable machine key for seeding/backfill/legacy reads. Nullable
                // because a workspace-created category doesn't need one, and NOT
                // unique at the DB level so a rename/re-add can never 500 — the
                // application enforces per-workspace uniqueness.
                $table->string('slug', 64)->nullable();
                $table->boolean('is_billable_default')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                // Soft-archive: an archived category disappears from the pickers
                // used to log NEW time while every historical entry keeps
                // rendering it (same rule as an archived client).
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['organization_id', 'sort_order'], 'work_categories_org_order_idx');
                $table->index(['organization_id', 'slug'], 'work_categories_org_slug_idx');
            });
        }

        if (Schema::hasTable('work_hours')) {
            if (! Schema::hasColumn('work_hours', 'work_category_id')) {
                Schema::table('work_hours', function (Blueprint $table) {
                    $table->unsignedBigInteger('work_category_id')->nullable()->index();
                });
            }

            if (! Schema::hasColumn('work_hours', 'is_billable')) {
                Schema::table('work_hours', function (Blueprint $table) {
                    // Defaults TRUE: a time tracker's rows are billable work
                    // unless a category says otherwise, and it keeps writers we
                    // don't touch (the desktop-tracker mirror) consistent with
                    // what the backfill gives their historical rows. The backfill
                    // immediately corrects every non-billable legacy category.
                    $table->boolean('is_billable')->default(true);
                });
            }

            // Reporting/filtering indexes. Every read is org-scoped by the
            // OrganizationScope, so organization_id leads both composites:
            // equality, equality, then the date range.
            $this->addIndexIfMissing('work_hours', ['organization_id', 'work_category_id'], 'work_hours_org_category_idx');
            $this->addIndexIfMissing('work_hours', ['organization_id', 'is_billable', 'date'], 'work_hours_org_billable_date_idx');
        }

        $this->seedAndBackfill();
    }

    public function down(): void
    {
        if (Schema::hasTable('work_hours')) {
            $this->dropIndexIfExists('work_hours', 'work_hours_org_category_idx');
            $this->dropIndexIfExists('work_hours', 'work_hours_org_billable_date_idx');

            if (Schema::hasColumn('work_hours', 'work_category_id')) {
                Schema::table('work_hours', function (Blueprint $table) {
                    $table->dropIndex(['work_category_id']);
                    $table->dropColumn('work_category_id');
                });
            }

            if (Schema::hasColumn('work_hours', 'is_billable')) {
                Schema::table('work_hours', function (Blueprint $table) {
                    $table->dropColumn('is_billable');
                });
            }
        }

        Schema::dropIfExists('work_categories');
    }

    /**
     * Seed every EXISTING workspace its starter categories, then re-file its
     * historical work_hours rows onto them. Workspaces created later get theirs
     * lazily from WorkCategory::ensureSeeded().
     */
    private function seedAndBackfill(): void
    {
        if (! Schema::hasTable('organizations') || ! Schema::hasTable('work_categories')) {
            return;
        }

        DB::table('organizations')
            ->select('id', 'workspace_type')
            ->orderBy('id')
            ->chunk(100, function ($organizations) {
                foreach ($organizations as $organization) {
                    $this->seedOrganization((int) $organization->id, $organization->workspace_type ?? 'general');
                }
            });
    }

    private function seedOrganization(int $organizationId, ?string $workspaceType): void
    {
        $now = now();

        $categories = DB::table('work_categories')
            ->where('organization_id', $organizationId)
            ->get(['id', 'slug', 'is_billable_default', 'sort_order']);

        /** @var array<string, int> $idBySlug */
        $idBySlug = [];
        /** @var array<int, bool> $billableById */
        $billableById = [];
        $nextSortOrder = 0;

        foreach ($categories as $category) {
            if ($category->slug !== null) {
                $idBySlug[$category->slug] = (int) $category->id;
            }
            $billableById[(int) $category->id] = (bool) $category->is_billable_default;
            $nextSortOrder = max($nextSortOrder, ((int) $category->sort_order) + 1);
        }

        $insert = function (array $definition, int $sortOrder) use ($organizationId, $now, &$idBySlug, &$billableById): void {
            $id = (int) DB::table('work_categories')->insertGetId([
                'organization_id' => $organizationId,
                'name' => $definition['name'],
                'slug' => $definition['slug'],
                'is_billable_default' => $definition['is_billable_default'],
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $idBySlug[$definition['slug']] = $id;
            $billableById[$id] = (bool) $definition['is_billable_default'];
        };

        // 1. The starter set for this workspace type — the ONE shared list.
        foreach (WorkCategory::defaultsFor($workspaceType) as $definition) {
            if (! isset($idBySlug[$definition['slug']])) {
                $insert($definition, $nextSortOrder++);
            }
        }

        if (! Schema::hasTable('work_hours')) {
            return;
        }

        // 2. Any legacy value this workspace ACTUALLY used that its starter set
        //    wouldn't cover (e.g. a General workspace with historical
        //    `upwork_bidding` rows). Meaning is never dropped on the floor; a
        //    workspace that never used one never gets the row.
        $legacyInUse = DB::table('work_hours')
            ->where('organization_id', $organizationId)
            ->whereNotNull('work_type')
            ->distinct()
            ->pluck('work_type');

        foreach ($legacyInUse as $legacyWorkType) {
            $slug = WorkCategory::LEGACY_WORK_TYPE_MAP[$legacyWorkType] ?? null;

            if ($slug === null || isset($idBySlug[$slug])) {
                continue;
            }

            $definition = WorkCategory::definition($slug);

            if ($definition !== null) {
                $insert($definition, $nextSortOrder++);
            }
        }

        // 3. Re-file the history. chunkById walks by primary key, selecting only
        //    two columns, and each chunk issues one bounded UPDATE per distinct
        //    target category (at most a handful) — never a full-table load and
        //    never an unbounded UPDATE.
        DB::table('work_hours')
            ->where('organization_id', $organizationId)
            ->whereNull('work_category_id')
            ->whereNotNull('work_type')
            ->select('id', 'work_type')
            ->orderBy('id')
            ->chunkById(self::BACKFILL_CHUNK, function ($rows) use ($idBySlug, $billableById) {
                $idsByCategory = [];

                foreach ($rows as $row) {
                    $slug = WorkCategory::LEGACY_WORK_TYPE_MAP[$row->work_type] ?? null;
                    $categoryId = $slug === null ? null : ($idBySlug[$slug] ?? null);

                    if ($categoryId !== null) {
                        $idsByCategory[$categoryId][] = $row->id;
                    }
                }

                foreach ($idsByCategory as $categoryId => $ids) {
                    DB::table('work_hours')->whereIn('id', $ids)->update([
                        'work_category_id' => $categoryId,
                        // `fixed` folds into Client Work, whose is_billable_default
                        // is true — that is how the old billing meaning survives.
                        'is_billable' => $billableById[$categoryId] ?? true,
                    ]);
                }
            });
    }

    private function addIndexIfMissing(string $table, array $columns, string $name): void
    {
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->index($columns, $name);
            });
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (Schema::hasIndex($table, $name)) {
            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        }
    }
};
