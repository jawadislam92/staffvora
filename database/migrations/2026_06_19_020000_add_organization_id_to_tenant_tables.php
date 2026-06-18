<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds organization_id to every tenant-owned table. Nullable so existing rows
 * and guest/console contexts keep working during the transition; the global
 * OrganizationScope only filters when a tenant is bound. Column + index only
 * (no DB-level FK) to stay portable across SQLite (local) and MySQL/Postgres
 * (production); the application enforces the relationship.
 */
return new class extends Migration
{
    private array $tables = [
        'users',
        'clients',
        'work_hours',
        'time_entries',
        'tracking_sessions',
        'tracking_screenshots',
        'tracking_activity_samples',
        'tracking_audit_logs',
        'upwork_profiles',
        'designations',
        'manual_attendance_marks',
        'manual_attendance_audits',
        'attendance_clock_checks',
        'monitoring_settings',
        'user_monitoring_settings',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'organization_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->unsignedBigInteger('organization_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'organization_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('organization_id');
                });
            }
        }
    }
};
