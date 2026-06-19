<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theme support: a workspace default (chosen at onboarding) and a per-user
 * override. Values: light | dark | black. Null user = inherit the workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'default_theme')) {
                $table->string('default_theme', 16)->default('dark')->after('accent_color');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'theme')) {
                $table->string('theme', 16)->nullable()->after('is_platform_admin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'default_theme')) {
                $table->dropColumn('default_theme');
            }
        });
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'theme')) {
                $table->dropColumn('theme');
            }
        });
    }
};
