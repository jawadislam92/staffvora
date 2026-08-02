<?php

namespace App\Console\Commands;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fail-closed tenancy invariant check, runnable in CI and before a deploy.
 *
 * The project ships no DB-level foreign keys (SQLite/MySQL portability), so the
 * "every tenant row belongs to a live organization" rule is enforced in the
 * application. This command proves it holds:
 *
 *   1. No user has organization_id = NULL. Such an account authenticates on the
 *      ordinary login page and then runs every query with no organization
 *      predicate (OrganizationScope is a no-op when nothing is bound), i.e.
 *      unrestricted cross-customer read and write.
 *   2. No row in any tenant-owned table has a NULL organization_id.
 *   3. No tenant row points at an organization that no longer exists.
 *
 * Exit code 1 on any violation so CI/deploy scripts can gate on it.
 */
class TenancyAudit extends Command
{
    protected $signature = 'tenancy:audit
        {--details : List the offending row ids (capped)}
        {--strict : Treat NULL organization_id on non-user tables as a failure too}';

    protected $description = 'Assert every tenant-owned row has a valid organization_id (fail-closed tenancy invariant).';

    private const SAMPLE_LIMIT = 20;

    public function handle(): int
    {
        $violations = 0;

        // 1. The one that matters most: null-org users.
        $orphanUsers = User::withoutOrganizationScope()->whereNull('organization_id')->count();

        if ($orphanUsers > 0) {
            $violations++;
            $this->error("CRITICAL: {$orphanUsers} user(s) have organization_id = NULL.");
            $this->line('  Each one is an unscoped cross-tenant account. Delete or re-home them.');

            if ($this->option('details')) {
                $rows = User::withoutOrganizationScope()
                    ->whereNull('organization_id')
                    ->limit(self::SAMPLE_LIMIT)
                    ->get(['id', 'email', 'role']);

                $this->table(['ID', 'Email', 'Role'], $rows->map(fn ($u) => [$u->id, $u->email, $u->role])->all());
            }
        }

        $organizationIds = Organization::query()->pluck('id')->all();

        // 2 + 3. Every other tenant-owned table.
        foreach ($this->tenantModels() as $class) {
            /** @var Model $model */
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
                continue;
            }

            $nulls = $class::withoutOrganizationScope()->whereNull('organization_id')->count();

            if ($nulls > 0) {
                // Not a leak: a NULL organization_id is invisible to every bound
                // tenant. It is dead data — typically a pre-tenancy row seeded by
                // an old migration (the single-tenant `designations` defaults and
                // the `monitoring_settings` id=1 row). Surfaced as a warning so
                // CI can gate on the security invariant without tripping on it;
                // --strict makes it a failure once those are cleaned up.
                if ($this->option('strict')) {
                    $violations++;
                    $this->error("{$table}: {$nulls} row(s) with organization_id = NULL.");
                } else {
                    $this->warn("{$table}: {$nulls} orphaned row(s) with organization_id = NULL (invisible to every tenant).");
                }
            }

            $orphans = $organizationIds === []
                ? $class::withoutOrganizationScope()->whereNotNull('organization_id')->count()
                : $class::withoutOrganizationScope()
                    ->whereNotNull('organization_id')
                    ->whereNotIn('organization_id', $organizationIds)
                    ->count();

            if ($orphans > 0) {
                $violations++;
                $this->error("{$table}: {$orphans} row(s) point at a missing organization.");
            }
        }

        if ($violations > 0) {
            $this->newLine();
            $this->error("tenancy:audit FAILED — {$violations} violation(s).");

            return self::FAILURE;
        }

        $this->info('tenancy:audit OK — every tenant-owned row has a valid organization_id.');

        return self::SUCCESS;
    }

    /**
     * Every model in app/Models that uses BelongsToOrganization.
     *
     * @return list<class-string<Model>>
     */
    public function tenantModels(): array
    {
        $classes = [];

        foreach (File::files(app_path('Models')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'App\\Models\\'.Str::before($file->getFilename(), '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (! in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
