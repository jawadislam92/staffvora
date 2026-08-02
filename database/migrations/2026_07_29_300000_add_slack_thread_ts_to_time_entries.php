<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anchors the per-person, per-day attendance Slack THREAD.
     *
     * The day's first clock-in stores the Slack parent message `ts` here;
     * break-start, break-end and clock-out then look it up and post as replies,
     * so a person's whole day reads as one thread in the attendance channel
     * instead of four separate channel posts.
     * {@see \App\Services\AttendanceClockNotifier}
     *
     * Plain nullable string — no foreign key (target convention), and guarded on
     * both hasTable and hasColumn so it is idempotent on SQLite and MySQL alike.
     *
     * NOTE: back-filling this column issues an UPDATE on time_entries. The
     * implicit MySQL `ON UPDATE CURRENT_TIMESTAMP` that would otherwise rewrite
     * action_timestamp is already neutralised by
     * 2026_06_30_000001_fix_tracking_timestamp_columns_on_update, so the
     * "5-hour re-save corruption" cannot recur here.
     */
    public function up(): void
    {
        if (! Schema::hasTable('time_entries')) {
            return;
        }

        Schema::table('time_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('time_entries', 'slack_thread_ts')) {
                $table->string('slack_thread_ts')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('time_entries')) {
            return;
        }

        Schema::table('time_entries', function (Blueprint $table) {
            if (Schema::hasColumn('time_entries', 'slack_thread_ts')) {
                $table->dropColumn('slack_thread_ts');
            }
        });
    }
};
