<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Testing override: capture a screenshot every 10 seconds and an activity
 * sample every 10 seconds so end-to-end behaviour can be verified quickly.
 *
 * When you're ready to use realistic intervals again, run this in tinker:
 *
 *   App\Models\MonitoringSetting::current()->update([
 *       'screenshot_interval_min_seconds' => 300,
 *       'screenshot_interval_max_seconds' => 600,
 *       'activity_sample_interval_seconds' => 60,
 *   ]);
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monitoring_settings')) {
            return;
        }

        DB::table('monitoring_settings')->where('id', 1)->update([
            'screenshot_interval_min_seconds' => 10,
            'screenshot_interval_max_seconds' => 10,
            'activity_sample_interval_seconds' => 10,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('monitoring_settings')) {
            return;
        }

        DB::table('monitoring_settings')->where('id', 1)->update([
            'screenshot_interval_min_seconds' => 300,
            'screenshot_interval_max_seconds' => 600,
            'activity_sample_interval_seconds' => 60,
            'updated_at' => now(),
        ]);
    }
};
