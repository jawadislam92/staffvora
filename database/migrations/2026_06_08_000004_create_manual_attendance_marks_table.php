<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manual_attendance_marks')) {
            return;
        }

        Schema::create('manual_attendance_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('attendance_date');
            $table->string('status_code', 10);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'attendance_date']);
            $table->index(['attendance_date', 'status_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_attendance_marks');
    }
};
