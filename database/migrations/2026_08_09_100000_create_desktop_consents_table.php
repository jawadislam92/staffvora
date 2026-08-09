<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that a named person, on a named machine, actively agreed to the terms
 * under which this workspace monitors them.
 *
 * WHY THE TABLE
 * The desktop agent can record time, activity levels and — when the employer
 * enables them — screenshots and application/website names. In most
 * jurisdictions that is lawful only where the monitored person was told what is
 * captured and agreed to it. "We put it in the handbook" is not evidence; a
 * consent you cannot produce on demand is worth roughly nothing. This table is
 * the evidence: WHO accepted, WHICH VERSION of the documents, WHEN (by the
 * server's clock, not the employee's), from WHAT machine, and a hash of the
 * exact plain-language disclosure they were shown at the time.
 *
 * WHY disclosure_hash EXISTS
 * The terms and privacy pages live on the marketing site and will be edited.
 * A row that merely says "accepted v2026-08-09" cannot answer the only question
 * that matters in a dispute — "what did it actually say?". The hash pins the
 * disclosure text the agent rendered, so a later edit cannot silently rewrite
 * history, and a mismatch is detectable.
 *
 * APPEND-ONLY BY POLICY
 * Rows are written once and never updated. Re-accepting a NEW version writes a
 * NEW row; the old one stays. There is no `updated by` path anywhere in the
 * app, and there is deliberately no retention sweep over this table — every
 * other telemetry table here is pruned, this one must not be, because the whole
 * point is producing it years later.
 *
 * ROW-FLOOD BOUND
 * The agent is attacker-controllable, so the controller de-duplicates on
 * (organization, user, version, device) and the route is throttled. With
 * Machine::MAX_DEVICES_PER_USER devices and a handful of document versions,
 * one employee's ceiling is a few dozen rows, not unbounded.
 *
 * TENANCY
 * organization_id is nullable + indexed, matching every other tenant table
 * here (NOT NULL breaks `migrate` on a database with pre-existing rows and the
 * unbound console/seeder paths). It is stamped server-side from the Sanctum
 * token's user and is NEVER read from the request body.
 *
 * Conventions: no DB-level foreign keys, Schema::has* guards (idempotent),
 * SQLite + MySQL safe, short explicit index names.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('desktop_consents')) {
            return;
        }

        Schema::create('desktop_consents', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // Which document version was accepted, e.g. '2026-08-09'. Compared
            // as an exact string — never a >= comparison, because a new version
            // means "ask again", not "close enough".
            $table->string('document_version', 40);

            // sha256 of the canonical disclosure text served with that version.
            $table->string('disclosure_hash', 64)->nullable();

            // The documents as advertised to the client at acceptance time, so
            // the record survives a later domain or slug change.
            $table->string('terms_url', 255)->nullable();
            $table->string('privacy_url', 255)->nullable();

            // Where the acceptance came from ('desktop_first_run' today; a web
            // or mobile surface can reuse this ledger later).
            $table->string('source', 40)->default('desktop_first_run');

            // The employee's machine, for "which of my PCs did I accept on".
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('app_version', 32)->nullable();

            // The client clock is a claim, not a fact: it is clamped on ingest
            // and stored SEPARATELY from accepted_at, which is the server clock
            // and the only timestamp an auditor should be shown.
            $table->timestamp('client_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            // Recorded because "someone on the other side of the world accepted
            // for me" is the obvious challenge to any digital consent.
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            // "Has this user accepted the current version?" — the hot lookup on
            // every agent launch.
            $table->index(['organization_id', 'user_id', 'document_version'], 'desktop_consent_org_usr_ver_idx');
            // The tenant-side ledger view: newest acceptances for a workspace.
            $table->index(['organization_id', 'accepted_at'], 'desktop_consent_org_at_idx');
            // De-duplication probe (user + version + device) and the operator's
            // cross-tenant "produce this person's consent history".
            $table->index(['user_id', 'document_version'], 'desktop_consent_usr_ver_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_consents');
    }
};
