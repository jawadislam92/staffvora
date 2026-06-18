<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UI-managed Slack attendance notifications. Previously the clock-in post was
 * gated only by an env channel; these let an admin turn the clock-in and
 * clock-out posts on/off from the Settings page and point them at a channel,
 * without editing the server .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('monitoring_settings', 'slack_clockin_enabled')) {
                $table->boolean('slack_clockin_enabled')->default(false)->after('time_format');
            }
            if (! Schema::hasColumn('monitoring_settings', 'slack_clockout_enabled')) {
                $table->boolean('slack_clockout_enabled')->default(false)->after('slack_clockin_enabled');
            }
            if (! Schema::hasColumn('monitoring_settings', 'slack_attendance_channel')) {
                $table->string('slack_attendance_channel')->nullable()->after('slack_clockout_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            foreach (['slack_clockin_enabled', 'slack_clockout_enabled', 'slack_attendance_channel'] as $column) {
                if (Schema::hasColumn('monitoring_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
