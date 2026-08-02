<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs and indexes the highest-volume table in the product.
 *
 * WHY: desktop activity samples are written with a mass `insert()`, which fires
 * no model events, so BelongsToOrganization's `creating` stamp never ran and
 * every row landed with `organization_id = NULL`. Because OrganizationScope
 * appends `where organization_id = ?`, NULL never matches: the whole dataset is
 * invisible to the tenant that produced it (Timeline apps/URLs, Team activity,
 * the Slack digest), the per-session purge and tenant deletion delete nothing,
 * and — most importantly for retention — a scoped pruner would report "0 pruned"
 * forever while the table grew without bound. Stamping is therefore a
 * PREREQUISITE for retention working at all, not a cosmetic fix.
 *
 * WHAT IT DOES:
 *   1. Backfills organization_id from `users` (reliably populated by
 *      TenantProvisioner), in bounded id ranges so a table with hundreds of
 *      millions of rows never takes one giant lock and stalls the tracker API.
 *   2. Second pass from `tracking_sessions` for anything still NULL.
 *   3. Adds the composite indexes every org-scoped read now needs. The table
 *      previously had no index with a leading organization_id, so post-stamp
 *      reads would otherwise degrade into per-tenant scans.
 *   4. Adds (user_id, action_timestamp, id) on time_entries, which the
 *      attendance safety-net commands sort on every ten minutes.
 *
 * The correlated-subquery UPDATE form is used deliberately: the `UPDATE ... JOIN`
 * form is MySQL-only and would fail on the local SQLite database. organization_id
 * is intentionally left NULLABLE — guest/login, console, seeders and the test
 * factories all legitimately produce unstamped rows, and NOT NULL would break
 * `migrate` on any database with pre-existing rows.
 */
return new class extends Migration
{
    /** Rows repaired per statement. Small enough to keep InnoDB locks short. */
    private const BACKFILL_CHUNK = 20000;

    public function up(): void
    {
        if (Schema::hasTable('tracking_activity_samples') && Schema::hasColumn('tracking_activity_samples', 'organization_id')) {
            if (Schema::hasTable('users')) {
                $this->backfillFrom('users', 'user_id');
            }

            if (Schema::hasTable('tracking_sessions') && Schema::hasColumn('tracking_sessions', 'organization_id')) {
                $this->backfillFrom('tracking_sessions', 'tracking_session_id');
            }

            $this->addIndex('tracking_activity_samples', ['organization_id', 'captured_at'], 'tas_org_captured_idx');
            $this->addIndex('tracking_activity_samples', ['organization_id', 'user_id', 'captured_at'], 'tas_org_user_captured_idx');
        }

        // The forgotten-clock-out and "still working?" commands both resolve the
        // latest time entry per user, ordered by action_timestamp. Without this
        // the ordering is a filesort on every pass, for every user, every ten
        // minutes. user_id already implies the organization, so a leading
        // organization_id here would only make the index unusable for the lookup.
        if (Schema::hasTable('time_entries') && Schema::hasColumn('time_entries', 'action_timestamp')) {
            $this->addIndex('time_entries', ['user_id', 'action_timestamp', 'id'], 'time_entries_user_action_ts_idx');
        }
    }

    public function down(): void
    {
        // The backfill is a data repair and is deliberately not reversed.
        $this->dropIndex('tracking_activity_samples', 'tas_org_captured_idx');
        $this->dropIndex('tracking_activity_samples', 'tas_org_user_captured_idx');
        $this->dropIndex('time_entries', 'time_entries_user_action_ts_idx');
    }

    /**
     * Copy organization_id onto unstamped samples from a parent table, walking
     * the primary key in bounded ranges.
     */
    private function backfillFrom(string $parentTable, string $localKey): void
    {
        $maxId = (int) DB::table('tracking_activity_samples')->max('id');

        if ($maxId <= 0) {
            return;
        }

        $subQuery = sprintf(
            '(select p.organization_id from %s as p where p.id = tracking_activity_samples.%s)',
            $parentTable,
            $localKey
        );

        for ($from = 1; $from <= $maxId; $from += self::BACKFILL_CHUNK) {
            DB::table('tracking_activity_samples')
                ->whereBetween('id', [$from, $from + self::BACKFILL_CHUNK - 1])
                ->whereNull('organization_id')
                ->whereNotNull($localKey)
                ->update(['organization_id' => DB::raw($subQuery)]);
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndex(string $table, array $columns, string $name): void
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        if (Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name) {
            $blueprint->dropIndex($name);
        });
    }
};
