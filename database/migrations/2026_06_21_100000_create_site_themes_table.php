<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global theme + chrome for the public marketing website (landlord-owned, NOT
 * tenant-scoped). One singleton row drives the header, footer, colours, socials
 * and contact details across every public page, all editable from the page
 * builder in the Platform console — no code change required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_themes', function (Blueprint $table) {
            $table->id();
            $table->string('site_name')->default('Staffvora');
            $table->string('tagline')->nullable();
            $table->string('logo_path')->nullable();      // S3 (avatars disk)
            $table->string('favicon_path')->nullable();
            $table->string('accent_color', 9)->default('#f97316');
            $table->string('highlight_color', 9)->default('#f59e0b');
            $table->json('nav_links')->nullable();         // [{label,url}]
            $table->json('footer_columns')->nullable();    // [{title,links:[{label,url}]}]
            $table->json('social_links')->nullable();      // {linkedin,facebook,instagram,x,youtube}
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_address')->nullable();
            $table->json('default_meta')->nullable();       // {title,description,og_image_path}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_themes');
    }
};
