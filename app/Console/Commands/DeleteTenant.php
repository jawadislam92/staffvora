<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * GDPR "right to erasure": permanently delete a tenant and ALL of its data.
 * Each tenant model is purged bound to the org (so only that org's rows go),
 * then the organization itself. Irreversible — guarded by a confirmation.
 *
 * Note: S3 objects are org-prefixed (org/{id}/...) and should be removed by the
 * object-store lifecycle/bucket cleanup; this command clears the database.
 */
class DeleteTenant extends Command
{
    protected $signature = 'tenant:delete {org : Organization id or slug} {--force : Skip the confirmation prompt}';

    protected $description = 'Permanently delete a tenant and all of its data (GDPR erasure).';

    /** Purge order — children before parents to respect FKs. */
    private array $models = [
        \App\Models\TrackingScreenshot::class,
        \App\Models\TrackingActivitySample::class,
        \App\Models\TrackingSession::class,
        \App\Models\TrackingAuditLog::class,
        \App\Models\WorkHour::class,
        \App\Models\TimeEntry::class,
        \App\Models\AttendanceClockCheck::class,
        \App\Models\ManualAttendanceMark::class,
        \App\Models\ManualAttendanceAudit::class,
        \App\Models\Payment::class,
        \App\Models\Subscription::class,
        \App\Models\Client::class,
        \App\Models\UpworkProfile::class,
        \App\Models\Designation::class,
        \App\Models\Team::class,
        \App\Models\UserMonitoringSetting::class,
        \App\Models\MonitoringSetting::class,
        \App\Models\User::class,
    ];

    public function handle(CurrentOrganization $current): int
    {
        $org = Organization::where('id', $this->argument('org'))
            ->orWhere('slug', $this->argument('org'))
            ->first();

        if (! $org) {
            $this->error("No organization matching '{$this->argument('org')}'.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Permanently delete '{$org->name}' (#{$org->id}) and ALL its data? This cannot be undone.")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $current->runFor($org, function () {
            foreach ($this->models as $model) {
                $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
                $query = $model::query();
                $usesSoftDeletes ? $query->forceDelete() : $query->delete();
            }
        });

        $name = $org->name;
        $org->delete();

        $this->info("Deleted tenant '{$name}' and all of its data.");

        return self::SUCCESS;
    }
}
