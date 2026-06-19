<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org Slack connection. Each workspace connects its OWN Slack bot token
 * instead of sharing one platform-wide credential. Stored encrypted (text,
 * since ciphertext is long); resolved per-tenant by SlackBotService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('monitoring_settings', 'slack_bot_token')) {
                $table->text('slack_bot_token')->nullable()->after('slack_attendance_channel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            if (Schema::hasColumn('monitoring_settings', 'slack_bot_token')) {
                $table->dropColumn('slack_bot_token');
            }
        });
    }
};
