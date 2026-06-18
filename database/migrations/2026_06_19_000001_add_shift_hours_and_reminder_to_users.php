<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person shift length and clock-out reminder threshold.
 *
 * - shift_hours: the expected length of this person's shift (e.g. 10h). Used to
 *   widen the forgotten-clock-out safety cap for genuinely long shifts so a
 *   10-hour person is not force-closed on the default 12h backstop.
 * - clockout_reminder_hours: when the Slack "still working?" nudge fires for
 *   this person (e.g. 8h). Falls back to the global ATTENDANCE_PROMPT_AFTER_HOURS
 *   when null.
 * Both nullable so existing users keep the current global behaviour untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'shift_hours')) {
                $table->decimal('shift_hours', 4, 2)->nullable()->after('shift_grace_minutes');
            }
            if (! Schema::hasColumn('users', 'clockout_reminder_hours')) {
                $table->decimal('clockout_reminder_hours', 4, 2)->nullable()->after('shift_hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['shift_hours', 'clockout_reminder_hours'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
