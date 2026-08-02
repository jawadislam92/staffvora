<?php

namespace Tests\Feature;

use App\Models\ManualAttendanceAudit;
use App\Models\ManualAttendanceMark;
use App\Models\Organization;
use App\Models\TrackingActivitySample;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the bulk-write / tenancy / retention hardening.
 *
 * Two classes of hole are pinned here:
 *
 *  1. TENANT STAMPING ON BULK WRITES. upsert()/insert() go straight to the query
 *     builder and fire NO model events, so BelongsToOrganization's `creating`
 *     hook never runs. Any bulk rewrite that forgets to put organization_id in
 *     the row array writes NULL, and OrganizationScope's `where organization_id
 *     = ?` then hides those rows from their own tenant forever. That is the
 *     exact trap the naive version of this refactor falls into, so it is
 *     asserted directly.
 *
 *  2. UNBOUNDED WORK PER REQUEST. The calendar endpoint capped the date span but
 *     not the number of people, so one admin click could mean six figures of
 *     queries in a single transaction.
 */
class ScaleHardeningBulkWritesTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name, string $email): array
    {
        $result = app(TenantProvisioner::class)->provision($name, 'Owner '.$name, $email, 'super-secret-pw');

        return [$result['owner']->organization, $result['owner']];
    }

    private function employee(Organization $org, string $name): User
    {
        return app(CurrentOrganization::class)->runFor($org, fn () => User::create([
            'name' => $name,
            'email' => str($name)->slug().'@'.$org->id.'.test',
            'password' => bcrypt('password'),
            'role' => 'member',
        ]));
    }

    public function test_calendar_apply_stamps_organization_id_on_marks_and_audits(): void
    {
        [$org, $owner] = $this->tenant('Acme', 'owner@acme.test');
        $employee = $this->employee($org, 'Bulk Apply User');

        $this->actingAs($owner)
            ->postJson(route('employee-attendance.calendar'), [
                'operation' => 'apply',
                'scope' => 'selected',
                'user_ids' => [$employee->id],
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-12',
                'status_code' => 'H',
                'note' => 'Company holiday',
            ])
            ->assertOk()
            ->assertJsonPath('affected', 3);

        // The rows must be readable THROUGH the scope, which is only true if
        // organization_id was written. A count of 0 here is the bug.
        app(CurrentOrganization::class)->set($org);
        $this->assertSame(3, ManualAttendanceMark::query()->where('organization_id', $org->id)->count());
        $this->assertSame(3, ManualAttendanceAudit::query()->where('organization_id', $org->id)->count());
        $this->assertSame(0, ManualAttendanceMark::query()->whereNull('organization_id')->count());
        $this->assertSame(0, ManualAttendanceAudit::query()->whereNull('organization_id')->count());
    }

    public function test_calendar_clear_stamps_organization_id_on_its_audit_rows(): void
    {
        [$org, $owner] = $this->tenant('Beta', 'owner@beta.test');
        $employee = $this->employee($org, 'Bulk Clear User');

        $this->actingAs($owner)
            ->postJson(route('employee-attendance.calendar'), [
                'operation' => 'apply',
                'scope' => 'selected',
                'user_ids' => [$employee->id],
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-11',
                'status_code' => 'L',
            ])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson(route('employee-attendance.calendar'), [
                'operation' => 'clear',
                'scope' => 'selected',
                'user_ids' => [$employee->id],
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-11',
            ])
            ->assertOk()
            ->assertJsonPath('affected', 2);

        app(CurrentOrganization::class)->set($org);
        $this->assertSame(0, ManualAttendanceMark::query()->count());
        // 2 from the apply + 2 clearing audits, all stamped.
        $this->assertSame(4, ManualAttendanceAudit::query()->where('organization_id', $org->id)->count());
        $this->assertSame(2, ManualAttendanceAudit::query()->whereNull('new_status_code')->count());
    }

    /**
     * The bulk path skips attribute casting, so it must write attendance_date in
     * the SAME textual form the single-day Eloquent path uses. If it does not,
     * the (user_id, attendance_date) unique index stops matching on SQLite: the
     * upsert inserts a duplicate mark for a day that is already marked, and the
     * "audit only on a real change" rule starts logging phantom changes.
     */
    public function test_bulk_apply_updates_a_mark_written_by_the_single_day_path(): void
    {
        [$org, $owner] = $this->tenant('Format Co', 'owner@format.test');
        $employee = $this->employee($org, 'Format User');

        $this->actingAs($owner)
            ->patchJson(route('employee-attendance.manual-status'), [
                'user_id' => $employee->id,
                'date' => '2026-06-10',
                'status_code' => 'PH',
            ])
            ->assertOk();

        app(CurrentOrganization::class)->set($org);
        $this->assertSame(1, ManualAttendanceMark::query()->count());
        $auditsAfterSingle = ManualAttendanceAudit::query()->count();

        // Same day, same status, via the bulk path: it must UPDATE the existing
        // row and record no change.
        $this->actingAs($owner)
            ->postJson(route('employee-attendance.calendar'), [
                'operation' => 'apply',
                'scope' => 'selected',
                'user_ids' => [$employee->id],
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-10',
                'status_code' => 'PH',
            ])
            ->assertOk()
            ->assertJsonPath('affected', 1);

        app(CurrentOrganization::class)->set($org);
        $this->assertSame(1, ManualAttendanceMark::query()->count(), 'The bulk upsert duplicated an existing mark.');
        $this->assertSame($auditsAfterSingle, ManualAttendanceAudit::query()->count(), 'An unchanged status must not be audited.');
    }

    public function test_calendar_ignores_user_ids_belonging_to_another_organization(): void
    {
        [$orgA, $ownerA] = $this->tenant('Alpha Co', 'owner@alpha.test');
        [$orgB] = $this->tenant('Bravo Co', 'owner@bravo.test');

        $mine = $this->employee($orgA, 'My Person');
        $theirs = $this->employee($orgB, 'Their Person');

        $this->actingAs($ownerA)
            ->postJson(route('employee-attendance.calendar'), [
                'operation' => 'apply',
                'scope' => 'selected',
                'user_ids' => [$mine->id, $theirs->id],
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-10',
                'status_code' => 'PH',
            ])
            ->assertOk()
            // Only my own person is applied — the foreign id is dropped by the
            // scoped re-derivation, not merely "validated" by a global exists rule.
            ->assertJsonPath('affected', 1);

        app(CurrentOrganization::class)->runUnscoped(function () use ($theirs) {
            $this->assertSame(
                0,
                ManualAttendanceMark::query()->withoutOrganizationScope()->where('user_id', $theirs->id)->count()
            );
        });
    }

    public function test_calendar_refuses_a_selection_larger_than_the_person_day_cap(): void
    {
        [$org, $owner] = $this->tenant('Huge Co', 'owner@huge.test');

        // 20 people x 366 days = 7,320 person-days, over the 5,000 cap.
        for ($i = 0; $i < 20; $i++) {
            $this->employee($org, 'Cap User '.$i);
        }

        $this->actingAs($owner)
            ->postJson(route('employee-attendance.calendar'), [
                'operation' => 'apply',
                'scope' => 'company',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status_code' => 'H',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');

        $this->assertSame(0, ManualAttendanceMark::query()->count());
    }

    public function test_calendar_rejects_more_selected_users_than_the_array_cap(): void
    {
        [, $owner] = $this->tenant('Array Cap Co', 'owner@arraycap.test');

        $this->actingAs($owner)
            ->postJson(route('employee-attendance.calendar'), [
                'operation' => 'apply',
                'scope' => 'selected',
                'user_ids' => range(1, 201),
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-10',
                'status_code' => 'H',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_ids');
    }

    /**
     * The desktop agent writes samples with a mass `insert()`, which fires no
     * model events, so every historical row landed with organization_id = NULL
     * and became invisible to its own tenant (and to the pruner, and to tenant
     * deletion). This exercises the repair migration's backfill against the real
     * database — including that the correlated-subquery UPDATE form is valid on
     * SQLite, which the MySQL-only `UPDATE ... JOIN` form is not.
     */
    public function test_the_repair_migration_backfills_unstamped_activity_samples(): void
    {
        [$org, $owner] = $this->tenant('Backfill Co', 'owner@backfill.test');
        app(CurrentOrganization::class)->set($org);

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => 'uuid-backfill',
            'started_at' => now()->subHour(),
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        // Exactly how the desktop endpoint writes them: straight to the query
        // builder, no events, no tenant stamp.
        TrackingActivitySample::query()->insert([
            [
                'tracking_session_id' => $session->id,
                'user_id' => $owner->id,
                'captured_at' => now()->subMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Invisible to its own tenant before the repair.
        $this->assertSame(0, TrackingActivitySample::query()->count());

        $migration = require database_path('migrations/2026_07_30_000000_backfill_and_index_tracking_activity_samples.php');
        $migration->up();

        $this->assertSame(1, TrackingActivitySample::query()->where('organization_id', $org->id)->count());
    }

    public function test_activity_samples_are_pruned_on_retention_and_recent_ones_survive(): void
    {
        [$org, $owner] = $this->tenant('Retention Co', 'owner@retention.test');
        app(CurrentOrganization::class)->set($org);

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => 'uuid-sample-retention',
            'started_at' => now()->subDays(200),
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        $old = TrackingActivitySample::create([
            'tracking_session_id' => $session->id,
            'user_id' => $owner->id,
            'captured_at' => now()->subDays(120),
        ]);

        $recent = TrackingActivitySample::create([
            'tracking_session_id' => $session->id,
            'user_id' => $owner->id,
            'captured_at' => now()->subDays(2),
        ]);

        $this->artisan('monitoring:prune-screenshots', ['--days' => 60])->assertExitCode(0);

        $this->assertSame(0, TrackingActivitySample::query()->whereKey($old->id)->count());
        $this->assertSame(1, TrackingActivitySample::query()->whereKey($recent->id)->count());
    }

    public function test_activity_sample_retention_still_applies_when_a_tenant_disables_retention(): void
    {
        [$org, $owner] = $this->tenant('Forever Co', 'owner@forever.test');
        app(CurrentOrganization::class)->set($org);

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => 'uuid-sample-ceiling',
            'started_at' => now()->subYears(2),
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        // Past the 180-day platform ceiling, but the tenant asked to keep forever.
        $ancient = TrackingActivitySample::create([
            'tracking_session_id' => $session->id,
            'user_id' => $owner->id,
            'captured_at' => now()->subDays(400),
        ]);

        $this->artisan('monitoring:prune-screenshots', ['--days' => 0])->assertExitCode(0);

        $this->assertSame(
            0,
            TrackingActivitySample::query()->whereKey($ancient->id)->count(),
            'Retention "disabled" must still be bounded by the platform ceiling for activity samples.'
        );
    }

    public function test_activity_samples_with_a_forged_future_capture_time_still_prune(): void
    {
        [$org, $owner] = $this->tenant('Forgery Co', 'owner@forgery.test');
        app(CurrentOrganization::class)->set($org);

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => 'uuid-sample-forged',
            'started_at' => now()->subDays(400),
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        // captured_at is client-supplied by the desktop agent. A far-future value
        // must not make the row immortal — retention keys on created_at too.
        $forged = TrackingActivitySample::create([
            'tracking_session_id' => $session->id,
            'user_id' => $owner->id,
            'captured_at' => now()->addYears(50),
        ]);
        $forged->forceFill(['created_at' => now()->subDays(400)])->saveQuietly();

        $this->artisan('monitoring:prune-screenshots', ['--days' => 30])->assertExitCode(0);

        $this->assertSame(0, TrackingActivitySample::query()->whereKey($forged->id)->count());
    }
}
