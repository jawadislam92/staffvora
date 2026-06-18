<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            // Screenshots-per-hour replaces the min/max seconds in the UI but
            // we keep the seconds columns for the desktop tracker.
            if (! Schema::hasColumn('monitoring_settings', 'screenshots_per_hour')) {
                $table->unsignedTinyInteger('screenshots_per_hour')->default(6)->after('id');
            }

            // Activity Level tracking (mouse + keyboard counts -> activity %).
            if (! Schema::hasColumn('monitoring_settings', 'activity_tracking_enabled')) {
                $table->boolean('activity_tracking_enabled')->default(true)->after('capture_enabled');
            }

            // App & URL tracking.
            if (! Schema::hasColumn('monitoring_settings', 'app_url_tracking_enabled')) {
                $table->boolean('app_url_tracking_enabled')->default(false)->after('activity_tracking_enabled');
            }

            // Weekly time limit (null = no limit).
            if (! Schema::hasColumn('monitoring_settings', 'weekly_time_limit_hours')) {
                $table->unsignedSmallInteger('weekly_time_limit_hours')->nullable()->after('app_url_tracking_enabled');
            }

            // Auto-pause after N minutes of inactivity.
            if (! Schema::hasColumn('monitoring_settings', 'auto_pause_minutes')) {
                $table->unsignedSmallInteger('auto_pause_minutes')->default(1)->after('weekly_time_limit_hours');
            }

            // Allow members to add offline / manual time.
            if (! Schema::hasColumn('monitoring_settings', 'allow_offline_time')) {
                $table->boolean('allow_offline_time')->default(false)->after('auto_pause_minutes');
            }

            // Notify user when a screenshot is taken.
            if (! Schema::hasColumn('monitoring_settings', 'notify_on_screenshot')) {
                $table->boolean('notify_on_screenshot')->default(false)->after('allow_offline_time');
            }

            // Week starts on.
            if (! Schema::hasColumn('monitoring_settings', 'week_starts_on')) {
                $table->string('week_starts_on', 10)->default('monday')->after('notify_on_screenshot');
            }

            // Currency symbol (cosmetic, used in cost columns later).
            if (! Schema::hasColumn('monitoring_settings', 'currency_symbol')) {
                $table->string('currency_symbol', 8)->default('$')->after('week_starts_on');
            }

            // Employee desktop application settings.
            if (! Schema::hasColumn('monitoring_settings', 'desktop_auto_start')) {
                $table->boolean('desktop_auto_start')->default(false)->after('currency_symbol');
            }
            if (! Schema::hasColumn('monitoring_settings', 'desktop_force_quit_on_idle')) {
                $table->boolean('desktop_force_quit_on_idle')->default(false)->after('desktop_auto_start');
            }
        });

        $row = DB::table('monitoring_settings')->where('id', 1)->first();
        if ($row) {
            $maxSeconds = max(60, (int) ($row->screenshot_interval_max_seconds ?? 600));
            DB::table('monitoring_settings')->where('id', 1)->update([
                'screenshots_per_hour' => max(1, (int) round(3600 / $maxSeconds)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            $columns = [
                'screenshots_per_hour',
                'activity_tracking_enabled',
                'app_url_tracking_enabled',
                'weekly_time_limit_hours',
                'auto_pause_minutes',
                'allow_offline_time',
                'notify_on_screenshot',
                'week_starts_on',
                'currency_symbol',
                'desktop_auto_start',
                'desktop_force_quit_on_idle',
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('monitoring_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
