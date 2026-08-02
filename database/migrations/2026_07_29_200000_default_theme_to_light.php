<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-3 (owner decision): Staffvora defaults to the LIGHT theme.
 *
 * Dark and black stay available — every workspace and every person can still
 * switch, and a workspace that has deliberately chosen its own theme keeps it.
 * This only moves the *default* new workspaces start from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organizations', 'default_theme')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('default_theme', 16)->default('light')->change();
        });

        // Existing workspaces still sitting on the old built-in default move to
        // light too; anything explicitly set to black is left untouched.
        DB::table('organizations')->where('default_theme', 'dark')->update(['default_theme' => 'light']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('organizations', 'default_theme')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('default_theme', 16)->default('dark')->change();
        });
    }
};
