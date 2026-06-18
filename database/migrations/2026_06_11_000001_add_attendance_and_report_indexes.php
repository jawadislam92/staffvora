<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * time_entries had no date index at all, so the attendance monthly grid
     * (whereBetween on action_date for the whole team) was a full table scan
     * on a table that grows with every clock action. The work_hours composite
     * covers the dashboard/report per-user date-range aggregates.
     */
    public function up(): void
    {
        $this->addIndexIfMissing('time_entries', ['action_date'], 'time_entries_action_date_index');
        $this->addIndexIfMissing('time_entries', ['user_id', 'action_date'], 'time_entries_user_id_action_date_index');
        $this->addIndexIfMissing('work_hours', ['user_id', 'date'], 'work_hours_user_id_date_index');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('time_entries', 'time_entries_action_date_index');
        $this->dropIndexIfExists('time_entries', 'time_entries_user_id_action_date_index');
        $this->dropIndexIfExists('work_hours', 'work_hours_user_id_date_index');
    }

    private function addIndexIfMissing(string $table, array $columns, string $name): void
    {
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->index($columns, $name);
            });
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (Schema::hasIndex($table, $name)) {
            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        }
    }
};
