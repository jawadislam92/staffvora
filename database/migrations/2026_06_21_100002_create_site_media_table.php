<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Images uploaded in the page builder (hero shots, screenshots, customer logos,
 * testimonial avatars). Stored on the S3-backed `avatars` disk so deploy
 * pruning can't wipe them; served by id through a cached public route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_media', function (Blueprint $table) {
            $table->id();
            $table->string('path');                 // S3 path on the avatars disk
            $table->string('mime', 64)->nullable();
            $table->string('original_name')->nullable();
            $table->string('alt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_media');
    }
};
