<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tracking_audit_logs')) {
            return;
        }

        Schema::create('tracking_audit_logs', function (Blueprint $table) {
            $table->id();

            // Polymorphic-ish: the entity affected. Either a session or a
            // screenshot; null for day-level events tied only to a user/date.
            $table->unsignedBigInteger('tracking_session_id')->nullable();
            $table->unsignedBigInteger('tracking_screenshot_id')->nullable();

            // The employee whose data was affected.
            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();

            // The person who performed the change (nullable for system events).
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 64); // e.g. screenshot.flag, screenshot.delete, session.note_edit
            $table->date('event_date')->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('reason', 500)->nullable();

            $table->timestamps();

            $table->index('tracking_session_id');
            $table->index('tracking_screenshot_id');
            $table->index(['subject_user_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_audit_logs');
    }
};
