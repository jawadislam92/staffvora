<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            $table->string('branding_logo_path')->nullable()->after('currency_symbol');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table) {
            $table->dropColumn('branding_logo_path');
        });
    }
};
