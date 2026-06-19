<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5F — humane attendance policy, per org. Stops a fresh workspace from
 * branding everyone "Absent" for every past day:
 *  - tracking_start_date: days before it are "Not tracked", never Absent.
 *  - week_off_days: configurable weekly offs (not Sunday-only).
 *  - holidays: explicit public-holiday dates.
 *  - auto_absent: when off, a missing working day is a neutral "No record".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            $table->date('attendance_tracking_start_date')->nullable()->after('week_starts_on');
            $table->json('attendance_week_off_days')->nullable()->after('attendance_tracking_start_date');
            $table->json('attendance_holidays')->nullable()->after('attendance_week_off_days');
            $table->boolean('attendance_auto_absent')->default(true)->after('attendance_holidays');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            $table->dropColumn([
                'attendance_tracking_start_date',
                'attendance_week_off_days',
                'attendance_holidays',
                'attendance_auto_absent',
            ]);
        });
    }
};
