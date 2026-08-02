<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive / deactivate lifecycle for users, clients and profiles.
 *
 * users.is_active     — a DEACTIVATED user (someone who left) can no longer sign
 *                       in to the web app or the desktop tracker, their desktop
 *                       tokens are revoked, and they drop out of active
 *                       directory / assignment / performance views — but every
 *                       bit of their history (attendance, work hours, reports)
 *                       is preserved. Reversible.
 * clients.is_active   — an ARCHIVED client (finished contract) disappears from
 *                       the pickers used to log NEW work, while all historical
 *                       hours, reports and timeline entries keep showing.
 * upwork_profiles.is_active
 *                     — an archived profile stops appearing in dropdowns.
 *
 * All three default to TRUE so every existing row stays active. Column names /
 * types mirror the single-tenant source app exactly so future ports line up.
 * The columns live on already-tenant-scoped tables (they carry organization_id
 * and the OrganizationScope), so nothing tenancy-specific is added here.
 *
 * Written column-by-column with hasColumn() guards: the fork already ships
 * upwork_profiles.is_active from its create migration, and guarding keeps the
 * migration idempotent and safe on both SQLite (local) and MySQL (prod).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_active')->default(true);
            });
        }

        if (! Schema::hasColumn('clients', 'is_active')) {
            Schema::table('clients', function (Blueprint $table) {
                // Indexed (as in the source) because every "active clients"
                // picker filters on it.
                $table->boolean('is_active')->default(true)->index();
            });
        }

        if (! Schema::hasColumn('upwork_profiles', 'is_active')) {
            Schema::table('upwork_profiles', function (Blueprint $table) {
                $table->boolean('is_active')->default(true);
            });
        }
    }

    public function down(): void
    {
        // upwork_profiles.is_active predates this migration (it ships with the
        // create-table migration), so it is intentionally NOT dropped here.
        if (Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }

        if (Schema::hasColumn('clients', 'is_active')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
