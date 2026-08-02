<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Database\Seeders\BigOrgSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Build the Phase 6 measurement fixture: one BIG tenant and one brand-new EMPTY
 * tenant. See {@see BigOrgSeeder} for what "big" means and why the data is
 * deliberately non-uniform.
 *
 * Refuses to run outside `local`/`testing` unless --force is passed: it writes
 * six figures of rows and creates accounts with a known password, which is not
 * something anyone should be able to do to production by tab-completion.
 */
class SeedBigOrg extends Command
{
    protected $signature = 'staffvora:seed-big-org
        {--users=120 : How many people the big workspace has (owner included)}
        {--months=6 : How many months of history to generate}
        {--screenshot-days=60 : Business days back that get screenshot rows (0 = none)}
        {--sample-days=14 : Business days back that get activity-sample rows (0 = none)}
        {--seed=20260803 : RNG seed, so two runs produce the same dataset}
        {--name=Northwind Global : Big workspace name}
        {--slug=big-org : Big workspace slug (also the owner email domain)}
        {--empty-name=Fresh Start Co : Empty workspace name}
        {--empty-slug=empty-org : Empty workspace slug}
        {--inventory-per-machine=0 : Inflate machine-compliance inventory to N rows per PC (0 = leave the seeder default). A real 100-PC fleet is ~350/PC; the Compliance page targets ~45,000 rows per workspace.}
        {--inventory-only : Skip seeding entirely and only top up the compliance inventory of the existing --slug workspace}
        {--no-empty : Skip the brand-new empty tenant}
        {--timezone=Asia/Karachi : Business timezone for both workspaces}
        {--fresh : Delete the two workspaces first if they already exist}
        {--force : Allow running outside the local/testing environment}';

    protected $description = 'Seed one realistic BIG tenant (100+ users, months of history) and one brand-new EMPTY tenant, for scale benchmarking.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Refusing to seed outside local/testing. Pass --force if you really mean it.');

            return self::FAILURE;
        }

        $startedAt = microtime(true);
        $bigSlug = (string) $this->option('slug');
        $emptySlug = (string) $this->option('empty-slug');

        // Top up an ALREADY-SEEDED workspace without paying for a full re-seed.
        // The seeder itself refuses to run twice against the same owner email, so
        // without this the inventory option would only be usable together with
        // --fresh — i.e. never, once you have a fixture you want to keep.
        if ($this->option('inventory-only')) {
            $organization = Organization::where('slug', $bigSlug)->first();

            if (! $organization) {
                $this->error("No workspace with slug '{$bigSlug}' to inflate. Seed one first.");

                return self::FAILURE;
            }

            $this->inflateInventory(
                $organization->id,
                max(1, (int) $this->option('inventory-per-machine')),
                (int) $this->option('seed'),
            );

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            foreach ([$bigSlug, $emptySlug] as $slug) {
                $organization = Organization::where('slug', $slug)->first();

                if ($organization) {
                    $this->line("Deleting existing workspace <comment>{$slug}</comment> (#{$organization->id})…");
                    $this->purge($organization);
                }
            }
        }

        $seeder = (new BigOrgSeeder)
            ->configure([
                'users' => (int) $this->option('users'),
                'months' => (int) $this->option('months'),
                'screenshot_days' => (int) $this->option('screenshot-days'),
                'sample_days' => (int) $this->option('sample-days'),
                'seed' => (int) $this->option('seed'),
                'name' => (string) $this->option('name'),
                'slug' => $bigSlug,
                'empty_name' => (string) $this->option('empty-name'),
                'empty_slug' => $emptySlug,
                'with_empty' => ! $this->option('no-empty'),
                'timezone' => (string) $this->option('timezone'),
            ])
            ->onProgress(function (string $message) use ($startedAt) {
                $this->line(sprintf('  <fg=gray>[%6.1fs]</> %s', microtime(true) - $startedAt, $message));
            });

        $this->info(sprintf(
            'Seeding a %d-user / %d-month workspace (screenshots: last %d days, activity samples: last %d days)…',
            (int) $this->option('users'),
            (int) $this->option('months'),
            (int) $this->option('screenshot-days'),
            (int) $this->option('sample-days'),
        ));

        try {
            $summary = $seeder->seed();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Done in '.$summary['seconds'].'s.');

        $perMachine = (int) $this->option('inventory-per-machine');

        if ($perMachine > 0) {
            $this->inflateInventory((int) $summary['big_organization_id'], $perMachine, (int) $this->option('seed'));
        }

        $rows = [];
        $total = 0;
        foreach ($this->liveCounts((int) $summary['big_organization_id']) as $table => $count) {
            $rows[] = [$table, number_format($count)];
            $total += $count;
        }
        $rows[] = ['<options=bold>TOTAL</>', '<options=bold>'.number_format($total).'</>'];

        $this->newLine();
        $this->line('<options=bold>Rows in the big workspace</>');
        $this->table(['Table', 'Rows'], $rows);

        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['Big workspace id', $summary['big_organization_id']],
            ['Big workspace login', $summary['big_owner_email']],
            ['Empty workspace id', $summary['empty_organization_id'] ?? '(skipped)'],
            ['Empty workspace login', $summary['empty_owner_email'] ?? '(skipped)'],
            ['Password (both)', $summary['password']],
            ['Seconds', $summary['seconds']],
            ['Rows written', number_format($total)],
        ]);

        $this->newLine();
        $this->line('Next: <comment>php artisan staffvora:benchmark --org='.$summary['big_organization_id'].'</comment>');
        $this->line('And the empty half of the acceptance test: <comment>php artisan staffvora:benchmark --org='.($summary['empty_organization_id'] ?? '?').'</comment>');

        return self::SUCCESS;
    }

    /**
     * Bring `machine_inventory_items` up to a REAL fleet's volume.
     *
     * The base fixture writes ~16 rows per PC, which is roughly a twentieth of
     * what an actual Windows machine reports: a corporate laptop enumerates
     * 200-400 installed programs plus every browser extension in every Chrome
     * profile. ComplianceController's own docblock sizes the page for "≈45,000
     * inventory rows per org" — so measuring the page against 1,976 rows was
     * measuring nothing, and the two GROUP BY views (By employee, By extension)
     * only show their true cost at the real cardinality.
     *
     * The generated names are deliberately NON-UNIFORM: a long tail of unique
     * per-machine programs on top of a shared core, because a GROUP BY over
     * `LOWER(name)` behaves completely differently when every row collapses into
     * 20 groups than when it produces thousands.
     *
     * Written with raw chunked `insert()`, which BYPASSES the BelongsToOrganization
     * stamping hook — so `organization_id` is set explicitly on every row. That is
     * the exact hazard the tenancy docs call out, and getting it wrong here would
     * silently produce rows no scoped read (and no tenant deletion, and no
     * retention sweep) could ever reach.
     */
    private function inflateInventory(int $organizationId, int $perMachine, int $seed): void
    {
        if (! Schema::hasTable('machine_inventory_items')) {
            return;
        }

        mt_srand($seed);

        $machines = DB::table('machines')
            ->where('organization_id', $organizationId)
            ->get(['id', 'user_id']);

        if ($machines->isEmpty()) {
            $this->warn('No machines to inflate.');

            return;
        }

        $startedAt = microtime(true);
        $now = now();
        $profiles = ['Default', 'Profile 1', 'Profile 2', 'Work'];
        $publishers = ['Microsoft Corporation', 'Google LLC', 'Adobe Inc.', 'JetBrains s.r.o.', 'Oracle', null];
        $written = 0;

        foreach ($machines as $machine) {
            $existing = (int) DB::table('machine_inventory_items')
                ->where('organization_id', $organizationId)
                ->where('machine_id', $machine->id)
                ->count();

            $needed = $perMachine - $existing;

            if ($needed <= 0) {
                continue;
            }

            $batch = [];

            for ($i = 0; $i < $needed; $i++) {
                // ~70% programs, 30% extensions — matching how a real report
                // splits, and keeping the extensions view's group count honest.
                $isExtension = ($i % 10) < 3;
                $kind = $isExtension ? 'extensions' : 'programs';

                // A shared core everyone has (collapses into few groups) plus a
                // per-machine long tail (thousands of groups). Both matter.
                $shared = $i % 3 === 0;
                $name = $shared
                    ? sprintf('%s Core %03d', $isExtension ? 'Ext' : 'App', $i % 60)
                    : sprintf('%s %s-%d-%d', $isExtension ? 'Extension' : 'Program', substr(md5($machine->id.'|'.$i), 0, 6), $machine->id, $i);

                $profile = $isExtension ? $profiles[$i % count($profiles)] : null;

                $batch[] = [
                    // EXPLICIT — insert() never fires the model's stamping hook.
                    'organization_id' => $organizationId,
                    'machine_id' => $machine->id,
                    'user_id' => $machine->user_id,
                    'kind' => $kind,
                    'signature' => $kind.'|'.mb_strtolower($name).'|'.($profile ?? ''),
                    'name' => $name,
                    'browser' => $isExtension ? 'chrome' : null,
                    'browser_profile' => $profile,
                    'publisher' => $publishers[$i % count($publishers)],
                    'version' => sprintf('%d.%d.%d', 1 + ($i % 9), $i % 20, $i % 50),
                    'detail' => null,
                    'enabled' => 1,
                    'flagged' => 0,
                    'rule' => null,
                    'severity' => null,
                    'alert' => 0,
                    'present' => 1,
                    'first_seen_at' => $now->copy()->subDays(30),
                    'last_seen_at' => $now->copy()->subHours($i % 48),
                    'removed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($batch) >= 500) {
                    DB::table('machine_inventory_items')->insert($batch);
                    $written += count($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                DB::table('machine_inventory_items')->insert($batch);
                $written += count($batch);
            }
        }

        $total = (int) DB::table('machine_inventory_items')->where('organization_id', $organizationId)->count();

        $this->line(sprintf(
            '  <fg=gray>[%6.1fs]</> Inflated compliance inventory: +%s row(s), %s total across %d machine(s).',
            microtime(true) - $startedAt,
            number_format($written),
            number_format($total),
            $machines->count(),
        ));
    }

    /**
     * Delete ONE workspace's rows, by explicit `organization_id`, children
     * first.
     *
     * DELIBERATELY NOT `tenant:delete`. That command purges soft-deleting models
     * with `$model::query()->forceDelete()`, and `Builder::forceDelete()` runs
     * `$this->query->delete()` on the BASE query builder — which never applies
     * the OrganizationScope. Running it therefore empties `tracking_screenshots`
     * for EVERY tenant, not just the target. That is reported as a Phase 6
     * finding; until it is fixed, a routine `--fresh` re-seed must not be able
     * to detonate it. See the Phase 6 report.
     */
    private function purge(Organization $organization): void
    {
        $id = $organization->id;

        // Pivot first: it has no organization_id of its own.
        if (Schema::hasTable('client_upwork_profile')) {
            DB::table('client_upwork_profile')
                ->whereIn('client_id', DB::table('clients')->where('organization_id', $id)->pluck('id'))
                ->delete();
        }

        // Children before parents — mirrors DeleteTenant's documented ordering,
        // which exists because these tables carry real FKs on MySQL.
        $tables = [
            'tracking_screenshots', 'tracking_activity_samples', 'tracking_sessions', 'tracking_audit_logs',
            'timesheet_approvals', 'work_hours', 'timesheets', 'time_entries', 'attendance_clock_checks',
            'manual_attendance_marks', 'manual_attendance_audits', 'shift_overrides', 'shifts',
            'feedback_messages', 'feedback_items', 'machine_flags', 'machine_inventory_items',
            'machine_blocklist_rules', 'machines', 'desktop_diagnostics', 'payments', 'billing_profiles',
            'organization_addon', 'subscriptions', 'clients', 'upwork_profiles', 'work_categories',
            'designations', 'user_monitoring_settings', 'monitoring_settings', 'users',
        ];

        DB::transaction(function () use ($tables, $id) {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                try {
                    DB::table($table)->where('organization_id', $id)->delete();
                } catch (\Throwable $e) {
                    // A table without an organization_id column is simply skipped.
                }
            }
        });

        // `teams` is deleted after users so users.team_id never dangles mid-purge.
        if (Schema::hasTable('teams')) {
            DB::table('teams')->where('organization_id', $id)->delete();
        }

        DB::table('organizations')->where('id', $id)->delete();
    }

    /**
     * Count what is ACTUALLY in the database for this tenant, rather than
     * trusting the seeder's own tally — a row the seeder thought it wrote but a
     * constraint rejected must not be reported as written.
     *
     * @return array<string,int>
     */
    private function liveCounts(int $organizationId): array
    {
        $tables = [
            'users', 'teams', 'designations', 'shifts', 'shift_overrides', 'clients', 'upwork_profiles',
            'work_categories', 'time_entries', 'manual_attendance_marks', 'work_hours', 'tracking_sessions',
            'tracking_screenshots', 'tracking_activity_samples', 'timesheets', 'timesheet_approvals',
            'machines', 'machine_inventory_items', 'machine_flags', 'machine_blocklist_rules',
            'desktop_diagnostics', 'feedback_items', 'feedback_messages', 'payments',
            'user_monitoring_settings',
        ];

        $out = [];
        foreach ($tables as $table) {
            try {
                $out[$table] = (int) DB::table($table)->where('organization_id', $organizationId)->count();
            } catch (\Throwable $e) {
                // A table that does not exist in this schema is simply not counted.
            }
        }

        return $out;
    }
}
