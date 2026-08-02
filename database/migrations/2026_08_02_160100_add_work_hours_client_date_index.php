<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Clients list aggregates this week's hours per client. work_hours already
 * has (user_id, date) — nothing serves a client-keyed date range, so every
 * Clients page render scanned the tenant's whole work_hours history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_hours') || ! Schema::hasColumn('work_hours', 'client_id')) {
            return;
        }

        if (! Schema::hasIndex('work_hours', 'work_hours_client_id_date_index')) {
            Schema::table('work_hours', function (Blueprint $table) {
                $table->index(['client_id', 'date'], 'work_hours_client_id_date_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('work_hours') && Schema::hasIndex('work_hours', 'work_hours_client_id_date_index')) {
            Schema::table('work_hours', function (Blueprint $table) {
                $table->dropIndex('work_hours_client_id_date_index');
            });
        }
    }
};
