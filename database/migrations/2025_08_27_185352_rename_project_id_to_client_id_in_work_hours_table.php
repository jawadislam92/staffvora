<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_hours', function (Blueprint $table) {
            if (Schema::hasColumn('work_hours', 'project_id') && ! Schema::hasColumn('work_hours', 'client_id')) {
                $table->renameColumn('project_id', 'client_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_hours', function (Blueprint $table) {
            if (Schema::hasColumn('work_hours', 'client_id') && ! Schema::hasColumn('work_hours', 'project_id')) {
                $table->renameColumn('client_id', 'project_id');
            }
        });
    }
};
