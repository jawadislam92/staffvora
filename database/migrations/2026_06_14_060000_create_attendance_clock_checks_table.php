<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the "still working?" Slack check for one open clock-in: how many times
 * we've pinged the person, when, whether they confirmed (snooze), and how it
 * was finally resolved. One row per open clock-in TimeEntry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_clock_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('clock_in_id')->unique();
            $table->unsignedInteger('prompts_sent')->default(0);
            $table->timestamp('last_prompted_at')->nullable();
            $table->timestamp('confirmed_until')->nullable();   // "yes, still working" snooze
            $table->timestamp('last_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution')->nullable();           // clocked_out_via_slack | no_response | closed_elsewhere
            $table->timestamps();

            $table->index(['user_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_clock_checks');
    }
};
