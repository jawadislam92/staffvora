<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public marketing pages, each composed of an ordered list of content blocks
 * built/edited in the Platform page builder. `blocks` is the working draft;
 * `published_blocks` is the live snapshot the public sees — Publish copies one
 * to the other so edits never leak before they're ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();              // home, pricing, features, ...
            $table->string('title');                       // admin label
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('og_image_path')->nullable();
            $table->string('status', 16)->default('draft'); // draft | published
            $table->json('blocks')->nullable();             // working draft
            $table->json('published_blocks')->nullable();   // live snapshot
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_pages');
    }
};
