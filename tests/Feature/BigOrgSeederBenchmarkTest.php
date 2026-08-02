<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Smoke cover for the Phase 6 measurement tooling: the big-org seeder and the
 * benchmark harness.
 *
 * Everything here runs at TINY counts. The point is not to measure anything —
 * the point is that the two tools still WORK, because a benchmark nobody can run
 * is worse than no benchmark. Specifically it pins:
 *
 *  - the seeder produces both halves of the M6 acceptance test (a populated
 *    workspace AND a brand-new empty one);
 *  - every bulk-inserted row carries organization_id. `insert()` bypasses the
 *    BelongsToOrganization stamp hook, so a forgotten stamp would silently
 *    produce rows that are invisible to the tenant that owns them — and a
 *    benchmark against invisible rows measures nothing;
 *  - the two tenants are isolated from each other;
 *  - the benchmark reaches every surface it claims to and gets a 2xx on each,
 *    with a query count and a wall-clock reading per surface.
 */
class BigOrgSeederBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    /** Tables the seeder bulk-inserts into; each must be fully org-stamped. */
    private const STAMPED_TABLES = [
        'users', 'teams', 'designations', 'shifts', 'clients', 'upwork_profiles', 'work_categories',
        'time_entries', 'work_hours', 'tracking_sessions', 'tracking_screenshots',
        'tracking_activity_samples', 'manual_attendance_marks', 'timesheets', 'timesheet_approvals',
        'machines', 'machine_inventory_items', 'machine_flags', 'desktop_diagnostics',
        'feedback_items', 'feedback_messages', 'payments', 'user_monitoring_settings',
    ];

    private function seedTiny(): void
    {
        $exit = Artisan::call('staffvora:seed-big-org', [
            '--users' => 4,
            '--months' => 1,
            '--screenshot-days' => 2,
            '--sample-days' => 1,
            '--slug' => 'test-big',
            '--empty-slug' => 'test-empty',
            '--name' => 'Test Big',
            '--empty-name' => 'Test Empty',
        ]);

        $this->assertSame(0, $exit, "seed-big-org failed:\n".Artisan::output());
    }

    public function test_seeder_builds_a_populated_tenant_and_a_brand_new_empty_one(): void
    {
        $this->seedTiny();

        $big = Organization::where('slug', 'test-big')->first();
        $empty = Organization::where('slug', 'test-empty')->first();

        $this->assertNotNull($big, 'the big workspace was not created');
        $this->assertNotNull($empty, 'the empty workspace was not created');

        // The populated half.
        $this->assertSame(4, DB::table('users')->where('organization_id', $big->id)->count());
        foreach (['time_entries', 'work_hours', 'tracking_sessions', 'tracking_screenshots',
            'tracking_activity_samples', 'timesheets', 'machine_inventory_items', 'feedback_items'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->where('organization_id', $big->id)->count(), "{$table} is empty");
        }

        // The empty half: an owner and the provisioning defaults, and NOTHING else.
        $this->assertSame(1, DB::table('users')->where('organization_id', $empty->id)->count());
        foreach (['time_entries', 'work_hours', 'tracking_sessions', 'tracking_screenshots', 'timesheets'] as $table) {
            $this->assertSame(0, DB::table($table)->where('organization_id', $empty->id)->count(), "{$table} should be empty");
        }

        // Both workspaces must be usable — a lapsed trial would make every
        // benchmarked surface a redirect instead of a measurement.
        $this->assertTrue($big->isActive());
        $this->assertTrue($empty->isActive());
    }

    /**
     * The stamp guard. Bulk `insert()` never fires the BelongsToOrganization
     * `creating` hook, so this is the one mistake that makes a "seeded" dataset
     * quietly unmeasurable.
     */
    public function test_every_bulk_inserted_row_is_organization_stamped(): void
    {
        // Some tables legitimately hold platform-level rows with a NULL
        // organization_id (the shared blocklist baseline, migration-seeded
        // designations). The assertion is therefore that the SEEDER added none,
        // not that the table contains none.
        $before = [];
        foreach (self::STAMPED_TABLES as $table) {
            $before[$table] = DB::table($table)->whereNull('organization_id')->count();
        }

        $this->seedTiny();

        foreach (self::STAMPED_TABLES as $table) {
            $after = DB::table($table)->whereNull('organization_id')->count();
            $this->assertSame(
                $before[$table],
                $after,
                sprintf('the seeder added %d un-stamped row(s) to %s', $after - $before[$table], $table)
            );
        }
    }

    public function test_the_two_seeded_tenants_cannot_see_each_other(): void
    {
        $this->seedTiny();

        $big = Organization::where('slug', 'test-big')->firstOrFail();
        $empty = Organization::where('slug', 'test-empty')->firstOrFail();

        $emptyOwner = User::withoutOrganizationScope()->where('organization_id', $empty->id)->firstOrFail();

        $this->actingAs($emptyOwner);

        $this->assertSame(
            0,
            \App\Models\WorkHour::query()->whereIn(
                'user_id',
                DB::table('users')->where('organization_id', $big->id)->pluck('id')
            )->count(),
            'the empty workspace can read the big workspace\'s work entries'
        );
    }

    public function test_benchmark_reaches_every_surface_and_reports_queries_and_milliseconds(): void
    {
        $this->seedTiny();

        $big = Organization::where('slug', 'test-big')->firstOrFail();

        // Thresholds are deliberately absurd: this test is about the harness
        // working, not about how fast a 4-user in-memory SQLite database is.
        $exit = Artisan::call('staffvora:benchmark', [
            '--org' => (string) $big->id,
            '--repeat' => '1',
            '--warmup' => '1',
            '--query-threshold' => '100000',
            '--ms-threshold' => '100000',
            '--json' => true,
        ]);

        $output = Artisan::output();
        $this->assertSame(0, $exit, "benchmark failed:\n".$output);

        $payload = json_decode($output, true);
        $this->assertIsArray($payload, "benchmark did not emit JSON:\n".$output);
        $this->assertSame($big->id, $payload['organization']['id']);
        $this->assertGreaterThanOrEqual(20, count($payload['surfaces']), 'the harness covers fewer surfaces than it claims');

        foreach ($payload['surfaces'] as $surface) {
            $this->assertSame(
                200,
                $surface['status'],
                sprintf('%s (%s) returned HTTP %d', $surface['name'], $surface['uri'], $surface['status'])
            );
            $this->assertGreaterThan(0, $surface['queries'], $surface['name'].' reported no queries at all');
            $this->assertGreaterThan(0, $surface['ms'], $surface['name'].' reported no wall-clock time');
        }

        // The surfaces the plan names explicitly must all be present.
        $names = array_column($payload['surfaces'], 'name');
        foreach (['Dashboard', 'today-summary', 'trend', 'monthly grid', 'Timesheets', 'Insights',
            'Reports', 'Team', 'Timeline', 'Users list', 'Clients list', 'Work diary',
            'Monitoring sessions', 'Compliance', 'Billing hub'] as $needle) {
            $this->assertTrue(
                collect($names)->contains(fn (string $name) => str_contains($name, $needle)),
                "the benchmark does not cover a surface matching '{$needle}'"
            );
        }

        $this->assertGreaterThan(0, $payload['totals']['queries']);
        $this->assertGreaterThan(0, $payload['totals']['ms']);
    }

    public function test_benchmark_runs_against_a_brand_new_empty_workspace_too(): void
    {
        $this->seedTiny();

        $empty = Organization::where('slug', 'test-empty')->firstOrFail();

        $exit = Artisan::call('staffvora:benchmark', [
            '--org' => 'test-empty',
            '--repeat' => '1',
            '--warmup' => '0',
            '--query-threshold' => '100000',
            '--ms-threshold' => '100000',
            '--json' => true,
        ]);

        $output = Artisan::output();
        $this->assertSame(0, $exit, "benchmark failed on the empty workspace:\n".$output);

        $payload = json_decode($output, true);
        $this->assertSame($empty->id, $payload['organization']['id']);
        $this->assertSame(1, $payload['scale']['users']);

        foreach ($payload['surfaces'] as $surface) {
            $this->assertSame(
                200,
                $surface['status'],
                sprintf('empty workspace: %s (%s) returned HTTP %d', $surface['name'], $surface['uri'], $surface['status'])
            );
        }
    }
}
