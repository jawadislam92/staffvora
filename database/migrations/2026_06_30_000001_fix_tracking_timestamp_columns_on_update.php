<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * GUARDRAIL — port of SA Track's `2026_06_27_000002` fix, extended to every
     * affected table.
     *
     * These columns were created as MySQL TIMESTAMP. On a MySQL/MariaDB server
     * running with `explicit_defaults_for_timestamp = OFF`, the first non-null
     * TIMESTAMP column in a table implicitly gets DEFAULT CURRENT_TIMESTAMP
     * ON UPDATE CURRENT_TIMESTAMP. That made ANY row UPDATE silently rewrite the
     * instant to now()-in-UTC — the bug that caused the "5-hour re-save
     * corruption" in SA Track (clock-ins shifted, auto-close fired ~5h early).
     *
     * Convert each to a plain DATETIME: no ON UPDATE, no implicit default, no
     * server-side timezone conversion. Values are preserved (MySQL uses the same
     * session timezone the app already reads with) — only the auto-rewrite
     * behaviour is removed. MySQL-only: SQLite/Postgres have no such implicit
     * ON UPDATE, so this is a no-op there.
     *
     * Columns = the non-nullable, first-in-table TIMESTAMP instants (the actual
     * ON UPDATE vectors). Nullable sibling columns do not get the implicit
     * ON UPDATE and are handled in the later UTC-storage migration.
     */
    private array $columns = [
        'time_entries' => 'action_timestamp',
        'tracking_sessions' => 'started_at',
        'tracking_screenshots' => 'captured_at',
        'tracking_activity_samples' => 'captured_at',
        'manual_attendance_audits' => 'changed_at',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->columns as $table => $column) {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} DATETIME NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->columns as $table => $column) {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }
};
