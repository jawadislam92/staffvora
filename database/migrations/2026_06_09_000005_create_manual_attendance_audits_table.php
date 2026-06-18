<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manual_attendance_audits')) {
            return;
        }

        Schema::create('manual_attendance_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('old_status_code', 10)->nullable();
            $table->string('new_status_code', 10)->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['attendance_date', 'user_id']);
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_attendance_audits');
    }
};
