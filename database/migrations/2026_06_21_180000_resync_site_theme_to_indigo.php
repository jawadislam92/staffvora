<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reset the pre-launch auto-seeded site_theme chrome so config/brand.php +
 * config/navigation.php are the DEFAULT for the public site, and the builder
 * only overrides what the owner explicitly sets.
 *
 * The singleton row still carried early-dev values: a green/placeholder accent,
 * a flat anchor-style nav ([Features /#features] …), generic footer columns and
 * a stale contact email (hello@tracksuite.io). Now that the public site lets the
 * builder override colours/nav/footer/contact, those stale values would surface
 * and regress the live site. Clear them — guarded so a REAL owner choice (a real
 * contact email saved in the builder) is never wiped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('site_themes')->where('id', 1)->first();
        if (! $row) {
            return; // created lazily with config defaults later
        }

        // The dev seed left contact_email = hello@tracksuite.io; if that's still
        // the value (or empty) the owner hasn't customised the chrome yet.
        $untouched = in_array(strtolower((string) $row->contact_email), ['', 'hello@tracksuite.io'], true);
        if (! $untouched) {
            return;
        }

        DB::table('site_themes')->where('id', 1)->update([
            'accent_color' => config('brand.colors.accent', '#4F46E5'),
            'highlight_color' => config('brand.colors.highlight', '#A78BFA'),
            'tagline' => null,
            'nav_links' => null,
            'footer_columns' => null,
            'social_links' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'contact_address' => null,
            'updated_at' => now(),
        ]);

        Cache::forget('site_theme');
    }

    public function down(): void
    {
        // no-op — don't revert a brand choice.
    }
};
