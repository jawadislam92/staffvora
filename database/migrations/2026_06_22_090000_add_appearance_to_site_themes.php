<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No-code Appearance: the owner picks a colour preset, fine-tunes individual
 * colours, and chooses a font from the builder — no code. These drive the public
 * site's palette (light + dark) and typography, resolved server-side on top of
 * config/brand.php defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_themes', function (Blueprint $table) {
            $table->string('palette_preset')->nullable()->after('highlight_color'); // e.g. aurora|electric|sunset|custom
            $table->json('palette')->nullable()->after('palette_preset');            // { light: {...}, dark: {...} } custom overrides
            $table->string('font')->nullable()->after('palette');                    // Google font family name
        });
    }

    public function down(): void
    {
        Schema::table('site_themes', function (Blueprint $table) {
            $table->dropColumn(['palette_preset', 'palette', 'font']);
        });
    }
};
