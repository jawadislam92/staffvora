<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original team default of 1 minute pauses far too eagerly
        // (reading a doc for 60s freezes the timer). Bump to Scrin's 5-minute
        // default, but only when the row still holds the old default so an
        // intentional admin choice is preserved.
        if (Schema::hasTable('monitoring_settings')) {
            DB::table('monitoring_settings')
                ->where('id', 1)
                ->where('auto_pause_minutes', 1)
                ->update(['auto_pause_minutes' => 5]);
        }
    }

    public function down(): void
    {
        // No-op: we do not restore the overly aggressive default.
    }
};
