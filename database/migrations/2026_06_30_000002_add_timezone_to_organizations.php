<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The workspace's BUSINESS timezone — the zone in which this organization's
     * attendance days, shift times, auto-clock-out and "today" are measured. It
     * is distinct from:
     *   - storage (now UTC, neutral), and
     *   - the per-viewer DISPLAY timezone (user_monitoring_settings.display_timezone),
     *     which only affects how times are RENDERED for a given viewer.
     *
     * Defaults to 'Asia/Karachi' so every existing (Sparking-Asia-origin) and
     * test workspace keeps its current day-bucketing byte-for-byte until an owner
     * sets their own zone. New workspaces pick this at signup.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'timezone')) {
                $table->string('timezone', 64)->default('Asia/Karachi')->after('default_theme');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};
