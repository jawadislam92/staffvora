<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The public site lets the builder's accent OVERRIDE config when it differs from
 * the config default. Earlier migrations *set* the site_theme accent to whatever
 * config was at the time (orange → green → indigo), so every time config moves to
 * a new brand the old value would hijack the site. Fix the model: accent/highlight
 * are now NULLABLE and default to NULL — meaning "use config" — unless the owner
 * explicitly picks one in the builder. Null only the known auto-set values so a
 * real owner choice (any other hex) is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_themes', function (Blueprint $table) {
            $table->string('accent_color', 9)->nullable()->default(null)->change();
            $table->string('highlight_color', 9)->nullable()->default(null)->change();
        });

        $row = DB::table('site_themes')->where('id', 1)->first();
        if (! $row) {
            return;
        }

        $auto = [null, '', '#f97316', '#f59e0b', '#4f9e78', '#86c9a4', '#4f46e5', '#a78bfa'];
        $update = [];
        if (in_array(strtolower((string) $row->accent_color), $auto, true)) {
            $update['accent_color'] = null;
        }
        if (in_array(strtolower((string) $row->highlight_color), $auto, true)) {
            $update['highlight_color'] = null;
        }

        if ($update) {
            DB::table('site_themes')->where('id', 1)->update($update + ['updated_at' => now()]);
            Cache::forget('site_theme');
        }
    }

    public function down(): void
    {
        // no-op
    }
};
