<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MACHINE COMPLIANCE — the storage layer.
 *
 * WHY THE FEATURE EXISTS
 * An agency running Upwork seller accounts gets its profiles flagged (and
 * eventually banned) when a bot, scraper, auto-refresh extension, mouse jiggler
 * or anti-detect browser is found running on a team machine. The desktop agent
 * reports what is installed and running on each PC; the server matches it
 * against a blocklist and the dashboard proves, per machine and per employee,
 * that the fleet is clean. Access is decided ONLY by the `machine_compliance`
 * entitlement key — never by plan name and never by workspace_type.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS NOT THE SOURCE'S SCHEMA (the scale decision)
 * ─────────────────────────────────────────────────────────────────────────────
 * The single-tenant source stored an APPEND-ONLY snapshot row per
 * (user, device, kind, day) with the entire inventory as a JSON blob. For one
 * company that is fine. At the target's stated ceiling — 500 organizations of
 * 100+ PCs — it is a firehose:
 *
 *   100 PCs × 5 kinds × 1 snapshot/day × 30 days  = 15,000 fat JSON rows /org/month
 *   × 500 orgs                                    = 7.5M rows/month, growing forever
 *
 * and every dashboard read had to re-parse those blobs in PHP.
 *
 * This port stores CURRENT STATE PER MACHINE instead, upserted in place, with
 * the change history carried on the row itself (first_seen_at / last_seen_at /
 * removed_at) and in the durable finding ledger (machine_flags):
 *
 *   machines                 100 rows per 100-PC org. Ever.
 *   machine_inventory_items  ~450 rows per PC steady state (see below) ≈ 45,000
 *                            rows for a 100-PC org — UPDATED on every report,
 *                            not appended. Net new rows per month is churn only:
 *                            an install or an uninstall, realistically a few
 *                            hundred rows/month for that whole org.
 *   machine_flags            one row per (machine, flagged tool) — tens, not
 *                            thousands; resolved rows are pruned.
 *
 * So a 100-PC organization generates roughly 300–800 NEW rows per month under
 * this design, against ~15,000 fat rows/month under the source's, and its
 * steady-state footprint stops growing once the fleet is enumerated.
 *
 * A second, deliberate volume cut: only `extensions` and `programs` are stored
 * in full. `processes` and `network` are scanned for blocklist hits and ONLY the
 * hits are stored. Running-process lists are enormous and change every minute,
 * and the source's own dashboard already excluded them from the browsable
 * inventory ("huge and transient… already covered by the flagged-tools list") —
 * so persisting them in full would have been pure cost with no read path.
 *
 * RETENTION EXISTS FROM DAY ONE (an unpruned firehose table was a prior audit
 * finding). `monitoring:prune-screenshots` — the nightly per-tenant sweep — now
 * also drops removed inventory items, resolved flags, machines that stopped
 * reporting, and anything over a per-organization row ceiling.
 *
 * TENANCY / SECURITY
 * organization_id is nullable + indexed on every table, matching the convention
 * here, and is stamped by App\Models\Concerns\BelongsToOrganization on create —
 * and stamped BY HAND in the ingest controller's upsert()/insert() calls, which
 * bypass the model hook entirely (that omission was a real audit finding).
 * Nothing in these tables is ever populated from a client-supplied tenant or
 * user id: the desktop agent runs on the employee's own machine and every byte
 * it sends is attacker-controlled.
 *
 * Conventions: no db-level foreign keys, idempotent guards, explicit short index
 * names, SQLite + MySQL safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createMachines();
        $this->createInventoryItems();
        $this->createFlags();
        $this->createBlocklistRules();
        $this->seedPlatformBlocklist();
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_blocklist_rules');
        Schema::dropIfExists('machine_flags');
        Schema::dropIfExists('machine_inventory_items');
        Schema::dropIfExists('machines');
    }

    /**
     * One row per physical PC an agent reports from, identified by
     * (organization_id, user_id, device_name). device_name is client-supplied,
     * so the ingest caps how many distinct machines one user may create — a
     * tampered agent must not be able to mint rows forever, and it can never
     * attach a machine to another user because user_id comes from the token.
     */
    private function createMachines(): void
    {
        if (Schema::hasTable('machines')) {
            return;
        }

        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('device_name', 120)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('app_version', 32)->nullable();

            $table->timestamp('first_seen_at')->nullable();
            // Server clock. Retention and the "is this machine still alive"
            // badge run against this, so it is never client-supplied.
            $table->timestamp('last_seen_at')->nullable();
            // The agent's own clock reading, clamped on ingest. Display only.
            $table->timestamp('collected_at')->nullable();

            // kind => count, and kind => last collection time. Display metadata
            // for the machine card; nothing filters or sorts on it, so JSON is
            // the right shape and keeps this table at one row per PC.
            $table->text('inventory_counts')->nullable();
            $table->text('inventory_collected_at')->nullable();

            $table->timestamps();

            // One machine per (tenant, user, device). This is the upsert target.
            $table->unique(['organization_id', 'user_id', 'device_name'], 'machines_org_user_device_uniq');
            $table->index(['organization_id', 'last_seen_at'], 'machines_org_seen_idx');
            $table->index(['organization_id', 'user_id'], 'machines_org_user_idx');
            // Cross-tenant: the retention sweep and orphan purge.
            $table->index('last_seen_at', 'machines_seen_idx');
        });
    }

    /**
     * CURRENT inventory state, one row per (machine, kind, item signature),
     * upserted on every report. `present` + `removed_at` are the change history:
     * an uninstalled tool flips to present=false and is pruned later, rather
     * than the whole inventory being re-inserted as a new snapshot.
     */
    private function createInventoryItems(): void
    {
        if (Schema::hasTable('machine_inventory_items')) {
            return;
        }

        Schema::create('machine_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('machine_id');
            // Denormalised from machines so the "By employee" aggregate never
            // needs a join, and so a per-user delete is a single statement.
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('kind', 32);            // extensions|programs|processes|network
            $table->string('signature', 64);       // md5(kind | canonical identity)

            $table->string('name', 200);
            $table->string('browser', 40)->nullable();
            // Which Chrome/Edge profile an extension lives in. NULL means "the
            // agent could not tell us", NEVER "no profile" — people run 20-30
            // profiles per PC, often one per Upwork account, and which profile
            // carries a scraper is the only actionable part.
            //
            // NOTE THE COLUMN NAME. The wire field is `browser_profile` and must
            // never be `profile`: a repeated `profile` key in the body is a
            // standard ModSecurity rule target and the host firewall returned a
            // plain-text 403 for the ENTIRE upload — silently losing extensions,
            // programs, processes and network together for over half the fleet.
            $table->string('browser_profile', 60)->nullable();
            $table->string('publisher', 150)->nullable();
            $table->string('version', 40)->nullable();
            // adapter description / process path, truncated. Free text from the
            // employee's machine, so it is normalised before it is written.
            $table->string('detail', 200)->nullable();
            $table->boolean('enabled')->nullable();

            // Denormalised blocklist verdict so the dashboard never re-scans in
            // PHP to render a list.
            $table->boolean('flagged')->default(false);
            $table->string('rule', 40)->nullable();
            $table->string('severity', 16)->nullable();
            $table->boolean('alert')->default(false);

            $table->boolean('present')->default(true);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('removed_at')->nullable();

            $table->timestamps();

            $table->unique(['machine_id', 'kind', 'signature'], 'mach_inv_uniq');
            // Every hot read is organization-leading.
            $table->index(['organization_id', 'kind', 'present'], 'mach_inv_org_kind_idx');
            $table->index(['organization_id', 'flagged', 'present'], 'mach_inv_org_flag_idx');
            $table->index(['organization_id', 'user_id', 'kind'], 'mach_inv_org_user_idx');
            $table->index(['organization_id', 'name'], 'mach_inv_org_name_idx');
            // Retention + the "mark everything not in this report as gone" sweep.
            $table->index(['machine_id', 'kind', 'present', 'last_seen_at'], 'mach_inv_sweep_idx');
            $table->index('last_seen_at', 'mach_inv_seen_idx');
        });
    }

    /**
     * Durable ledger of flagged tools — one row per (machine, tool signature).
     *
     * Ported wholesale from the source, where it replaced a cache-based dedup
     * that re-announced every finding after each deploy. A tool alerts exactly
     * ONCE when it first appears, stays quiet while it is present, auto-resolves
     * when it disappears, and re-alerts only if it comes back. Managers
     * acknowledge (seen, stay quiet) or ignore (never alert here again).
     */
    private function createFlags(): void
    {
        if (Schema::hasTable('machine_flags')) {
            return;
        }

        Schema::create('machine_flags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('device_name', 120)->nullable();

            $table->string('kind', 32);
            $table->string('rule', 40);
            $table->string('severity', 16);
            // Does this category ping Slack? TRUE only for the Upwork-banning
            // categories (scrapers, refresh/bid bots, jigglers, anti-detect
            // browsers). VPN/network findings are WATCH-ONLY by owner policy:
            // recorded and shown, never announced.
            $table->boolean('alert')->default(false);
            $table->string('label', 200);
            $table->string('browser_profile', 60)->nullable();
            $table->string('signature', 64);

            $table->string('status', 16)->default('open'); // open|acknowledged|ignored|resolved
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('platform', 32)->nullable();

            $table->timestamps();

            $table->unique(['machine_id', 'signature'], 'mach_flag_uniq');
            $table->index(['organization_id', 'status', 'alert'], 'mach_flag_org_status_idx');
            $table->index(['organization_id', 'rule'], 'mach_flag_org_rule_idx');
            $table->index(['organization_id', 'user_id'], 'mach_flag_org_user_idx');
            $table->index(['machine_id', 'kind', 'status', 'last_seen_at'], 'mach_flag_sweep_idx');
            $table->index('resolved_at', 'mach_flag_resolved_idx');
        });
    }

    /**
     * THE BLOCKLIST IS DATA, NOT A PHP ARRAY.
     *
     * The source hardcoded its rules in a class constant, so extending the list
     * meant a deploy. Here the platform seeds its own rules with
     * organization_id = NULL (visible to every tenant, is_system = true so a
     * customer cannot delete the operator's baseline) and any organization may
     * add its own rows on top — including allowlist entries (`is_allow`) that
     * suppress a false positive such as "Proxy SwitchyOmega".
     */
    private function createBlocklistRules(): void
    {
        if (Schema::hasTable('machine_blocklist_rules')) {
            return;
        }

        Schema::create('machine_blocklist_rules', function (Blueprint $table) {
            $table->id();
            // NULL = platform baseline, shared by every tenant (read-only to
            // customers). Anything else is that organization's own rule.
            $table->unsignedBigInteger('organization_id')->nullable();

            $table->string('rule_key', 40);     // category slug, e.g. 'scraper'
            $table->string('label', 120);
            $table->string('severity', 16)->default('medium'); // critical|high|medium|low
            // Whether a match in this category announces to Slack.
            $table->boolean('alert')->default(false);
            // NULL = applies to every report kind.
            $table->string('kind', 32)->nullable();
            $table->string('match_type', 12)->default('regex'); // regex|contains
            $table->string('pattern', 200);
            // Allowlist row: a match suppresses the item entirely. Needed because
            // matching is on the tool's NAME, and "Proxy SwitchyOmega" or
            // "IDM Integration Module" would otherwise trip the VPN patterns.
            $table->boolean('is_allow')->default(false);
            // Platform baseline row — a tenant may disable it, never delete it.
            $table->boolean('is_system')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'enabled'], 'mach_rule_org_enabled_idx');
            $table->index(['organization_id', 'rule_key'], 'mach_rule_org_key_idx');
        });
    }

    /**
     * The source's battle-tested rule set, ported verbatim as rows.
     *
     * Two rules learned the hard way there, preserved here:
     *  1. Match on the tool's NAME (and a program's publisher) only — never on
     *     the permissions it requests. An earlier version folded manifest
     *     permissions into the searched text and mislabelled Similarweb, IDM and
     *     ad blockers as VPNs.
     *  2. Only the genuinely Upwork-banning categories alert. VPNs and RPA/QA
     *     automation frameworks are recorded for review but never ping a
     *     channel — VPNs are used for legitimate client work in the office.
     */
    private function seedPlatformBlocklist(): void
    {
        if (! Schema::hasTable('machine_blocklist_rules')) {
            return;
        }

        if (DB::table('machine_blocklist_rules')->whereNull('organization_id')->exists()) {
            return; // already seeded
        }

        $now = now();
        $rows = [];

        $categories = [
            'upwork_refresh_bid' => [
                'label' => 'Upwork auto-refresh / auto-bid tool',
                'severity' => 'critical', 'alert' => true, 'sort' => 10,
                'patterns' => [
                    'auto.?refresh', 'easy auto refresh', 'super.?refresh', 'auto.?bid', 'auto.?propos',
                    'connects.?bot', 'upwork.?bot', 'upwork.?automat', 'upwork.?assist', 'freelanc.?bot',
                    'job.?alert.?bot',
                ],
            ],
            'scraper' => [
                'label' => 'Scraper / data extraction tool',
                'severity' => 'critical', 'alert' => true, 'sort' => 20,
                'patterns' => [
                    'instant data scraper', 'web.?scraper', 'data.?scraper', '\\bscraper\\b', 'scrape',
                    'octoparse', 'parsehub', 'simplescraper', 'listly', 'webharvy',
                ],
            ],
            'jiggler_autoclicker' => [
                'label' => 'Mouse jiggler / auto-clicker',
                'severity' => 'critical', 'alert' => true, 'sort' => 30,
                'patterns' => [
                    'mouse.?jiggl', '\\bjiggler\\b', 'move.?mouse', 'mouse.?mover', 'mouse.?wiggl',
                    'auto.?click', 'autoclick', 'gs auto clicker', 'op auto clicker', 'tinytask', 'tiny task',
                    'murgee', 'auto.?mouse', 'ghost.?mouse', 'mini.?mouse', 'clickermann', 'autohotkey',
                    'macro.?record',
                ],
            ],
            // Anti-detect browsers run multiple identities from one machine. For
            // Upwork that reads as multi-accounting and is a harder ban risk than
            // a scraper, so these alert.
            'antidetect_browser' => [
                'label' => 'Anti-detect / multi-account browser',
                'severity' => 'critical', 'alert' => true, 'sort' => 40,
                'patterns' => [
                    'multilogin', 'gologin', 'dolphin.?anty', 'antidetect', 'incogniton',
                    'adspower', 'kameleo', 'octo.?browser',
                ],
            ],
            // WATCH-ONLY. Recorded and shown on the dashboard, never announced.
            'vpn_proxy' => [
                'label' => 'VPN / proxy (watch only)',
                'severity' => 'high', 'alert' => false, 'sort' => 50,
                'patterns' => [
                    '\\bvpn\\b', 'browsec', '\\bhola\\b', 'windscribe', 'nordvpn', 'expressvpn', 'proton.?vpn',
                    'hotspot.?shield', 'urban.?vpn', '1click.?vpn', 'touch.?vpn', 'zenmate', 'tunnelbear',
                    'setupvpn', 'betternet', 'psiphon', 'ultrasurf', 'surfshark', 'veepn', '\\buvpn\\b', '\\b1vpn\\b',
                ],
            ],
            // WATCH-ONLY. Legitimate on an engineering machine.
            'automation_framework' => [
                'label' => 'Automation / RPA framework (watch only)',
                'severity' => 'medium', 'alert' => false, 'sort' => 60,
                'patterns' => [
                    'selenium', 'puppeteer', 'playwright', 'uipath', 'power.?automate', 'automa\\b',
                    'browserflow', 'axiom.?ai', 'bardeen',
                ],
            ],
        ];

        foreach ($categories as $key => $category) {
            foreach ($category['patterns'] as $pattern) {
                $rows[] = [
                    'organization_id' => null,
                    'rule_key' => $key,
                    'label' => $category['label'],
                    'severity' => $category['severity'],
                    'alert' => $category['alert'],
                    'kind' => null,
                    'match_type' => 'regex',
                    'pattern' => $pattern,
                    'is_allow' => false,
                    'is_system' => true,
                    'enabled' => true,
                    'sort' => $category['sort'],
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Known-safe tools whose NAME contains a trigger word. Substring match.
        $allowlist = [
            'ublock origin', 'adguard adblocker', 'adblock plus', 'ghostery', 'privacy badger',
            'proxy switchyomega', 'switchyomega', 'modheader', 'postman',
            'idm integration module', 'internet download manager', 'free download manager',
            'similarweb', 'mozbar', 'ahrefs', 'seoquake', 'keywords everywhere',
            'skyline dataminer',
        ];

        foreach ($allowlist as $safe) {
            $rows[] = [
                'organization_id' => null,
                'rule_key' => 'allowlist',
                'label' => 'Known-safe tool',
                'severity' => 'low',
                'alert' => false,
                'kind' => null,
                'match_type' => 'contains',
                'pattern' => $safe,
                'is_allow' => true,
                'is_system' => true,
                'enabled' => true,
                'sort' => 0,
                'notes' => 'Suppresses a name-based false positive.',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Chunked so the seed never builds one oversized statement.
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('machine_blocklist_rules')->insert($chunk);
        }
    }
};
