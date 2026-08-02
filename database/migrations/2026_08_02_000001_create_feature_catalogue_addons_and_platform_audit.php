<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plans & entitlements, phase 1 — THE DATA LAYER.
 *
 * Everything the operator must be able to change without a code deploy now
 * lives in tables:
 *
 *  1. `features`            — the editable feature CATALOGUE. Replaces the PHP
 *                             constant `App\Tenancy\Entitlements::REGISTRY` as
 *                             the source of truth for "which entitlement keys
 *                             exist, what kind are they, and what does the
 *                             default-deny value look like". Seeded with the 13
 *                             keys the constant shipped with (label/help/kind/
 *                             default reproduced EXACTLY so nothing changes
 *                             behaviour) plus three new ones:
 *                             `max_storage_gb`, `upwork_profiles`,
 *                             `machine_compliance`.
 *  2. `plans` (+ columns)   — yearly price, visibility lifecycle, archive, and
 *                             `is_default_trial` which replaces the hardcoded
 *                             `where('key','pro')` signup lookup. NOTHING may
 *                             branch on a plan KEY from here on.
 *  3. `addons`              — sellable bundles of entitlements that attach to a
 *                             company on top of its plan (the Agency toolkit).
 *  4. `organization_addon`  — which company bought which add-on, optionally
 *                             time-boxed via starts_at / ends_at.
 *  5. `platform_audit_logs` — LANDLORD-SIDE record of every operator action.
 *                             Deliberately NOT tenant-scoped: it carries a plain
 *                             `organization_id` column with no global scope and
 *                             no BelongsToOrganization trait, so a customer can
 *                             never read operator activity about themselves or
 *                             anybody else.
 *
 * Conventions honoured: no database-level foreign keys, every statement guarded
 * by hasTable()/hasColumn() so the migration is idempotent, SQLite (local) and
 * MySQL (prod) safe, and every index given an explicit short name so MySQL's
 * 64-char identifier limit is never hit.
 *
 * Staffvora is pre-launch with no live customer data, so this migration also
 * (re)writes the four catalogue plans to the owner-approved placeholder prices
 * and limits — it deliberately overwrites the old seeded values rather than
 * carrying a compatibility shim.
 */
return new class extends Migration
{
    /**
     * The feature catalogue seed.
     *
     * The first 13 rows are a faithful copy of `Entitlements::REGISTRY` — same
     * key, kind, group, unit, label, help and default — so moving the catalogue
     * into the database is a no-op for access decisions. `default_value` is
     * stored as JSON so `false`, `0` and `null` stay distinguishable (null means
     * "unlimited" on a max-kind feature).
     *
     * @return array<int, array<string, mixed>>
     */
    private function featureSeed(): array
    {
        return [
            // --- Limits ------------------------------------------------------
            [
                'key' => 'max_users', 'kind' => 'max', 'group' => 'Limits', 'unit' => 'seats',
                'label' => 'User seats',
                'help' => 'Maximum number of users in the workspace (blank = unlimited).',
                'default_value' => 1, 'sort' => 10,
            ],
            [
                // NEW (owner request): a storage ceiling. Usage accounting is a
                // later phase; the key exists now so plans can price it.
                'key' => 'max_storage_gb', 'kind' => 'max', 'group' => 'Limits', 'unit' => 'GB',
                'label' => 'Storage (max GB)',
                'help' => 'Total screenshot + upload storage this workspace may use (blank = unlimited).',
                'default_value' => 0, 'sort' => 20,
            ],

            // --- Monitoring caps --------------------------------------------
            [
                'key' => 'max_screenshots_per_hour', 'kind' => 'max', 'group' => 'Monitoring', 'unit' => '/hour',
                'label' => 'Screenshots per hour (max)',
                'help' => 'The highest screenshot rate this plan may set.',
                'default_value' => 0, 'sort' => 30,
            ],
            [
                'key' => 'min_auto_pause_minutes', 'kind' => 'min', 'group' => 'Monitoring', 'unit' => 'min',
                'label' => 'Auto-pause floor',
                'help' => 'The tightest idle auto-pause this plan may set (lower = more aggressive). Free is forced to a gentler floor.',
                'default_value' => 10, 'sort' => 40,
            ],
            [
                'key' => 'max_retention_days', 'kind' => 'max', 'group' => 'Monitoring', 'unit' => 'days',
                'label' => 'Screenshot retention (max days)',
                'help' => 'How long screenshots may be kept.',
                'default_value' => 7, 'sort' => 50,
            ],

            // --- Feature flags ----------------------------------------------
            [
                'key' => 'monitoring', 'kind' => 'flag', 'group' => 'Features', 'unit' => null,
                'label' => 'Screenshot monitoring',
                'help' => 'Capture desktop screenshots at all.',
                'default_value' => false, 'sort' => 60,
            ],
            [
                'key' => 'activity_tracking', 'kind' => 'flag', 'group' => 'Features', 'unit' => null,
                'label' => 'Activity tracking',
                'help' => 'Keyboard/mouse activity percentage.',
                'default_value' => false, 'sort' => 70,
            ],
            [
                'key' => 'app_url_tracking', 'kind' => 'flag', 'group' => 'Features', 'unit' => null,
                'label' => 'App & URL tracking',
                'help' => 'Record active application and browser URL.',
                'default_value' => false, 'sort' => 80,
            ],
            [
                'key' => 'weekly_limit', 'kind' => 'flag', 'group' => 'Features', 'unit' => null,
                'label' => 'Weekly time limit',
                'help' => 'Allow setting a weekly hours cap per user.',
                'default_value' => false, 'sort' => 90,
            ],
            [
                'key' => 'attendance_alerts', 'kind' => 'flag', 'group' => 'Features', 'unit' => null,
                'label' => 'Attendance Slack alerts',
                'help' => 'Notify Slack on clock-in / clock-out.',
                'default_value' => false, 'sort' => 100,
            ],
            [
                'key' => 'ai', 'kind' => 'flag', 'group' => 'Features', 'unit' => null,
                'label' => 'AI assistant',
                'help' => 'AI day-summary and assistant features.',
                'default_value' => false, 'sort' => 110,
            ],
            [
                'key' => 'integrations', 'kind' => 'flag', 'group' => 'Features', 'unit' => null,
                'label' => 'Integrations',
                'help' => "Connect the workspace's own Slack bot.",
                'default_value' => false, 'sort' => 120,
            ],
            [
                'key' => 'api', 'kind' => 'flag', 'group' => 'Features', 'unit' => null,
                'label' => 'API access',
                'help' => 'Programmatic API access.',
                'default_value' => false, 'sort' => 130,
            ],

            // --- Agency / compliance (NEW — replaces workspace_type gating) ---
            [
                'key' => 'upwork_profiles', 'kind' => 'flag', 'group' => 'Agency', 'unit' => null,
                'label' => 'Upwork profiles',
                'help' => 'The Upwork "Profiles" attribution dimension and marketplace work categories. Agencies running multiple Upwork seller accounts need this; in-house teams never see it.',
                'default_value' => false, 'sort' => 140,
            ],
            [
                'key' => 'machine_compliance', 'kind' => 'flag', 'group' => 'Agency', 'unit' => null,
                'label' => 'Machine compliance',
                'help' => 'Per-PC extension / program / process / VPN inventory with a blocklist and compliance dashboard.',
                'default_value' => false, 'sort' => 150,
            ],

            // --- Branding -----------------------------------------------------
            [
                'key' => 'white_label', 'kind' => 'flag', 'group' => 'Branding', 'unit' => null,
                'label' => 'White-label branding',
                'help' => 'Custom logo, name, accent and theme across the app.',
                'default_value' => false, 'sort' => 160,
            ],
        ];
    }

    public function up(): void
    {
        // ------------------------------------------------------------------
        // 1. features — the editable catalogue
        // ------------------------------------------------------------------
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();          // stable identifier used in code — never renamed
                $table->string('label');
                $table->text('help')->nullable();
                $table->string('kind')->default('flag');  // flag | max | min
                $table->string('group')->default('Features');
                $table->text('default_value')->nullable(); // JSON scalar: false / 0 / null
                $table->string('unit')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_active', 'sort'], 'features_active_sort_idx');
            });
        }

        foreach ($this->featureSeed() as $feature) {
            // updateOrInsert keyed on `key` — re-running the migration refreshes
            // wording without duplicating rows or clobbering `is_active`.
            $existing = DB::table('features')->where('key', $feature['key'])->first();

            $payload = [
                'label' => $feature['label'],
                'help' => $feature['help'],
                'kind' => $feature['kind'],
                'group' => $feature['group'],
                'default_value' => json_encode($feature['default_value']),
                'unit' => $feature['unit'],
                'sort' => $feature['sort'],
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('features')->where('key', $feature['key'])->update($payload);
            } else {
                DB::table('features')->insert($payload + [
                    'key' => $feature['key'],
                    'is_active' => true,
                    'created_at' => now(),
                ]);
            }
        }

        // ------------------------------------------------------------------
        // 2. plans — yearly price + lifecycle
        // ------------------------------------------------------------------
        if (! Schema::hasColumn('plans', 'price_cents_yearly')) {
            Schema::table('plans', function (Blueprint $table) {
                // Nullable, and null is MEANINGFUL: "no yearly price / talk to us"
                // (Enterprise). Not the same as 0, which is genuinely free.
                $table->unsignedInteger('price_cents_yearly')->nullable()->after('price_cents');
            });
        }

        if (! Schema::hasColumn('plans', 'visibility')) {
            Schema::table('plans', function (Blueprint $table) {
                // public  — listed on the pricing page and self-serve signup
                // hidden  — exists, but not advertised
                // custom  — negotiated, assigned by an operator only
                // legacy  — grandfathered; existing subscribers keep it, nobody new
                $table->string('visibility')->default('public')->after('is_public');
                $table->index(['visibility', 'sort'], 'plans_visibility_sort_idx');
            });
        }

        if (! Schema::hasColumn('plans', 'archived_at')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->after('visibility');
            });
        }

        if (! Schema::hasColumn('plans', 'is_default_trial')) {
            Schema::table('plans', function (Blueprint $table) {
                // Exactly one plan carries this. It is what a brand-new signup
                // is put on — replacing the hardcoded literal 'pro' lookup, so
                // the owner can rename/archive any plan without breaking signup.
                $table->boolean('is_default_trial')->default(false)->after('archived_at');
            });
        }

        // Backfill visibility from the legacy boolean, then apply the
        // owner-approved placeholder catalogue. Pre-launch: no live data, so we
        // overwrite the old seeded prices/limits outright.
        DB::table('plans')->where('is_public', false)->update(['visibility' => 'hidden']);

        if (class_exists(\App\Models\Plan::class)) {
            foreach (\App\Models\Plan::defaults() as $spec) {
                $plan = \App\Models\Plan::firstOrNew(['key' => $spec['key']]);
                $plan->fill($spec)->save();
            }

            // Belt and braces: only one plan may be THE default trial plan.
            $trial = \App\Models\Plan::where('is_default_trial', true)->orderBy('sort')->first();
            if ($trial) {
                \App\Models\Plan::where('is_default_trial', true)
                    ->where('id', '!=', $trial->id)
                    ->update(['is_default_trial' => false]);
            }
        }

        // ------------------------------------------------------------------
        // 3. addons — sellable entitlement bundles
        // ------------------------------------------------------------------
        if (! Schema::hasTable('addons')) {
            Schema::create('addons', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->unsignedInteger('price_cents')->default(0);          // per seat / month
                $table->unsignedInteger('price_cents_yearly')->nullable();   // per seat / year
                $table->string('currency', 3)->default('USD');
                $table->json('entitlements')->nullable();                    // same shape as plans.entitlements
                $table->string('visibility')->default('public');             // public | hidden | custom | legacy
                $table->unsignedInteger('sort')->default(0);
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                $table->index(['visibility', 'sort'], 'addons_visibility_sort_idx');
            });
        }

        // The Agency toolkit: everything that used to be welded to
        // organizations.workspace_type === 'agency', now sold as entitlements.
        if (! DB::table('addons')->where('key', 'agency_toolkit')->exists()) {
            DB::table('addons')->insert([
                'key' => 'agency_toolkit',
                'name' => 'Agency toolkit',
                'description' => 'Upwork profiles, marketplace work categories and Machine Compliance. Attaches to Starter, Growth or Enterprise.',
                'price_cents' => 400,        // PLACEHOLDER — $4 / seat / month
                'price_cents_yearly' => 4000, // PLACEHOLDER — $40 / seat / year
                'currency' => 'USD',
                'entitlements' => json_encode([
                    'upwork_profiles' => true,
                    'machine_compliance' => true,
                ]),
                'visibility' => 'public',
                'sort' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ------------------------------------------------------------------
        // 4. organization_addon — who bought what
        // ------------------------------------------------------------------
        if (! Schema::hasTable('organization_addon')) {
            Schema::create('organization_addon', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('addon_id');
                // Both nullable: null starts_at = active immediately,
                // null ends_at = no expiry. A time-boxed grant lets the operator
                // trial an add-on that switches itself off.
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'addon_id'], 'org_addon_unique');
                $table->index('addon_id', 'org_addon_addon_idx');
            });
        }

        // ------------------------------------------------------------------
        // 5. platform_audit_logs — landlord-side, NEVER tenant-readable
        // ------------------------------------------------------------------
        if (! Schema::hasTable('platform_audit_logs')) {
            Schema::create('platform_audit_logs', function (Blueprint $table) {
                $table->id();
                // Actor: the operator/staff account. Nullable so a system or
                // console action can be recorded too.
                $table->unsignedBigInteger('platform_user_id')->nullable();
                // Denormalised on purpose: the record must stay readable after
                // the staff account is deleted. An audit trail that can be
                // erased by deleting a user is not an audit trail.
                $table->string('actor_name')->nullable();
                $table->string('action');                 // e.g. plan.updated, tenant.impersonated
                // PLAIN column — deliberately NOT tenant-scoped. This table has
                // no OrganizationScope and no BelongsToOrganization trait.
                $table->unsignedBigInteger('organization_id')->nullable();
                $table->string('target_type')->nullable(); // e.g. plan, addon, organization, feature
                $table->unsignedBigInteger('target_id')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->text('reason')->nullable();
                $table->string('ip', 45)->nullable();      // 45 = max IPv6 text length
                $table->timestamps();

                $table->index(['organization_id', 'created_at'], 'pal_org_created_idx');
                $table->index(['action', 'created_at'], 'pal_action_created_idx');
                $table->index(['platform_user_id', 'created_at'], 'pal_actor_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('organization_addon');
        Schema::dropIfExists('addons');
        Schema::dropIfExists('features');

        foreach (['price_cents_yearly', 'visibility', 'archived_at', 'is_default_trial'] as $column) {
            if (Schema::hasColumn('plans', $column)) {
                Schema::table('plans', function (Blueprint $table) use ($column) {
                    if ($column === 'visibility') {
                        $table->dropIndex('plans_visibility_sort_idx');
                    }
                    $table->dropColumn($column);
                });
            }
        }
    }
};
