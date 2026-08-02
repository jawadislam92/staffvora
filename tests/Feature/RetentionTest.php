<?php

namespace Tests\Feature;

use App\Models\AttendanceClockCheck;
use App\Models\DesktopDiagnostic;
use App\Models\Machine;
use App\Models\MachineFlag;
use App\Models\MachineInventoryItem;
use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\TrackingActivitySample;
use App\Models\TrackingAuditLog;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * EVERY UNBOUNDED TABLE, PROVEN BOUNDED
 * ─────────────────────────────────────────────────────────────────────────────
 * At 100-500 workspaces of 100+ people, a table with no retention path is not a
 * tidiness problem — it is a dated outage. Activity samples alone are ~480 rows
 * per person per day (~48,000/day for a 100-person tenant), each carrying window
 * titles and URL domains, i.e. PII retained well past what the customer was sold.
 *
 * The nightly sweep is two commands:
 *   • `monitoring:prune-screenshots` — PER TENANT (driven by `tenant:run`), covering
 *     screenshots, activity samples, desktop diagnostics, machine-compliance
 *     inventory, the monitoring audit trail and the attendance clock-check ledger;
 *   • `platform:prune` — ONCE, for the landlord tables that have no
 *     `organization_id` at all (failed jobs, contact form, reset tokens, access
 *     tokens, landlord audit trail).
 *
 * Three properties are asserted for every table, because a retention policy that
 * only holds for one of them is not a bound:
 *   1. the tenant's own retention WINDOW is honoured (old goes, recent stays);
 *   2. the HARD CAP still applies when the tenant sets retention to "forever"
 *      (0) or to something longer than its plan allows — otherwise the
 *      unbounded-growth hole simply reopens one checkbox at a time;
 *   3. pruning is TENANT-SCOPED. This one gets its own test because the platform
 *      has already shipped exactly this bug once: `tenant:delete` used
 *      `Eloquent\Builder::forceDelete()`, which reaches the BASE builder and
 *      discards every global scope, so erasing one workspace emitted an
 *      unqualified `DELETE FROM tracking_screenshots` and destroyed every
 *      customer's screenshots platform-wide. 592 tests stayed green through it.
 *      A destructive sweep does not get to be trusted; it gets to be tested.
 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A workspace with one row in every retention-governed table.
     *
     * @return array{org: Organization, user: User, rows: array<string, array{0: class-string, 1: int}>}
     */
    private function workspace(string $name, int $retentionDays = 60, int $maxRetentionDays = 3650): array
    {
        $org = Organization::factory()->create([
            'name' => $name,
            'status' => Organization::STATUS_ACTIVE,
            // The catalogue default for max_retention_days is 7 days, which would
            // otherwise clamp every fixture below. Pinned wide here so each test
            // exercises the rule it is actually about; the clamp has its own test.
            'entitlement_overrides' => ['max_retention_days' => $maxRetentionDays],
        ]);

        $current = app(CurrentOrganization::class);

        return $current->runFor($org, function () use ($org, $retentionDays) {
            $user = User::factory()->create(['organization_id' => $org->id]);

            MonitoringSetting::create(['retention_days' => $retentionDays]);

            $session = TrackingSession::create([
                'user_id' => $user->id,
                'client_uuid' => 'uuid-'.Str::random(8),
                'started_at' => now()->subDays(200),
                'status' => 'stopped',
                'source' => 'desktop',
            ]);

            Storage::disk('screenshots')->put($org->id.'/old.jpg', 'old');
            Storage::disk('screenshots')->put($org->id.'/recent.jpg', 'recent');

            $rows = [];

            // ── Screenshots ──────────────────────────────────────────────────
            $rows['screenshot.old'] = [TrackingScreenshot::class, TrackingScreenshot::create([
                'tracking_session_id' => $session->id,
                'user_id' => $user->id,
                'captured_at' => now()->subDays(90),
                'image_path' => $org->id.'/old.jpg',
            ])->id];

            $rows['screenshot.recent'] = [TrackingScreenshot::class, TrackingScreenshot::create([
                'tracking_session_id' => $session->id,
                'user_id' => $user->id,
                'captured_at' => now()->subDays(5),
                'image_path' => $org->id.'/recent.jpg',
            ])->id];

            // ── Activity samples ─────────────────────────────────────────────
            foreach (['ancient' => 200, 'old' => 90, 'recent' => 5] as $label => $daysAgo) {
                $rows['sample.'.$label] = [TrackingActivitySample::class, TrackingActivitySample::create([
                    'tracking_session_id' => $session->id,
                    'user_id' => $user->id,
                    'captured_at' => now()->subDays($daysAgo),
                    'active_window_title' => 'Inbox — '.$label,
                ])->id];
            }

            // ── Desktop diagnostics ──────────────────────────────────────────
            foreach (['old' => 40, 'recent' => 10] as $label => $daysAgo) {
                $rows['diagnostic.'.$label] = [DesktopDiagnostic::class, DesktopDiagnostic::create([
                    'user_id' => $user->id,
                    'category' => DesktopDiagnostic::CATEGORY_DESKTOP,
                    'level' => 'warn',
                    'event' => 'capture.blocked',
                    'captured_at' => now()->subDays($daysAgo),
                ])->id];
            }

            // ── Machine compliance ───────────────────────────────────────────
            $live = Machine::create([
                'user_id' => $user->id,
                'device_name' => 'LIVE-PC',
                'last_seen_at' => now()->subDay(),
            ]);
            $dead = Machine::create([
                'user_id' => $user->id,
                'device_name' => 'DEAD-PC',
                'last_seen_at' => now()->subDays(130),
            ]);

            $rows['machine.live'] = [Machine::class, $live->id];
            $rows['machine.dead'] = [Machine::class, $dead->id];

            $item = function (Machine $machine, string $label, bool $present, int $lastSeenDaysAgo) {
                return MachineInventoryItem::create([
                    'machine_id' => $machine->id,
                    'kind' => 'extensions',
                    'signature' => md5($machine->id.$label),
                    'name' => 'Tool '.$label,
                    'present' => $present,
                    'last_seen_at' => now()->subDays($lastSeenDaysAgo),
                ])->id;
            };

            $rows['inventory.removed_old'] = [MachineInventoryItem::class, $item($live, 'removed_old', false, 40)];
            $rows['inventory.stale_present'] = [MachineInventoryItem::class, $item($live, 'stale_present', true, 50)];
            $rows['inventory.fresh'] = [MachineInventoryItem::class, $item($live, 'fresh', true, 1)];
            $rows['inventory.on_dead_machine'] = [MachineInventoryItem::class, $item($dead, 'on_dead', true, 1)];

            $flag = function (Machine $machine, string $label, string $status, ?int $resolvedDaysAgo) {
                return MachineFlag::create([
                    'machine_id' => $machine->id,
                    'user_id' => null,
                    'kind' => 'extensions',
                    'rule' => 'scraper',
                    'severity' => 'high',
                    'label' => 'Finding '.$label,
                    'signature' => md5($machine->id.$label.'flag'),
                    'status' => $status,
                    'resolved_at' => $resolvedDaysAgo === null ? null : now()->subDays($resolvedDaysAgo),
                ])->id;
            };

            $rows['flag.resolved_old'] = [MachineFlag::class, $flag($live, 'resolved_old', MachineFlag::STATUS_RESOLVED, 100)];
            $rows['flag.resolved_recent'] = [MachineFlag::class, $flag($live, 'resolved_recent', MachineFlag::STATUS_RESOLVED, 10)];
            $rows['flag.open_old'] = [MachineFlag::class, $flag($live, 'open_old', MachineFlag::STATUS_OPEN, null)];

            // ── Monitoring audit trail (created_at only — no client timestamp) ─
            foreach (['old' => 400, 'recent' => 10] as $label => $daysAgo) {
                $id = TrackingAuditLog::create([
                    'subject_user_id' => $user->id,
                    'actor_user_id' => $user->id,
                    'action' => 'screenshot.delete',
                ])->id;

                $this->backdate('tracking_audit_logs', $id, $daysAgo);
                $rows['audit.'.$label] = [TrackingAuditLog::class, $id];
            }

            // ── Attendance clock-check ledger ────────────────────────────────
            $checks = [
                'check.resolved_old' => [90, true],
                'check.resolved_recent' => [10, true],
                'check.unresolved_recent' => [10, false],
                'check.unresolved_ancient' => [200, false],
            ];

            foreach ($checks as $label => [$daysAgo, $resolved]) {
                $id = AttendanceClockCheck::create([
                    'user_id' => $user->id,
                    'clock_in_id' => random_int(1, PHP_INT_MAX),
                    'resolved_at' => $resolved ? now()->subDays($daysAgo) : null,
                ])->id;

                $this->backdate('attendance_clock_checks', $id, $daysAgo);
                $rows[$label] = [AttendanceClockCheck::class, $id];
            }

            return ['org' => $org, 'user' => $user, 'rows' => $rows];
        });
    }

    /** created_at is not fillable, so the fixture has to set it out of band. */
    private function backdate(string $table, int $id, int $daysAgo): void
    {
        DB::table($table)->where('id', $id)->update(['created_at' => now()->subDays($daysAgo)]);
    }

    /** @param array<string, array{0: class-string, 1: int}> $rows */
    private function assertRowExists(array $rows, string $label, string $why = ''): void
    {
        [$model, $id] = $rows[$label];

        $this->assertNotNull(
            $model::withoutOrganizationScope()->find($id),
            $label.' should have survived the sweep. '.$why
        );
    }

    /** @param array<string, array{0: class-string, 1: int}> $rows */
    private function assertRowGone(array $rows, string $label, string $why = ''): void
    {
        [$model, $id] = $rows[$label];

        $this->assertNull(
            $model::withoutOrganizationScope()->find($id),
            $label.' should have been pruned. '.$why
        );
    }

    private function prune(Organization $org, array $options = []): void
    {
        app(CurrentOrganization::class)->runFor(
            $org,
            fn () => Artisan::call('monitoring:prune-screenshots', $options)
        );
    }

    /**
     * Property 1: every one of the six per-tenant tables actually shrinks, and the
     * recent rows in each of them survive. A sweep that deletes nothing and a sweep
     * that deletes everything are both wrong, and both exit 0.
     */
    public function test_the_nightly_sweep_prunes_every_unbounded_tenant_table(): void
    {
        Storage::fake('screenshots');

        $a = $this->workspace('Alpha');

        $this->prune($a['org']);

        $rows = $a['rows'];

        // Screenshots: the tenant's 60-day window.
        $this->assertRowGone($rows, 'screenshot.old');
        $this->assertRowExists($rows, 'screenshot.recent');
        Storage::disk('screenshots')->assertMissing($a['org']->id.'/old.jpg');
        Storage::disk('screenshots')->assertExists($a['org']->id.'/recent.jpg');

        // Activity samples — the highest-cardinality table in the product.
        $this->assertRowGone($rows, 'sample.ancient');
        $this->assertRowGone($rows, 'sample.old');
        $this->assertRowExists($rows, 'sample.recent');

        // Diagnostics: the tenant asked for 60 days, the PLATFORM cap is 30, so a
        // 40-day-old diagnostic goes even though the tenant's window would keep it.
        $this->assertRowGone($rows, 'diagnostic.old', 'The 30-day platform ceiling must beat the tenant window.');
        $this->assertRowExists($rows, 'diagnostic.recent');

        // Machine compliance: four independent ceilings.
        $this->assertRowGone($rows, 'inventory.removed_old', 'A tool removed 40 days ago is history, not evidence.');
        $this->assertRowExists($rows, 'inventory.fresh');
        $this->assertRowGone($rows, 'machine.dead', 'A PC that stopped reporting 130 days ago must be dropped.');
        $this->assertRowExists($rows, 'machine.live');
        $this->assertRowGone($rows, 'inventory.on_dead_machine', 'Dead machines must take their inventory with them (no db-level FKs).');
        $this->assertRowGone($rows, 'flag.resolved_old');
        $this->assertRowExists($rows, 'flag.resolved_recent');
        $this->assertRowExists($rows, 'flag.open_old', 'Open findings are never pruned by age.');

        // A long-unconfirmed but still "present" item is flipped to gone rather
        // than deleted in one step — that is what ages out a machine reporting an
        // empty inventory list, which the ingest cannot treat as a removal.
        $stale = MachineInventoryItem::withoutOrganizationScope()->find($rows['inventory.stale_present'][1]);
        $this->assertNotNull($stale);
        $this->assertFalse((bool) $stale->present, 'A 50-day-unconfirmed item must be flipped to removed.');
        $this->assertNotNull($stale->removed_at);

        // Monitoring audit trail — deliberately a LONG window (365 days), and
        // deliberately NOT clamped down to the tenant's own retention.
        $this->assertRowGone($rows, 'audit.old');
        $this->assertRowExists($rows, 'audit.recent');

        // Clock checks: resolved-and-old goes, everything still live stays.
        $this->assertRowGone($rows, 'check.resolved_old');
        $this->assertRowExists($rows, 'check.resolved_recent');
        $this->assertRowExists($rows, 'check.unresolved_recent', 'An unresolved check is live state the next pass reads.');
        $this->assertRowGone($rows, 'check.unresolved_ancient', 'An unresolved check 200 days old is abandoned state.');
    }

    /**
     * Property 3, and the reason this file exists at all: one workspace's sweep must
     * not touch another's. Every fixture row in the untouched tenant — including the
     * ones its OWN sweep would have deleted — must still be there.
     */
    public function test_one_tenants_sweep_never_touches_another_tenants_rows(): void
    {
        Storage::fake('screenshots');

        $a = $this->workspace('Alpha');
        $b = $this->workspace('Bravo');

        $this->prune($a['org']);

        foreach (array_keys($b['rows']) as $label) {
            $this->assertRowExists(
                $b['rows'],
                $label,
                'Pruning workspace A deleted a row owned by workspace B. This is the tenant:delete '.
                'forceDelete() bug in a different command: a destructive statement that lost its scope.'
            );
        }

        // Its files, too.
        Storage::disk('screenshots')->assertExists($b['org']->id.'/old.jpg');

        // …and the stale-item UPDATE is scoped as well, not just the DELETEs.
        $stale = MachineInventoryItem::withoutOrganizationScope()->find($b['rows']['inventory.stale_present'][1]);
        $this->assertTrue((bool) $stale->present, 'Workspace A\'s sweep flipped workspace B\'s inventory row.');
    }

    /**
     * Property 2. "Retention disabled" is a tenant setting, not a platform promise:
     * screenshots are genuinely kept (that is what the switch means), but the
     * unbounded PII tables keep their hard ceilings regardless — otherwise the
     * growth hole reopens per tenant, one checkbox at a time.
     */
    public function test_hard_ceilings_still_apply_when_the_tenant_disables_retention(): void
    {
        Storage::fake('screenshots');

        $a = $this->workspace('Forever', retentionDays: 0);

        $this->prune($a['org']);

        $rows = $a['rows'];

        // The tenant's explicit choice is honoured for the evidence table…
        $this->assertRowExists($rows, 'screenshot.old', 'retention_days = 0 means keep screenshots.');

        // …but not for the tables whose growth is unbounded and whose contents are PII.
        $this->assertRowGone($rows, 'sample.ancient', 'The 180-day sample ceiling applies even with retention off.');
        $this->assertRowExists($rows, 'sample.old', '90 days is inside the 180-day ceiling.');
        $this->assertRowGone($rows, 'diagnostic.old', 'The 30-day diagnostic ceiling applies even with retention off.');

        // The compliance / ledger ceilings are unconditional too.
        $this->assertRowGone($rows, 'inventory.removed_old');
        $this->assertRowGone($rows, 'machine.dead');
        $this->assertRowGone($rows, 'audit.old');
        $this->assertRowGone($rows, 'check.resolved_old');
    }

    /**
     * A tenant may configure a SHORTER retention than its plan allows, never a
     * longer one — otherwise the plan's retention limit is decorative.
     */
    public function test_retention_is_clamped_down_to_the_plans_entitlement(): void
    {
        Storage::fake('screenshots');

        // Asks for 60 days; entitled to 7.
        $capped = $this->workspace('Capped', retentionDays: 60, maxRetentionDays: 7);
        // Same fixture, entitled to plenty.
        $roomy = $this->workspace('Roomy', retentionDays: 60, maxRetentionDays: 3650);

        $this->prune($capped['org']);
        $this->prune($roomy['org']);

        // The 5-day-old screenshot survives either way; the difference is the
        // 90-day one, which both would delete. Use the sample table's 200/90/5
        // spread to see the clamp: at 7 days everything but the newest goes.
        $this->assertRowGone($capped['rows'], 'sample.old');
        $this->assertRowExists($capped['rows'], 'sample.recent');

        // …and prove the clamp bit, by showing a row that is INSIDE the requested
        // 60-day window but OUTSIDE the 7-day entitlement.
        $inBetween = app(CurrentOrganization::class)->runFor($capped['org'], function () use ($capped) {
            return TrackingActivitySample::create([
                'tracking_session_id' => TrackingActivitySample::withoutOrganizationScope()
                    ->find($capped['rows']['sample.recent'][1])->tracking_session_id,
                'user_id' => $capped['user']->id,
                'captured_at' => now()->subDays(20),
            ])->id;
        });

        $roomyInBetween = app(CurrentOrganization::class)->runFor($roomy['org'], function () use ($roomy) {
            return TrackingActivitySample::create([
                'tracking_session_id' => TrackingActivitySample::withoutOrganizationScope()
                    ->find($roomy['rows']['sample.recent'][1])->tracking_session_id,
                'user_id' => $roomy['user']->id,
                'captured_at' => now()->subDays(20),
            ])->id;
        });

        $this->prune($capped['org']);
        $this->prune($roomy['org']);

        $this->assertNull(
            TrackingActivitySample::withoutOrganizationScope()->find($inBetween),
            'A 20-day-old row must go for a workspace entitled to only 7 days, even though it asked for 60.'
        );
        $this->assertNotNull(
            TrackingActivitySample::withoutOrganizationScope()->find($roomyInBetween),
            'Control: the same row on a workspace with a wide entitlement must survive.'
        );
    }

    /** `--dry-run` must report without deleting — it is the only form CI ever runs. */
    public function test_a_dry_run_deletes_nothing_anywhere(): void
    {
        Storage::fake('screenshots');

        $a = $this->workspace('DryRun');

        $this->prune($a['org'], ['--dry-run' => true]);

        foreach (array_keys($a['rows']) as $label) {
            $this->assertRowExists($a['rows'], $label, '--dry-run must not delete.');
        }

        $stale = MachineInventoryItem::withoutOrganizationScope()->find($a['rows']['inventory.stale_present'][1]);
        $this->assertTrue((bool) $stale->present, '--dry-run must not run the stale-item UPDATE either.');
    }

    /**
     * One broken step must not cost the other five their retention — at 500 tenants a
     * single malformed row would otherwise silently disable the whole night's sweep
     * for that workspace and every workspace behind it in the pass.
     */
    public function test_one_failing_retention_step_does_not_stop_the_others(): void
    {
        Storage::fake('screenshots');

        $a = $this->workspace('Resilient');

        // Break exactly one step: drop the table it reads.
        DB::statement('DROP TABLE desktop_diagnostics');

        $this->prune($a['org']);

        $this->assertRowGone($a['rows'], 'screenshot.old', 'Later steps must still run after an earlier one throws.');
        $this->assertRowGone($a['rows'], 'audit.old');
        $this->assertRowGone($a['rows'], 'check.resolved_old');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // platform:prune — the landlord tables, which have no organization_id at all
    // and had no retention path anywhere before this phase.
    // ─────────────────────────────────────────────────────────────────────────

    private function seedPlatformTables(): void
    {
        DB::table('failed_jobs')->insert([
            ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'old', 'failed_at' => now()->subDays(60)],
            ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'recent', 'failed_at' => now()->subDays(3)],
        ]);

        DB::table('contact_submissions')->insert([
            ['name' => 'Old', 'email' => 'old@example.test', 'message' => 'old', 'created_at' => now()->subDays(400), 'updated_at' => now()->subDays(400)],
            ['name' => 'Recent', 'email' => 'recent@example.test', 'message' => 'recent', 'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)],
        ]);

        DB::table('password_reset_tokens')->insert([
            ['email' => 'stale@example.test', 'token' => Str::random(40), 'created_at' => now()->subDays(2)],
            ['email' => 'fresh@example.test', 'token' => Str::random(40), 'created_at' => now()->subMinutes(5)],
        ]);

        // NOTE: every row must carry the SAME keys. A multi-row `insert()` takes its
        // column list from the first row, so a row that omits one column silently
        // shifts its remaining values one slot to the left.
        $token = fn (string $name, ?\DateTimeInterface $expires, ?\DateTimeInterface $lastUsed, int $createdDaysAgo) => [
            'tokenable_type' => User::class,
            'tokenable_id' => 1,
            'name' => $name,
            'token' => hash('sha256', $name),
            'abilities' => '["*"]',
            'expires_at' => $expires,
            'last_used_at' => $lastUsed,
            'created_at' => now()->subDays($createdDaysAgo),
            'updated_at' => now()->subDays($createdDaysAgo),
        ];

        DB::table('personal_access_tokens')->insert([
            $token('expired', now()->subDay(), now()->subDays(2), 5),
            $token('never-used', null, null, 200),
            $token('abandoned', null, now()->subDays(200), 300),
            $token('in-use', null, now()->subHour(), 300),
        ]);

        DB::table('platform_audit_logs')->insert([
            ['action' => 'plan.updated', 'created_at' => now()->subDays(1200), 'updated_at' => now()->subDays(1200)],
            ['action' => 'plan.updated', 'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)],
        ]);
    }

    public function test_platform_prune_bounds_every_landlord_table(): void
    {
        $this->seedPlatformTables();

        $this->artisan('platform:prune')->assertExitCode(0);

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame('recent', DB::table('failed_jobs')->value('exception'));

        $this->assertSame(1, DB::table('contact_submissions')->count());
        $this->assertSame('Recent', DB::table('contact_submissions')->value('name'));

        // Laravel expires reset tokens logically but never deletes the rows.
        $this->assertSame(['fresh@example.test'], DB::table('password_reset_tokens')->pluck('email')->all());

        // Dead desktop-agent credentials are a security liability, not just size.
        $this->assertSame(['in-use'], DB::table('personal_access_tokens')->pluck('name')->all());

        $this->assertSame(1, DB::table('platform_audit_logs')->count());
    }

    public function test_platform_prune_dry_run_deletes_nothing(): void
    {
        $this->seedPlatformTables();

        $this->artisan('platform:prune', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(2, DB::table('failed_jobs')->count());
        $this->assertSame(2, DB::table('contact_submissions')->count());
        $this->assertSame(2, DB::table('password_reset_tokens')->count());
        $this->assertSame(4, DB::table('personal_access_tokens')->count());
        $this->assertSame(2, DB::table('platform_audit_logs')->count());
    }

    /**
     * The batching loop is the part every real run has so far skipped, because the
     * tables were empty. SQLite has no `DELETE … LIMIT`, so the sweep selects a page
     * of keys and deletes by key — an off-by-one there would leave a permanent
     * remainder, or loop forever. 2,001 rows against a 2,000-row batch exercises both
     * the multi-pass path and the final short page.
     */
    public function test_platform_prune_batching_loop_clears_more_rows_than_one_batch(): void
    {
        $rows = [];
        for ($i = 0; $i < 2001; $i++) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'bulk',
                'failed_at' => now()->subDays(90),
            ];

            if (count($rows) === 500) {
                DB::table('failed_jobs')->insert($rows);
                $rows = [];
            }
        }
        DB::table('failed_jobs')->insert($rows);

        $this->assertSame(2001, DB::table('failed_jobs')->count());

        $this->artisan('platform:prune')->assertExitCode(0);

        $this->assertSame(0, DB::table('failed_jobs')->count(), 'The batched delete left a remainder.');
    }

    /**
     * `platform:prune` must never be added to the per-tenant `tenant:run` pass: it is
     * landlord-scoped, so running it there would repeat the same global sweep 500
     * times a night. Asserting it is scope-independent is what makes that safe to
     * rely on.
     */
    public function test_platform_prune_is_landlord_scoped_and_ignores_the_bound_tenant(): void
    {
        $org = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);

        $this->seedPlatformTables();

        app(CurrentOrganization::class)->runFor($org, fn () => Artisan::call('platform:prune'));

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('platform_audit_logs')->count());
    }
}
