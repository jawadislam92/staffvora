<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('tracking_sessions', 'health_alerted_at')) {
                // Set once the "tracker is live but capturing nothing" Slack
                // alert has fired for this session, so we alert at most once.
                $table->timestamp('health_alerted_at')->nullable()->after('last_heartbeat_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tracking_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('tracking_sessions', 'health_alerted_at')) {
                $table->dropColumn('health_alerted_at');
            }
        });
    }
};
