<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_hours', function (Blueprint $table) {
            if (! Schema::hasColumn('work_hours', 'tracking_session_id')) {
                $table->unsignedBigInteger('tracking_session_id')->nullable()->after('tracker');
                $table->index('tracking_session_id', 'work_hours_tracking_session_id_index');
            }
            if (! Schema::hasColumn('work_hours', 'source')) {
                $table->string('source', 16)->default('manual')->after('tracking_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_hours', function (Blueprint $table) {
            if (Schema::hasColumn('work_hours', 'tracking_session_id')) {
                $table->dropIndex('work_hours_tracking_session_id_index');
                $table->dropColumn('tracking_session_id');
            }
            if (Schema::hasColumn('work_hours', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
