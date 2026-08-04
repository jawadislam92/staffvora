<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.is_demo — the UNFORGEABLE sample-data marker.
 *
 * ─── WHY THIS COLUMN EXISTS ──────────────────────────────────────────────────
 * Sample-data teammates ({@see \App\Services\DemoDataService}) do not consume
 * plan seats, so "is this row sample data?" is a BILLING and PLAN-LIMIT
 * decision. It used to be answered by looking at the e-mail address, in two
 * different places that could not agree:
 *
 *   • the REFUSAL was byte-exact PHP  — `str_ends_with(strtolower($email), …)`
 *   • the EXCLUSION was SQL `LIKE`    — evaluated in `utf8mb4_unicode_ci`
 *
 * A *_ci collation folds case AND accents, so on MySQL (production)
 * `'demo-9-1@demo.staffvora.İnvalid' LIKE '%@demo.staffvora.invalid'` is TRUE
 * while PHP's byte comparison is FALSE. That single disagreement was a free,
 * unbilled, login-capable seat: the guard let the address through and the seat
 * count hid the row. Eight ordinary `POST /users` requests put nine active
 * users on a two-seat plan with both counters still reporting 1. SQLite (the
 * test suite) does not fold, so the suite structurally could not see it.
 *
 * The fix is to stop asking the database how it compares strings. Demo identity
 * is now an explicit boolean FACT, written only inside
 * {@see \App\Models\User::asDemoIdentityWriter()} (server-side code with no
 * request-reachable entry point) and read by BOTH the guard and the seat
 * exclusion. The e-mail convention survives only as a cosmetic label.
 *
 * ─── BACKFILL ────────────────────────────────────────────────────────────────
 * Existing sample-data rows must keep being free, so they are stamped here. The
 * candidate set is fetched with a LIKE (on MySQL that FOLDS, i.e. it returns a
 * SUPERSET that includes any already-forged address) and then filtered in PHP
 * with a byte-exact test — so a forged row is deliberately NOT stamped and
 * starts consuming the seat it always should have. Over-marking would make the
 * forgery permanent; under-marking only costs a seat until the customer removes
 * their sample data, so this errs strict on purpose.
 *
 * Idempotent (hasColumn guards + a re-runnable backfill), no DB-level FKs,
 * short index name, safe on SQLite and MySQL.
 */
return new class extends Migration
{
    /** The reserved namespace, duplicated here so the migration never depends on app code. */
    private const DEMO_DOMAIN = 'demo.staffvora.invalid';

    private const INDEX = 'users_org_demo_active_idx';

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'is_demo')) {
            Schema::table('users', function (Blueprint $table) {
                // NOT NULL default false: every existing row is real staff until
                // the backfill below proves otherwise, and a NULL would put the
                // seat scope back in the business of three-valued logic.
                $table->boolean('is_demo')->default(false);
            });
        }

        // The seat count is `organization_id + is_demo + is_active`; give it a
        // covering composite so a 100-user tenant's count stays an index probe.
        try {
            Schema::table('users', function (Blueprint $table) {
                $table->index(['organization_id', 'is_demo', 'is_active'], self::INDEX);
            });
        } catch (\Throwable $e) {
            // Already present (a re-run, or an equivalent index) — never fatal.
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'is_demo')) {
            return;
        }

        try {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        } catch (\Throwable $e) {
            // Index may not exist; dropping the column is what matters.
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }

    /**
     * Stamp the rows DemoDataService really created. Chunked and bounded — this
     * runs against a table with a million rows, and the LIKE is anchored on the
     * unique e-mail index.
     */
    private function backfill(): void
    {
        DB::table('users')
            ->select(['id', 'email', 'organization_id'])
            ->where('email', 'like', '%@'.self::DEMO_DOMAIN)
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                $ids = [];

                foreach ($rows as $row) {
                    if ($this->isGenuineDemoAddress((string) $row->email, $row->organization_id)) {
                        $ids[] = $row->id;
                    }
                }

                if ($ids !== []) {
                    DB::table('users')->whereIn('id', $ids)->update(['is_demo' => true]);
                }
            });
    }

    /**
     * Byte-exact, in PHP, on a normalised value — never in SQL. The address must
     * be EXACTLY the shape DemoDataService mints for THIS row's own workspace:
     * `demo-<organization_id>-<n>@demo.staffvora.invalid`.
     *
     * `strtolower()` is ASCII-only in PHP 8, so it cannot fold a non-ASCII
     * codepoint onto an ASCII one the way a *_ci collation does.
     */
    private function isGenuineDemoAddress(string $email, mixed $organizationId): bool
    {
        if ($organizationId === null || ! is_numeric($organizationId)) {
            return false;
        }

        $normalised = strtolower(trim($email));

        return preg_match(
            '/^demo-'.((int) $organizationId).'-\d+@'.preg_quote(self::DEMO_DOMAIN, '/').'$/',
            $normalised
        ) === 1;
    }
};
