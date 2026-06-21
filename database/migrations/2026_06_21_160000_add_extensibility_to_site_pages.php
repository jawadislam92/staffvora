<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extensibility columns for the marketing CMS (SITE_BUILD_SPEC §2.1 + §8).
 *
 * - status already exists (string) and now also carries `planned` — a reserved
 *   slot that stays out of nav/sitemap and 404s until set to `published`.
 * - show_in_nav lets a page opt into auto-generated nav (resolver in §2.3).
 * - canonical_url / robots give per-page SEO control (§8).
 *
 * og_image_path already exists on the table, so OG images need no new column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_pages', function (Blueprint $table) {
            $table->boolean('show_in_nav')->default(false)->after('status');
            $table->string('canonical_url')->nullable()->after('meta_description');
            $table->string('robots', 40)->nullable()->after('canonical_url'); // e.g. "index,follow"
        });
    }

    public function down(): void
    {
        Schema::table('site_pages', function (Blueprint $table) {
            $table->dropColumn(['show_in_nav', 'canonical_url', 'robots']);
        });
    }
};
