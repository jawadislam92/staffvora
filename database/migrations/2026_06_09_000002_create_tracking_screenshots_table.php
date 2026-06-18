<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tracking_screenshots')) {
            return;
        }

        Schema::create('tracking_screenshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracking_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('captured_at');
            $table->string('image_path');
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedTinyInteger('activity_percent')->default(0);
            $table->unsignedSmallInteger('keyboard_count')->default(0);
            $table->unsignedSmallInteger('mouse_count')->default(0);
            $table->string('active_app')->nullable();
            $table->string('active_window_title')->nullable();
            $table->string('url_domain')->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->string('flag_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'captured_at']);
            $table->index(['tracking_session_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_screenshots');
    }
};
