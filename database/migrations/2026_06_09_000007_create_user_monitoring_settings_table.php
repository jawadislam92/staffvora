<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_monitoring_settings')) {
            return;
        }

        Schema::create('user_monitoring_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Per-category override toggles. When false the team default applies.
            $table->boolean('override_screenshots')->default(false);
            $table->boolean('override_activity')->default(false);
            $table->boolean('override_app_url')->default(false);
            $table->boolean('override_weekly_limit')->default(false);
            $table->boolean('override_auto_pause')->default(false);
            $table->boolean('override_offline_time')->default(false);
            $table->boolean('override_notify_screenshot')->default(false);
            $table->boolean('override_desktop_app')->default(false);

            // Mirrored value columns. Nullable; only honoured when the matching
            // override flag is true.
            $table->unsignedTinyInteger('screenshots_per_hour')->nullable();
            $table->boolean('blur_screenshots')->nullable();
            $table->boolean('capture_enabled')->nullable();

            $table->boolean('activity_tracking_enabled')->nullable();
            $table->boolean('app_url_tracking_enabled')->nullable();

            $table->unsignedSmallInteger('weekly_time_limit_hours')->nullable();
            $table->unsignedSmallInteger('auto_pause_minutes')->nullable();

            $table->boolean('allow_offline_time')->nullable();
            $table->boolean('notify_on_screenshot')->nullable();

            $table->boolean('desktop_auto_start')->nullable();
            $table->boolean('desktop_force_quit_on_idle')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_monitoring_settings');
    }
};
