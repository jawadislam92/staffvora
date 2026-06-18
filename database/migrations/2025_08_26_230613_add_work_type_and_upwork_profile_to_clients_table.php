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
        if (! Schema::hasColumn('clients', 'work_type')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('work_type')->nullable();
            });
        }

        if (! Schema::hasColumn('clients', 'upwork_profile_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->foreignId('upwork_profile_id')
                    ->nullable()
                    ->constrained('upwork_profiles')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('clients', 'upwork_profile_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropConstrainedForeignId('upwork_profile_id');
            });
        }

        if (Schema::hasColumn('clients', 'work_type')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('work_type');
            });
        }
    }
};
