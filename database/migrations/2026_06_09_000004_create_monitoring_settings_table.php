<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monitoring_settings')) {
            Schema::create('monitoring_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedSmallInteger('screenshot_interval_min_seconds')->default(300);  // 5 min
                $table->unsignedSmallInteger('screenshot_interval_max_seconds')->default(600);  // 10 min
                $table->unsignedSmallInteger('idle_threshold_seconds')->default(300);          // 5 min
                $table->unsignedSmallInteger('activity_sample_interval_seconds')->default(60); // 1 min
                $table->unsignedSmallInteger('retention_days')->default(60);
                $table->boolean('blur_screenshots')->default(false);
                $table->boolean('capture_enabled')->default(true);
                $table->boolean('require_active_window_metadata')->default(true);
                $table->timestamps();
            });

            DB::table('monitoring_settings')->insert([
                'screenshot_interval_min_seconds' => 300,
                'screenshot_interval_max_seconds' => 600,
                'idle_threshold_seconds' => 300,
                'activity_sample_interval_seconds' => 60,
                'retention_days' => 60,
                'blur_screenshots' => false,
                'capture_enabled' => true,
                'require_active_window_metadata' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_settings');
    }
};
