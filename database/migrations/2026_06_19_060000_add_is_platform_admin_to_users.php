<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform (landlord) admin — the SaaS operator who manages every tenant
 * from the platform console. Distinct from a per-org super_admin (an org owner).
 * A platform admin still belongs to their own organization for normal use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_platform_admin')) {
                $table->boolean('is_platform_admin')->default(false)->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_platform_admin')) {
                $table->dropColumn('is_platform_admin');
            }
        });
    }
};
