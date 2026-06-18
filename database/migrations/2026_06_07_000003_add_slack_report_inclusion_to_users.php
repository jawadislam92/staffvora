<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'include_in_slack_reports')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('include_in_slack_reports')->default(true)->after('permissions');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'include_in_slack_reports')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('include_in_slack_reports');
            });
        }
    }
};
