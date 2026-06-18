<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('monitoring_settings', 'display_timezone')) {
                $table->string('display_timezone', 64)->default('Asia/Karachi')->after('week_starts_on');
            }
            if (! Schema::hasColumn('monitoring_settings', 'time_format')) {
                $table->string('time_format', 2)->default('12')->after('display_timezone');
            }
        });

        if (Schema::hasTable('user_monitoring_settings')) {
            Schema::table('user_monitoring_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('user_monitoring_settings', 'override_display')) {
                    $table->boolean('override_display')->default(false)->after('override_desktop_app');
                }
                if (! Schema::hasColumn('user_monitoring_settings', 'display_timezone')) {
                    $table->string('display_timezone', 64)->nullable();
                }
                if (! Schema::hasColumn('user_monitoring_settings', 'time_format')) {
                    $table->string('time_format', 2)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            foreach (['display_timezone', 'time_format'] as $col) {
                if (Schema::hasColumn('monitoring_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (Schema::hasTable('user_monitoring_settings')) {
            Schema::table('user_monitoring_settings', function (Blueprint $table) {
                foreach (['override_display', 'display_timezone', 'time_format'] as $col) {
                    if (Schema::hasColumn('user_monitoring_settings', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
