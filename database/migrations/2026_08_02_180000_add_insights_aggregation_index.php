<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE covering index for the Insights read path.
 *
 * Insights runs three org-wide aggregates over a window of up to 92 days of
 * `work_hours` on EVERY page load:
 *
 *   trend     SELECT date, SUM(hours)                       … GROUP BY date
 *   category  SELECT work_category_id, SUM(hours), SUM(CASE WHEN is_billable …)
 *                                                           … GROUP BY work_category_id
 *   billable  the is_billable split of the same window
 *
 * What already exists does not serve them:
 *   • work_hours_org_date_idx (organization_id, date) finds the right ROWS, but
 *     `hours`, `is_billable` and `work_category_id` all live in the row, so a
 *     quarter of a 100-person workspace is ~100k random primary-key lookups.
 *   • work_hours_org_category_idx (organization_id, work_category_id) carries no
 *     date at all, so it cannot serve a range at all — the category rollup had
 *     no usable path before this.
 *
 * (organization_id, date, work_category_id, is_billable, hours) is tenant-
 * leading (OrganizationScope always emits organization_id), range-scannable on
 * the date window, and INDEX-ONLY for all three aggregates: the grouping keys
 * and the summed measure are both inside the index, so none of them touches the
 * table. The per-person rollup keeps using the existing
 * work_hours_org_user_date_idx.
 *
 * Not added deliberately:
 *   • nothing on tracking_sessions — (organization_id, started_at) and
 *     (organization_id, user_id, started_at) already cover the activity
 *     aggregates, and that table is on the tracker's write path where an extra
 *     index costs every heartbeat.
 *   • nothing on shift_overrides — it holds at most one row per person per day
 *     and only the rare exception days exist at all; its
 *     (user_id, date) unique is sufficient.
 *
 * PRODUCTION NOTE: on a large tenant apply this out of band as
 * `ALTER TABLE work_hours ADD INDEX … , ALGORITHM=INPLACE, LOCK=NONE` (or via
 * gh-ost) so the tracker mirror keeps writing during the deploy. This migration
 * is idempotent and will then no-op.
 *
 * Idempotent + driver-safe: guarded by hasTable/hasColumn/hasIndex, no
 * db-level foreign keys, explicit short index name.
 */
return new class extends Migration
{
    private const TABLE = 'work_hours';

    private const NAME = 'work_hours_org_insights_idx';

    private const COLUMNS = ['organization_id', 'date', 'work_category_id', 'is_billable', 'hours'];

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
