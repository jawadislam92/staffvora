<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Local/testing screenshot cadence.
 *
 * On a developer machine (or the test runner) we want a fast capture loop —
 * a screenshot and an activity sample every 10 seconds — so end-to-end
 * behaviour can be verified in seconds instead of minutes.
 *
 * That cadence must NEVER reach production: `php artisan migrate --force` is the
 * documented deploy step, and a 10-second interval would mean ~360 captures per
 * hour per user (a storage, bandwidth, S3-cost and privacy problem). So this
 * migration is environment-guarded:
 *
 *   - local / testing  -> 10 / 10 / 10   (fast dev loop, unchanged behaviour)
 *   - everything else  -> 300 / 600 / 60 (the sane values the create migration
 *                         already seeds; written here too so this is idempotent
 *                         and self-heals any row a prior unguarded run left at
 *                         10 seconds on a non-local environment)
 *
 * Either branch is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monitoring_settings')) {
            return;
        }

        $fast = app()->environment(['local', 'testing']);

        DB::table('monitoring_settings')->where('id', 1)->update([
            'screenshot_interval_min_seconds' => $fast ? 10 : 300,
            'screenshot_interval_max_seconds' => $fast ? 10 : 600,
            'activity_sample_interval_seconds' => $fast ? 10 : 60,
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
