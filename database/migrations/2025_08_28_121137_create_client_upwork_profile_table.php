<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('client_upwork_profile')) {
            return;
        }

        Schema::create('client_upwork_profile', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('upwork_profile_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            // Ensure a client can't have the same profile multiple times
            $table->unique(['client_id', 'upwork_profile_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_upwork_profile');
    }
};
