<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated reset-token table for operator (platform guard) accounts. Kept
 * SEPARATE from the customer `password_reset_tokens` table (email is the PK
 * there) so an operator and a customer who happen to share an email address can
 * never overwrite each other's reset tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_password_reset_tokens');
    }
};
