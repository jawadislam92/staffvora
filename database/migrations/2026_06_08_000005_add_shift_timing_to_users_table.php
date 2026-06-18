<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'shift_start_time')) {
                $table->time('shift_start_time')->nullable()->after('designation');
            }

            if (! Schema::hasColumn('users', 'shift_grace_minutes')) {
                $table->unsignedSmallInteger('shift_grace_minutes')->default(15)->after('shift_start_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'shift_grace_minutes')) {
                $table->dropColumn('shift_grace_minutes');
            }

            if (Schema::hasColumn('users', 'shift_start_time')) {
                $table->dropColumn('shift_start_time');
            }
        });
    }
};
