<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REGION-BASED PRICING RULES (Phase 5, plan §5 — "grant/remove access by region").
 *
 * The operator decides, per region, WHICH public plans are offered and AT WHAT
 * PRICE. Nothing here touches entitlements — see the security note below.
 *
 *  1. `pricing_regions`       — the rule. A region is a NAMED GROUP of ISO-3166-1
 *                               alpha-2 country codes ("South Asia" = PK, IN, BD…)
 *                               or a single country, with its own currency, an
 *                               offer mode and a priority used to break ties when
 *                               two rules claim the same country.
 *  2. `pricing_region_plans`  — the per-plan statement inside a region: is this
 *                               plan offered here, and does it have a local price?
 *                               Money is stored in MINOR UNITS (integers) exactly
 *                               like `plans.price_cents`, and `currency` lives on
 *                               the region.
 *  3. `organizations.country` — the customer's own country claim, captured at
 *                               signup. It is a PRICING/VISIBILITY input only.
 *
 * ─── SECURITY: WHAT A REGION MAY AND MAY NOT DO ──────────────────────────────
 * The country a visitor claims is ATTACKER-CONTROLLED (a form field, or a proxy
 * header a client can forge). So a region rule is deliberately built so that
 * claiming a different country can never be worth anything except a different
 * PRICE LIST:
 *   • There is NO `entitlements` column here, by design. A region can never
 *     grant a capability — `Organization::can()` never reads this table.
 *   • A region may only SUBTRACT from, or RE-PRICE, the standard public plan
 *     catalogue. It can never ADD a hidden / custom / legacy / archived plan,
 *     so no region can surface a plan the operator has not published.
 *   • The resolved price is always re-derived server-side from these rows at
 *     checkout — a price is never taken from the request.
 *
 * ─── DEFAULT WHEN NOTHING MATCHES (documented, deliberate) ───────────────────
 * FAIL OPEN to the standard public catalogue. No region rule, an unknown
 * country, a blank country, an inactive/archived rule, or a rule that ends up
 * offering nothing at all → the visitor sees exactly what they see today. A
 * pricing rule must never leave a visitor with no plans to buy.
 *
 * Conventions honoured: no database-level foreign keys, every statement guarded
 * by hasTable()/hasColumn() so the migration is idempotent, SQLite (local) and
 * MySQL (prod) safe, and short explicit index names.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // 1. pricing_regions — the rule
        // ------------------------------------------------------------------
        if (! Schema::hasTable('pricing_regions')) {
            Schema::create('pricing_regions', function (Blueprint $table) {
                $table->id();
                $table->string('key', 40)->unique();       // stable slug, immutable once created
                $table->string('name', 80);                // "Pakistan", "South Asia"
                $table->text('description')->nullable();
                // ISO-3166-1 alpha-2, uppercase, e.g. ["PK","IN","BD"]. A rule
                // that names NO country matches nothing (it is inert, not global).
                $table->json('country_codes')->nullable();
                // The currency local prices in this region are quoted in.
                $table->string('currency', 3)->default('USD');
                // all_except  — every public plan is offered here EXCEPT the ones
                //               explicitly switched off (the safe default).
                // only_listed — only the plans explicitly switched ON are offered.
                $table->string('offer_mode', 20)->default('all_except');
                // Lower wins. Ties are broken by "fewer countries = more
                // specific", then by the older row — see App\Services\RegionPricing.
                $table->unsignedInteger('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                $table->index(['is_active', 'priority'], 'pricing_regions_active_idx');
            });
        }

        // ------------------------------------------------------------------
        // 2. pricing_region_plans — per-plan offer + local price
        // ------------------------------------------------------------------
        if (! Schema::hasTable('pricing_region_plans')) {
            Schema::create('pricing_region_plans', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pricing_region_id');
                $table->unsignedBigInteger('plan_id');
                $table->boolean('is_offered')->default(true);
                // NULL = "no local price, use the plan's own catalogue price".
                // 0 is NOT the same as NULL — 0 means genuinely free here.
                $table->unsignedInteger('price_cents')->nullable();          // per seat / month
                $table->unsignedInteger('price_cents_yearly')->nullable();   // per seat / year
                $table->timestamps();

                $table->unique(['pricing_region_id', 'plan_id'], 'region_plan_unique');
                $table->index('plan_id', 'region_plan_plan_idx');
            });
        }

        // ------------------------------------------------------------------
        // 3. organizations.country — the customer's country claim
        // ------------------------------------------------------------------
        if (! Schema::hasColumn('organizations', 'country')) {
            Schema::table('organizations', function (Blueprint $table) {
                // ISO-3166-1 alpha-2, uppercase. Nullable = "not stated", which
                // resolves to the standard public catalogue.
                $table->string('country', 2)->nullable()->after('timezone');
                $table->index('country', 'organizations_country_idx');
            });
        }

        // ------------------------------------------------------------------
        // 4. Seed the owner-approved PKR region (PLACEHOLDER PRICES).
        //    Straight from documents/STAFFVORA_PLANS_AND_ENTITLEMENTS.md Part 2:
        //      Free Rs 0 · Starter Rs 900 · Growth Rs 1,600 · Enterprise Rs 3,200
        //    The operator edits every number of this from the Regions page.
        // ------------------------------------------------------------------
        if (! DB::table('pricing_regions')->where('key', 'pakistan')->exists()) {
            $regionId = DB::table('pricing_regions')->insertGetId([
                'key' => 'pakistan',
                'name' => 'Pakistan',
                'description' => 'PKR-first local pricing, deliberately below the USD conversion.',
                'country_codes' => json_encode(['PK']),
                'currency' => 'PKR',
                'offer_mode' => 'all_except',
                'priority' => 10,
                'is_active' => true,
                'archived_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Keyed by plan KEY only because this is a one-off data seed; no
            // running code may ever branch on a plan key.
            $prices = [
                'free' => [0, 0],
                'starter' => [90000, 900000],       // Rs 900 / mo · Rs 9,000 / yr (10 months)
                'pro' => [160000, 1600000],         // "Growth" — Rs 1,600 / mo · Rs 16,000 / yr
                'enterprise' => [320000, null],     // from Rs 3,200 / mo · yearly negotiated
            ];

            foreach ($prices as $planKey => [$monthly, $yearly]) {
                $planId = DB::table('plans')->where('key', $planKey)->value('id');

                if (! $planId) {
                    continue; // catalogue edited — skip silently, never fail a migration
                }

                DB::table('pricing_region_plans')->insert([
                    'pricing_region_id' => $regionId,
                    'plan_id' => $planId,
                    'is_offered' => true,
                    'price_cents' => $monthly,
                    'price_cents_yearly' => $yearly,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_region_plans');
        Schema::dropIfExists('pricing_regions');

        if (Schema::hasColumn('organizations', 'country')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropIndex('organizations_country_idx');
                $table->dropColumn('country');
            });
        }
    }
};
