<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE covering index for the work-hours REPORT (`/report`), the slowest surface
 * in the Phase 6 benchmark.
 *
 * That page does not list rows — it lists MERGED rows. Every load runs the same
 * aggregate twice over the tenant's entire work_hours history:
 *
 *   rows   SELECT date, user_id, client_id, work_category_id, is_billable,
 *                 work_type, tracker, source, SUM(hours), COUNT(*), GROUP_CONCAT(…)
 *          GROUP BY those eight columns          (one page of the result)
 *   count  the number of GROUPS, for the paginator
 *
 * What already exists cannot serve it. work_hours_org_date_idx stops after
 * `date`, so the other seven grouping columns and `hours` are all row lookups —
 * the grouping degenerates into a full scan plus a temp B-tree. There is no
 * date predicate to narrow it either: the report is deliberately all-time
 * unless the user picks a range, so this scan grows for the life of the
 * workspace.
 *
 * (organization_id, <the eight grouping columns>, hours) is tenant-leading
 * (OrganizationScope always emits organization_id) and INDEX-ONLY for both
 * statements: every grouping key and the summed measure are inside the index,
 * so neither touches the table, and the leading (organization_id, date) prefix
 * also means the ORDER BY date DESC arrives pre-sorted.
 *
 * MEASURED on the synthetic 120-person workspace (27,514 work entries, SQLite):
 *   count statement   62.4 ms -> 8.4 ms   (same result, 27,514 groups)
 *   page statement    68.0 ms -> 37.3 ms
 *   whole /report surface (real HTTP request, median of 3)  190.2 ms -> 60.9 ms
 *
 * Not added, because they were measured and did NOT help — an index nobody
 * reads is pure write-path tax, and tracking_sessions in particular is on the
 * desktop tracker's heartbeat path:
 *   • tracking_sessions (organization_id, status, user_id) — the live-session
 *     lookups are already 0.10 ms via tracking_sessions_status_last_heartbeat_at_index.
 *   • tracking_sessions (organization_id, stopped_at) — the Team page's
 *     range-overlap read was 7.21 ms before and 7.10 ms after: the predicate is
 *     an OR across two columns and no single index serves it.
 *   • work_hours (organization_id, client_id) — the report's client dropdown is
 *     already 0.18 ms once it is an EXISTS (see WorkHourController::report);
 *     work_hours_client_id_index is enough.
 *   • users (organization_id, is_active, name) — 0.17 ms either way; the table
 *     is small by construction.
 *
 * COST, stated plainly: this is a nine-column index on one of the two
 * fastest-growing tables. It roughly doubles work_hours' index footprint and
 * adds one B-tree write per work entry. Work entries are written by hand or
 * once per tracker session sync — orders of magnitude below the screenshot and
 * activity-sample rates — so the trade is worth it for a page that is otherwise
 * unbounded. If that ever changes, the fix is a default date window on the
 * report, not this index.
 *
 * PRODUCTION NOTE: on a large tenant apply out of band as
 * `ALTER TABLE work_hours ADD INDEX …, ALGORITHM=INPLACE, LOCK=NONE` (or via
 * gh-ost) so the tracker mirror keeps writing during the deploy. This migration
 * is idempotent and will then no-op.
 *
 * Idempotent + driver-safe: guarded by hasTable/hasColumn/hasIndex, no db-level
 * foreign keys, explicit short index name.
 */
return new class extends Migration
{
    private const TABLE = 'work_hours';

    private const NAME = 'work_hours_org_report_group_idx';

    private const COLUMNS = [
        'organization_id',
        'date',
        'user_id',
        'client_id',
        'work_category_id',
        'is_billable',
        'work_type',
        'tracker',
        'source',
        'hours',
    ];

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
