<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user override for concurrent multi-device tracking. Off by default: a
 * user may track on only ONE device at a time (starting on a second device
 * stops the first). Super-admins flip this on for the rare person who genuinely
 * needs two machines tracking at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('allow_multiple_devices')->default(false)->after('shift_grace_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('allow_multiple_devices');
        });
    }
};
