<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer billing hub — the data the 4-tab billing page needs, and the
 * ADD-ON PURCHASE PATH that was missing entirely.
 *
 * Three things:
 *
 *  1. `subscriptions.billing_cycle` — monthly | yearly. Until now the yearly
 *     price existed on the plan but nothing recorded which cycle a company had
 *     actually chosen, so the "next invoice" figure could never be derived.
 *
 *  2. `payments` gains the columns that let ONE table carry both a received
 *     subscription payment and an OWED add-on charge:
 *       kind             subscription | addon   (what the line is for)
 *       addon_id         which add-on (landlord-side id; no DB-level FK)
 *       billing_cycle    the cycle the amount was derived for
 *       seats            the seat count the amount was derived from
 *       unit_price_cents the per-seat price for that cycle, snapshotted
 *       description      human line text for the invoice list
 *       requested_by     the TENANT user who asked (distinct from
 *                        `recorded_by`, which is the platform operator)
 *     `status` already existed and already defaulted to 'confirmed'; the new
 *     value 'pending' means "owed, not yet paid, grants nothing". Because no
 *     payment gateway is wired, a customer adding an add-on writes a PENDING
 *     row and NOTHING else — the entitlement is granted only when an operator
 *     confirms the charge (exactly how a bank transfer is handled today).
 *
 *  3. `billing_profiles` — the company's own billing details (bill-to name,
 *     tax id, address, notes, invoice email). Tenant-owned, one row per org.
 *
 * Conventions: no database-level foreign keys, every statement guarded by
 * hasTable()/hasColumn() so re-running is a no-op, SQLite + MySQL safe, and
 * every index carries an explicit short name.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // 1. subscriptions — which cycle this company is billed on
        // ------------------------------------------------------------------
        if (Schema::hasTable('subscriptions') && ! Schema::hasColumn('subscriptions', 'billing_cycle')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->string('billing_cycle', 16)->default('monthly')->after('seats');
            });
        }

        // ------------------------------------------------------------------
        // 2. payments — carry an owed add-on charge as well as a received one
        // ------------------------------------------------------------------
        if (Schema::hasTable('payments')) {
            $adds = [
                'kind' => fn (Blueprint $t) => $t->string('kind', 20)->default('subscription')->after('subscription_id'),
                'addon_id' => fn (Blueprint $t) => $t->unsignedBigInteger('addon_id')->nullable()->after('kind'),
                'billing_cycle' => fn (Blueprint $t) => $t->string('billing_cycle', 16)->nullable()->after('addon_id'),
                'seats' => fn (Blueprint $t) => $t->unsignedInteger('seats')->nullable()->after('billing_cycle'),
                'unit_price_cents' => fn (Blueprint $t) => $t->unsignedInteger('unit_price_cents')->nullable()->after('seats'),
                'description' => fn (Blueprint $t) => $t->string('description')->nullable()->after('reference'),
                'requested_by' => fn (Blueprint $t) => $t->unsignedBigInteger('requested_by')->nullable()->after('recorded_by'),
            ];

            foreach ($adds as $column => $definition) {
                if (! Schema::hasColumn('payments', $column)) {
                    Schema::table('payments', function (Blueprint $table) use ($definition) {
                        $definition($table);
                    });
                }
            }

            // The invoices tab pages by (org, status) newest-first, and the
            // "do we already owe for this add-on" check reads (org, addon_id).
            // Both must stay indexed at 500 orgs × years of history.
            $this->addIndexOnce('payments', ['organization_id', 'status', 'id'], 'payments_org_status_id_idx');
            $this->addIndexOnce('payments', ['organization_id', 'addon_id'], 'payments_org_addon_idx');
        }

        // ------------------------------------------------------------------
        // 3. billing_profiles — the company's bill-to details
        // ------------------------------------------------------------------
        if (! Schema::hasTable('billing_profiles')) {
            Schema::create('billing_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->string('billing_name')->nullable();      // legal / bill-to name
                $table->string('billing_email')->nullable();     // where invoices go
                $table->string('tax_id')->nullable();            // NTN / VAT / GST
                $table->string('address_line1')->nullable();
                $table->string('address_line2')->nullable();
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->string('postal_code', 32)->nullable();
                $table->string('country', 2)->nullable();        // ISO-3166 alpha-2
                $table->text('notes')->nullable();               // printed on the invoice
                $table->timestamps();

                $table->unique('organization_id', 'billing_profiles_org_unique');
            });
        }
    }

    /** Add an index only if the table doesn't already carry one by that name. */
    private function addIndexOnce(string $table, array $columns, string $name): void
    {
        try {
            $existing = Schema::getIndexes($table);
            foreach ($existing as $index) {
                if (($index['name'] ?? null) === $name) {
                    return;
                }
            }
        } catch (Throwable $e) {
            // Driver can't introspect — fall through and let the create attempt
            // decide; a duplicate-index error is caught below.
        }

        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        } catch (Throwable $e) {
            // Already present. Idempotent by design.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_profiles');

        if (Schema::hasTable('payments')) {
            foreach (['payments_org_status_id_idx', 'payments_org_addon_idx'] as $index) {
                try {
                    Schema::table('payments', fn (Blueprint $t) => $t->dropIndex($index));
                } catch (Throwable $e) {
                    // never existed
                }
            }

            foreach (['kind', 'addon_id', 'billing_cycle', 'seats', 'unit_price_cents', 'description', 'requested_by'] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    Schema::table('payments', fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }

        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'billing_cycle')) {
            Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('billing_cycle'));
        }
    }
};
