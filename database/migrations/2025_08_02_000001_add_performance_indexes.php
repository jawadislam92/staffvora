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
        // Add indexes for better performance
        $this->addIndexIfNotExists('work_hours', 'user_id');
        $this->addIndexIfNotExists('work_hours', 'client_id');
        $this->addIndexIfNotExists('work_hours', 'date');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes if they exist
        $this->dropIndexIfExists('work_hours', 'work_hours_user_id_index');
        $this->dropIndexIfExists('work_hours', 'work_hours_client_id_index');
        $this->dropIndexIfExists('work_hours', 'work_hours_date_index');
    }

    private function addIndexIfNotExists($table, $column)
    {
        $indexName = $table.'_'.$column.'_index';
        if (! Schema::hasIndex($table, $indexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->index($column);
            });
        }
    }

    private function dropIndexIfExists($table, $indexName)
    {
        if (Schema::hasIndex($table, $indexName)) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropIndex($indexName);
            });
        }
    }
};
