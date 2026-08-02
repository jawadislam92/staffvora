<?php

namespace App\Console\Commands;

use App\Models\AttendanceClockCheck;
use App\Models\BillingProfile;
use App\Models\Client;
use App\Models\Designation;
use App\Models\DesktopDiagnostic;
use App\Models\Feedback;
use App\Models\FeedbackMessage;
use App\Models\Machine;
use App\Models\MachineBlocklistRule;
use App\Models\MachineFlag;
use App\Models\MachineInventoryItem;
use App\Models\ManualAttendanceAudit;
use App\Models\ManualAttendanceMark;
use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PlatformAuditLog;
use App\Models\Shift;
use App\Models\ShiftOverride;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\TimesheetApproval;
use App\Models\TrackingActivitySample;
use App\Models\TrackingAuditLog;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\UpworkProfile;
use App\Models\User;
use App\Models\UserMonitoringSetting;
use App\Models\WorkCategory;
use App\Models\WorkHour;
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

    /**
     * Purge order — children before parents to respect FKs.
     *
     * This stays an EXPLICIT ORDERED array rather than a reflection sweep over
     * BelongsToOrganization users: the pre-convention tracking/attendance tables
     * carry real database FKs (work_hours.user_id, time_entries.user_id,
     * tracking_screenshots.tracking_session_id, manual_attendance_marks.user_id
     * …), so an arbitrary order can violate constraints on MySQL even where
     * SQLite tolerates it. The ordering is not derivable; the drift risk is
     * covered by TenantDeletionTest, which fails the build the moment a new
     * tenant-owned model is added without being listed here.
     *
     * Note FeedbackMessage must be listed in its own right: the purge uses a
     * MASS delete, which never fires Eloquent model events, so the `deleting`
     * cascade on Feedback is dead code in this path.
     *
     * @var array<int, class-string>
     */
    private static array $models = [
        TrackingScreenshot::class,
        TrackingActivitySample::class,
        TrackingSession::class,
        TrackingAuditLog::class,
        // Children first: the approval trail points at a timesheet, and
        // work_hours.timesheet_id points at one too — so the trail, then the
        // work entries, and only then the timesheets themselves.
        TimesheetApproval::class,
        WorkHour::class,
        Timesheet::class,
        TimeEntry::class,
        AttendanceClockCheck::class,
        ManualAttendanceMark::class,
        ManualAttendanceAudit::class,
        ShiftOverride::class,
        Shift::class,
        FeedbackMessage::class,
        Feedback::class,
        MachineFlag::class,
        MachineInventoryItem::class,
        MachineBlocklistRule::class,
        Machine::class,
        DesktopDiagnostic::class,
        Payment::class,
        BillingProfile::class,
        Subscription::class,
        Client::class,
        UpworkProfile::class,
        WorkCategory::class,
        Designation::class,
        Team::class,
        UserMonitoringSetting::class,
        MonitoringSetting::class,
        User::class,
    ];

    /**
     * Tenant-owned models that are deliberately NOT purged here, with the reason.
     * The drift-guard test consults this so an intentional exemption is a
     * documented decision rather than a silent omission.
     *
     * @var array<class-string, string>
     */
    private static array $exempt = [
        // Erasure must not destroy the platform's own audit trail of what was
        // done to the tenant; it is landlord data that happens to reference an
        // organization_id, and it is retained by design.
        PlatformAuditLog::class => 'Landlord audit trail, retained deliberately.',
    ];

    /**
     * The purge list, for the drift-guard test.
     *
     * @return array<int, class-string>
     */
    public static function models(): array
    {
        return self::$models;
    }

    /**
     * @return array<class-string, string>
     */
    public static function exemptModels(): array
    {
        return self::$exempt;
    }

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

        $orgId = $org->id;

        $current->runFor($org, function () use ($orgId) {
            foreach (self::$models as $model) {
                if (! class_exists($model)) {
                    continue;
                }

                // The organization predicate is EXPLICIT and non-negotiable here.
                //
                // Eloquent\Builder::forceDelete() is literally `$this->query->delete()`
                // — it reaches the BASE query builder directly, so applyScopes() never
                // runs. That drops the SoftDeletingScope *and* the OrganizationScope,
                // turning this into an unqualified `DELETE FROM <table>` that wipes
                // EVERY tenant's rows. runFor() cannot save us: the scope it binds is
                // exactly the one forceDelete() discards.
                //
                // This was live and destroyed one tenant's screenshots when an
                // unrelated tenant was erased. TrackingScreenshot is currently the only
                // SoftDeletes model in $models and it sorts FIRST, so it fired on the
                // first iteration of every erasure. Adding SoftDeletes to any other
                // tenant model would have silently joined the blast radius.
                //
                // Scoping by hand means both paths are safe regardless of which scopes
                // a given builder method happens to honour.
                $query = $model::query()->where('organization_id', $orgId);

                $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
                $usesSoftDeletes ? $query->forceDelete() : $query->delete();
            }
        });

        $name = $org->name;
        $org->delete();

        $this->info("Deleted tenant '{$name}' and all of its data.");

        return self::SUCCESS;
    }
}
