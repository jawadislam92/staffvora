<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5B — richer plan entitlements + per-tenant platform control.
 *
 * - plans.entitlements: the full caps/flags map the platform edits per plan
 *   (replaces the thin boolean `features` map; `features` is kept for back-compat
 *   reads during the transition).
 * - organizations.entitlement_overrides: per-tenant grants/limits that the
 *   platform sets for a single customer (the "contact us for a bit more" case).
 *   Effective entitlement = plan default merged with this override.
 * - organizations.custom_price_cents: a per-tenant negotiated price that wins
 *   over the plan's list price when set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('entitlements')->nullable()->after('features');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('entitlement_overrides')->nullable()->after('trial_ends_at');
            $table->unsignedInteger('custom_price_cents')->nullable()->after('entitlement_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('entitlements');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['entitlement_overrides', 'custom_price_cents']);
        });
    }
};
