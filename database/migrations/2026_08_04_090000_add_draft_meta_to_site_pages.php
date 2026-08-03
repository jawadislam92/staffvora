<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the Site Builder's Draft → Publish split honest for the page title and
 * SEO fields.
 *
 * `title`, `meta_title` and `meta_description` are what SitePage::publicPayload
 * serves to anonymous visitors, and the builder's "Save draft" wrote straight
 * into them. Only `blocks`/`published_blocks` were snapshotted, so an
 * unpublished title or meta-description edit went live on its own as soon as
 * the six-hour page cache expired.
 *
 * The existing columns keep their meaning (LIVE values, so the public read path
 * is unchanged and needs no deploy ordering), and the working copy moves into
 * these `draft_*` columns, promoted by SiteBuilderController::publish().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_pages', function (Blueprint $table) {
            if (! Schema::hasColumn('site_pages', 'draft_title')) {
                $table->string('draft_title')->nullable()->after('title');
            }
            if (! Schema::hasColumn('site_pages', 'draft_meta_title')) {
                $table->string('draft_meta_title')->nullable()->after('meta_title');
            }
            if (! Schema::hasColumn('site_pages', 'draft_meta_description')) {
                $table->text('draft_meta_description')->nullable()->after('meta_description');
            }
        });

        // Seed the working copy from what is currently live, so opening the
        // builder after this migration shows the operator exactly what they had.
        DB::table('site_pages')->whereNull('draft_title')->update([
            'draft_title' => DB::raw('title'),
            'draft_meta_title' => DB::raw('meta_title'),
            'draft_meta_description' => DB::raw('meta_description'),
        ]);
    }

    public function down(): void
    {
        Schema::table('site_pages', function (Blueprint $table) {
            $table->dropColumn(['draft_title', 'draft_meta_title', 'draft_meta_description']);
        });
    }
};
