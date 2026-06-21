<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator billing controls: a per-tenant percentage discount, and a per-plan
 * trial length (so new signups on a plan get that many trial days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedTinyInteger('discount_percent')->nullable()->after('custom_price_cents');
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->nullable()->after('max_users');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('discount_percent'));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('trial_days'));
    }
};
