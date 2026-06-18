<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "tracker_manual" is a client-level Upwork engagement type, not a
        // valid per-entry billing category. Existing tracked entries default
        // to "tracker" so they edit cleanly in Work Diary and Report.
        if (Schema::hasColumn('work_hours', 'work_type')) {
            DB::table('work_hours')
                ->where('work_type', 'tracker_manual')
                ->update(['work_type' => 'tracker']);
        }

        if (Schema::hasTable('tracking_sessions') && Schema::hasColumn('tracking_sessions', 'work_type')) {
            DB::table('tracking_sessions')
                ->where('work_type', 'tracker_manual')
                ->update(['work_type' => 'tracker']);
        }
    }

    public function down(): void
    {
        // No-op: we do not restore the ambiguous client-level value onto
        // per-entry rows.
    }
};
