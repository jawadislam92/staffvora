<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual time entered as a start–end range (e.g. 2:08–3:05am) instead of only a
 * duration. started_at/ended_at are wall-clock datetimes in the org's display
 * timezone; `hours` stays the authoritative total so every existing report,
 * export and rollup is unaffected. Old duration-only rows keep null endpoints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_hours', function (Blueprint $table) {
            $table->dateTime('started_at')->nullable()->after('date');
            $table->dateTime('ended_at')->nullable()->after('started_at');
        });

        Schema::table('monitoring_settings', function (Blueprint $table) {
            // How a manual range that overlaps another entry is handled:
            // warn (allow + flash), block (reject), or allow (silent).
            $table->string('manual_time_overlap_policy', 8)->default('warn')->after('allow_offline_time');
        });
    }

    public function down(): void
    {
        Schema::table('work_hours', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'ended_at']);
        });
        Schema::table('monitoring_settings', function (Blueprint $table) {
            $table->dropColumn('manual_time_overlap_policy');
        });
    }
};
