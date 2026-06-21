<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Point the marketing site_theme row at the finalised green "Measured System"
 * brand (the original row shipped with the orange placeholder default). Guarded
 * to the placeholder value so it never overwrites a real owner choice made in
 * the builder. config/brand.php stays the canonical source.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('site_themes')->where('id', 1)->first();
        if (! $row) {
            return; // created later with config defaults
        }
        if ($row->accent_color && strtolower($row->accent_color) !== '#f97316') {
            return; // owner already customised — leave it alone
        }

        DB::table('site_themes')->where('id', 1)->update([
            'site_name' => config('brand.name', 'Tracksuite'),
            'tagline' => config('brand.tagline'),
            'accent_color' => config('brand.colors.accent', '#4F9E78'),
            'highlight_color' => config('brand.colors.highlight', '#86C9A4'),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\Cache::forget('site_theme');
    }

    public function down(): void
    {
        // no-op (don't revert a brand choice)
    }
};
