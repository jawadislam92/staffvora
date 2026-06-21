<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator/landlord staff — a SEPARATE account type from customer `users`, with
 * its own auth guard. These accounts live only on the platform portal
 * (platform.satimetracker.com), are invite-only, and have no tenant. This is
 * what keeps platform access off the public customer signup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 16)->default('admin'); // owner | admin | support
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('platform_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('role', 16)->default('admin');
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invitations');
        Schema::dropIfExists('platform_users');
    }
};
