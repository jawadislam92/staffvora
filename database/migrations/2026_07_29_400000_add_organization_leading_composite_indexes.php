<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-leading composite indexes (audit finding high-21, high-16, high-15).
 *
 * OrganizationScope injects `where <table>.organization_id = ?` into EVERY read
 * on the tenant tables, but every pre-existing composite was designed before
 * tenancy and leads with the wrong column (action_date, (user_id, action_date),
 * (user_id, captured_at), (user_id, date), ...). MySQL picks ONE index per table
 * access, so it either scans every row the tenant owns or walks a date range
 * across ALL tenants before filtering. At 500 orgs sharing one instance the
 * second shape also thrashes the buffer pool for every other tenant.
 *
 * These composites give the optimizer a path that is tenant-partitioned FIRST
 * and range-scannable SECOND. They are shipped together with the whereDate()
 * removals in TimeEntryController / TeamController — without those the
 * predicates stay non-sargable and none of this is usable.
 *
 * Deliberate omissions (see the audit notes):
 *  - NO (organization_id, status, last_heartbeat_at) on tracking_sessions: its
 *    only consumer (CloseStaleTrackingSessions) runs unbound, where
 *    OrganizationScope emits no predicate at all, so an org-leading duplicate
 *    would never be chosen and would only cost writes on the hottest table.
 *  - The single-column organization_id indexes are KEPT. Several call sites
 *    (platform portal counts, TenantProvisioner, the per-tenant console runner)
 *    filter on organization_id alone; dropping those would turn a ref lookup
 *    into a scan. Retire them only after per-table verification.
 *
 * PRODUCTION NOTE: on a multi-million-row InnoDB table, run these as
 * `ALTER TABLE ... ADD INDEX ..., ALGORITHM=INPLACE, LOCK=NONE` (or via
 * pt-online-schema-change / gh-ost) rather than during the deploy's migrate
 * step, so the desktop tracker's heartbeat API keeps writing. Laravel's
 * Blueprint cannot express the ALGORITHM/LOCK hints, so on a large tenant
 * apply the equivalent DDL out of band first — this migration is idempotent
 * and will then no-op.
 *
 * Idempotent on both drivers: every step is guarded by hasTable + hasColumn +
 * hasIndex, so re-running (or running after an out-of-band apply) is a no-op.
 */
return new class extends Migration
{
    /**
     * table => [index name => columns]
     *
     * Every name is well under MySQL's 64-character identifier limit.
     */
    private function indexes(): array
    {
        return [
            // Dashboard trend (team viewer: no user filter), monthly attendance
            // grid, and every per-user attendance range read.
            'time_entries' => [
                'time_entries_org_action_date_idx' => ['organization_id', 'action_date'],
                'time_entries_org_user_action_date_idx' => ['organization_id', 'user_id', 'action_date'],
            ],

            // Work-diary aggregates: dashboard tracked-hours, team activity,
            // Slack reports, work-hour reports/exports.
            'work_hours' => [
                'work_hours_org_date_idx' => ['organization_id', 'date'],
                'work_hours_org_user_date_idx' => ['organization_id', 'user_id', 'date'],
            ],

            // Highest-volume table in the product (~1.4M rows/month per 100-user
            // org). Before this it had NO tenant-leading path of any kind, so the
            // org-wide app/URL rollups had no viable index.
            // (organization_id, captured_at, active_app) also serves the plain
            // (organization_id, captured_at) prefix, so that pair is not added
            // separately.
            'tracking_activity_samples' => [
                'tracking_activity_samples_org_captured_app_idx' => ['organization_id', 'captured_at', 'active_app'],
                'tracking_activity_samples_org_user_captured_idx' => ['organization_id', 'user_id', 'captured_at'],
            ],

            // Team page day snapshot (no user filter) + per-user session ranges.
            'tracking_sessions' => [
                'tracking_sessions_org_started_idx' => ['organization_id', 'started_at'],
                'tracking_sessions_org_user_started_idx' => ['organization_id', 'user_id', 'started_at'],
            ],

            // Screenshot gallery / timeline, always org + person + time window.
            'tracking_screenshots' => [
                'tracking_screenshots_org_user_captured_idx' => ['organization_id', 'user_id', 'captured_at'],
            ],

            // Manual attendance marks are read by month for the whole org.
            'manual_attendance_marks' => [
                'manual_attendance_marks_org_date_idx' => ['organization_id', 'attendance_date'],
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as $table => $definitions) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
                continue;
            }

            foreach ($definitions as $name => $columns) {
                $missing = collect($columns)->first(fn ($c) => ! Schema::hasColumn($table, $c));

                if ($missing !== null || Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                    $blueprint->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes() as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($definitions) as $name) {
                if (! Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($name) {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }
};
