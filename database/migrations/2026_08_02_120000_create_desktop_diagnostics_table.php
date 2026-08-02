<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop diagnostics store + the watchdog's paused-vs-blocked probe column.
 *
 * WHY THE TABLE
 * The desktop agent runs on the employee's own machine, where nobody can see it.
 * When its screenshot helper is killed by antivirus, its token expires, or the
 * process crashes, the *only* signal today is an empty report discovered at
 * payroll time. This table is where the agent reports those client-side events
 * (`category = 'desktop'`), and where the capture-health watchdog records WHY it
 * decided to alert / stay quiet (`category = 'tracker-health'`), so
 * "why did I get this notification?" is answerable without an SSH session.
 *
 * The single-tenant source app wrote one JSON file per event under
 * storage/app/diagnostics/. That does not survive multi-tenancy (no tenant
 * boundary, no query surface, and on the shared VPS it is one directory that
 * every organization writes into), so it is ported as a tenant-owned table.
 *
 * TENANCY
 * organization_id is NULLABLE and INDEXED, matching every other tenant table
 * here: NOT NULL would break `migrate` on a database with pre-existing rows and
 * would break the console/seeder paths that legitimately run unbound. The
 * OrganizationScope restricts reads to the active workspace;
 * App\Models\DesktopDiagnostic stamps the column on create.
 *
 * RETENTION (from day one — an unpruned telemetry table was a prior audit
 * finding). Two independent ceilings, both enforced by
 * `monitoring:prune-screenshots`:
 *   1. TIME  — rows older than the tenant's retention window, hard-capped at
 *      DIAGNOSTIC_MAX_RETENTION_DAYS (30) regardless of what the tenant asks for.
 *   2. VOLUME — at most DIAGNOSTIC_MAX_ROWS newest rows per organization, so a
 *      looping agent cannot flood the table inside the time window.
 * The command only ever writes an alert-grade row unattended; per-decision
 * traces require the operator to pass `--trace`.
 *
 * WHY THE EXTRA COLUMN ON tracking_sessions
 * `health_probe_seconds` is the watchdog's memory between runs: the tracked
 * seconds observed last pass. Comparing it to the current value is how a PAUSED
 * tracker (legitimately capturing nothing) is told apart from one that is
 * counting time but capturing nothing (an actual antivirus block). Without it
 * the watchdog cannot avoid crying wolf, so it ships in the same migration.
 *
 * Conventions: no db-level foreign keys, idempotent guards, explicit short index
 * names, SQLite + MySQL safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('desktop_diagnostics')) {
            Schema::create('desktop_diagnostics', function (Blueprint $table) {
                $table->id();

                // Nullable + indexed, per the tenancy convention. Never taken
                // from the request body — the ingest controller derives it from
                // the authenticated user.
                $table->unsignedBigInteger('organization_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();

                // 'desktop' (agent-reported) | 'tracker-health' (watchdog decisions).
                $table->string('category', 40)->default('desktop');
                $table->string('level', 12)->default('info');
                $table->string('event', 80);
                $table->string('summary', 255)->nullable();
                $table->text('message')->nullable();

                // JSON payload, size-capped by the model before it is written.
                // `text` rather than `json` so the column behaves identically on
                // SQLite and older MySQL.
                $table->text('context')->nullable();

                $table->string('device_name', 120)->nullable();
                $table->string('platform', 32)->nullable();
                $table->string('app_version', 32)->nullable();

                // Client-supplied instant (clamped on ingest, never trusted) vs
                // the server clock, which is the one retention runs against.
                $table->timestamp('client_at')->nullable();
                $table->timestamp('captured_at')->nullable();

                $table->timestamps();

                // Every tenant read is org-leading and time-ordered.
                $table->index(['organization_id', 'captured_at'], 'desktop_diag_org_cap_idx');
                $table->index(['organization_id', 'level', 'captured_at'], 'desktop_diag_org_lvl_idx');
                $table->index(['organization_id', 'user_id', 'captured_at'], 'desktop_diag_org_usr_idx');
                // Cross-tenant: the operator panel's recent feed and the
                // orphan/retention sweep, neither of which has an organization.
                $table->index('captured_at', 'desktop_diag_cap_idx');
            });
        }

        if (Schema::hasTable('tracking_sessions') && ! Schema::hasColumn('tracking_sessions', 'health_probe_seconds')) {
            Schema::table('tracking_sessions', function (Blueprint $table) {
                $table->unsignedBigInteger('health_probe_seconds')->nullable()->after('health_alerted_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_diagnostics');

        if (Schema::hasTable('tracking_sessions') && Schema::hasColumn('tracking_sessions', 'health_probe_seconds')) {
            Schema::table('tracking_sessions', function (Blueprint $table) {
                $table->dropColumn('health_probe_seconds');
            });
        }
    }
};
