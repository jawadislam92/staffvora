<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE index for Machine Compliance's "By employee" view, added only after the
 * page was finally measured at the volume its own docblock is written for.
 *
 * THE MEASUREMENT THAT JUSTIFIES IT. The compliance fixture held 1,976
 * inventory rows while `ComplianceController` sizes the page for "≈45,000
 * inventory rows per org" — a 23x undersize, so nothing about this page had
 * ever been measured for real. Re-seeded to exactly 45,000 rows / 37,573
 * distinct names across 125 machines
 * (`staffvora:seed-big-org --inventory-only --inventory-per-machine=360`), the
 * By-employee view's group COUNT was the single most expensive statement on it.
 *
 * `employeeRows()` groups `machine_inventory_items` by `user_id` over
 * `present = 1`, and the paginator needs the number of groups. The existing
 * `mach_inv_org_user_idx (organization_id, user_id, kind)` cannot serve that:
 * `present` is not in it, so SQLite abandoned the index entirely and answered
 * `COUNT(DISTINCT user_id)` with a full table SCAN plus a temp B-tree.
 *
 * (organization_id, present, user_id) is tenant-leading (OrganizationScope
 * always emits organization_id), puts the `present` predicate second where the
 * filter actually is, and carries the grouping column — so the count becomes
 * index-only.
 *
 * MEASURED by an interleaved A/B on the 45,000-row workspace (SQLite, index
 * dropped and re-created between passes with `ANALYZE` after every schema
 * change, so both configurations hand the planner equally fresh statistics):
 *
 *   COUNT(DISTINCT user_id) statement   10.7 ms -> 0.1 ms  (median of 3 passes)
 *   EXPLAIN                             "SCAN machine_inventory_items"
 *                                    -> "SEARCH … USING COVERING INDEX
 *                                        mach_inv_org_present_user_idx"
 *   whole /compliance?view=employees surface (real HTTP, 2 interleaved passes
 *   of 7 runs)                          45.2 / 37.7 ms -> 30.6 / 28.9 ms
 *
 * The page aggregate itself (the seven SUM/COUNT/MAX columns) is barely helped
 * and is not claimed to be: 18.3 ms without, 16.0 ms with. That statement has
 * to touch every one of the tenant's inventory rows whatever is indexed,
 * because it sums over all of them. Stated plainly rather than folded into the
 * headline.
 *
 * THE CONTROL HELD. Across the same interleaved passes, the two compliance
 * surfaces that never run this GROUP BY did not move: By extension
 * 57.1 / 57.9 ms -> 52.9 / 58.2 ms and Findings 48.0 / 41.3 ms -> 43.3 / 44.3
 * ms — both differences smaller than their own run-to-run spread. So the
 * By-employee number is signal, and this index is not quietly taxing the rest
 * of the page.
 *
 * NOT ADDED, because it was measured and made things WORSE. The obvious
 * companion for the "By extension" view is
 * (organization_id, kind, present, name), to serve its
 * `COUNT(DISTINCT LOWER(name))`. It does help that one statement (21.3 ms ->
 * 4.4 ms) — but SQLite then also chose it for the page aggregate, which it does
 * not cover, and the row lookups made that aggregate 26.6 ms -> 59.4 ms. Net
 * loss on the page, so it is deliberately absent. No functional index on
 * LOWER(name) exists here either: MySQL 5.7/8 cannot index an expression
 * without a generated column, and adding one is not worth it while the whole
 * surface sits at ~53 ms against a 1,000 ms threshold.
 *
 * WRITE COST, measured rather than asserted (10 batches of 500 single-row
 * inserts inside a rolled-back transaction, median batch, all other
 * machine_inventory_items indexes present in both configurations):
 *
 *   without the index   97.9 us per inventory insert
 *   with the index     100.8 us per inventory insert   (+2.9 us, +3%)
 *
 * That is cheap for this table specifically: inventory is written by the
 * desktop agent's inventory report, which is one bulk upsert per machine per
 * collection cycle (hours apart) — not the screenshot/heartbeat path where the
 * agent's write volume actually lives.
 *
 * PRODUCTION NOTE: on a large tenant apply out of band as
 * `ALTER TABLE machine_inventory_items ADD INDEX …, ALGORITHM=INPLACE,
 * LOCK=NONE` so the agent's inventory ingest keeps writing during the deploy.
 * This migration is idempotent and will then no-op.
 *
 * Idempotent + driver-safe: guarded by hasTable/hasColumn/hasIndex, no db-level
 * foreign keys, explicit short index name, SQLite + MySQL safe.
 */
return new class extends Migration
{
    private const TABLE = 'machine_inventory_items';

    private const NAME = 'mach_inv_org_present_user_idx';

    private const COLUMNS = ['organization_id', 'present', 'user_id'];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                return;
            }
        }

        if (Schema::hasIndex(self::TABLE, self::NAME)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->index(self::COLUMNS, self::NAME);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasIndex(self::TABLE, self::NAME)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropIndex(self::NAME);
        });
    }
};
