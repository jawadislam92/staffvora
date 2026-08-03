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
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * GDPR "right to erasure": permanently delete a tenant and ALL of its data —
 * database rows AND the files it uploaded. Irreversible; guarded by a
 * confirmation.
 *
 * ---------------------------------------------------------------------------
 * STORED OBJECTS — why this is done row-by-row rather than by prefix
 * ---------------------------------------------------------------------------
 * This command used to carry a note claiming "S3 objects are org-prefixed
 * (org/{id}/...) and should be removed by the object-store lifecycle". That was
 * WRONG on both halves, and /privacy now publicly promises the opposite:
 *
 *   - Screenshots are written by Api\Desktop\ScreenshotController as
 *       screenshots/{user_id}/{Y-m-d}/{session_id}/{time}_{uniqid}.{ext}
 *     There is NO organization segment anywhere in that path. Avatars are
 *     `avatars/{random}.{ext}` and workspace branding logos `branding/{random}`
 *     — both flat, both with nothing tying an object to a workspace except the
 *     database row that references it.
 *   - There is no bucket lifecycle rule doing this either; nothing else was
 *     ever going to delete these objects.
 *
 * So a prefix/directory delete CANNOT be used: there is no per-org prefix to
 * delete, and inventing one from `user_id` would be guessing at the layout with
 * a permanent-deletion tool. The authoritative record of "which object belongs
 * to which workspace" is the row, so the row is what we enumerate. That is
 * exact, and it is also the only thing that stays correct if the path scheme
 * changes again.
 *
 * Consequences that shaped the implementation:
 *   - MEMORY: the rows are walked in bounded batches (never a full pluck of a
 *     workspace with hundreds of thousands of screenshots), and each batch is
 *     deleted as it is processed so the loop always makes progress without an
 *     offset scan.
 *   - RESUMABILITY: within a batch the FILES go first and the ROWS only after
 *     they are gone. If storage fails the command aborts BEFORE the row purge
 *     and before the organization row is removed, so nothing is orphaned and
 *     re-running the command resumes exactly where it stopped. Deleting rows
 *     first is what created the original defect: once the rows are gone neither
 *     this command nor `monitoring:prune-screenshots` (which also works from
 *     rows) can ever find the files again.
 *   - COST: one delete call per object, which is the same per-object cost the
 *     retention job already pays. If the screenshot layout is ever moved under
 *     an `org/{id}/` prefix, this becomes a single deleteDirectory per disk and
 *     purgeStoredObjects() is the one place to change.
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

        // FILES BEFORE ROWS. This runs first and, on failure, aborts the whole
        // erasure with the database untouched — see the class docblock.
        try {
            $current->runFor($org, fn () => $this->purgeStoredObjects($orgId));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->error("Nothing further was deleted for '{$org->name}' (#{$orgId}). Fix the storage problem and run this command again — it resumes from where it stopped.");

            return self::FAILURE;
        }

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

    /**
     * Rows (and, for screenshots, their files) handled per batch. Small enough
     * that a workspace with 500k screenshots never holds more than this many
     * hydrated rows at once.
     */
    private const OBJECT_CHUNK = 500;

    /**
     * Delete every stored object this workspace owns, on whichever driver the
     * disks are configured with (local in dev, s3 in production —
     * Storage::disk() hides the difference and Storage::fake() in tests
     * exercises the same code path).
     *
     * Throws RuntimeException on the first storage failure, having deleted no
     * rows for the failing batch. The caller turns that into a non-zero exit
     * with the database still consistent and the erasure re-runnable.
     */
    private function purgeStoredObjects(int $orgId): void
    {
        [$shotFiles, $shotRows] = $this->purgeScreenshotObjects($orgId);
        $avatars = $this->purgeAvatarObjects($orgId);
        $logos = $this->purgeBrandingObjects($orgId);

        $this->info("Erased {$shotFiles} screenshot file(s) across {$shotRows} row(s), {$avatars} avatar(s) and {$logos} branding logo(s) from storage.");
    }

    /**
     * Screenshots: the big one. Batches are taken in id order, the files for a
     * batch are deleted, and only then are that batch's rows force-deleted —
     * which also shrinks the query for the next iteration, so the loop is
     * O(rows) with no growing offset.
     *
     * `withTrashed()` matters: a soft-deleted screenshot still has its file on
     * disk, and "deleted in the UI" is not "erased".
     *
     * @return array{0:int,1:int} [files deleted, rows deleted]
     */
    private function purgeScreenshotObjects(int $orgId): array
    {
        if (! Schema::hasTable('tracking_screenshots')) {
            return [0, 0];
        }

        $disk = Storage::disk('screenshots');
        $files = 0;
        $rows = 0;

        while (true) {
            $batch = TrackingScreenshot::withoutOrganizationScope()
                ->withTrashed()
                ->where('organization_id', $orgId)
                ->orderBy('id')
                ->limit(self::OBJECT_CHUNK)
                ->get(['id', 'image_path', 'thumbnail_path']);

            if ($batch->isEmpty()) {
                break;
            }

            $paths = [];

            foreach ($batch as $screenshot) {
                foreach ([$screenshot->image_path, $screenshot->thumbnail_path] as $path) {
                    if (is_string($path) && $path !== '') {
                        $paths[] = $path;
                    }
                }
            }

            $this->deleteObjects($disk, $paths, 'screenshots');
            $files += count($paths);

            // Explicit organization predicate: forceDelete() runs on the BASE
            // query builder, so no global scope — not SoftDeletingScope, not
            // OrganizationScope — is applied to it. whereKey() alone would be
            // enough here, but the org predicate is what makes that true by
            // construction rather than by inspection of the batch above.
            $rows += (int) TrackingScreenshot::withoutOrganizationScope()
                ->withTrashed()
                ->where('organization_id', $orgId)
                ->whereKey($batch->modelKeys())
                ->forceDelete();
        }

        return [$files, $rows];
    }

    /**
     * User avatars. The rows themselves are removed by the model purge; only
     * the objects need handling here, and they are read in bounded pages
     * because a workspace can have hundreds of users.
     */
    private function purgeAvatarObjects(int $orgId): int
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'avatar')) {
            return 0;
        }

        $disk = Storage::disk('avatars');
        $deleted = 0;
        $lastId = 0;

        while (true) {
            $rows = User::withoutOrganizationScope()
                ->where('organization_id', $orgId)
                ->whereNotNull('avatar')
                ->where('avatar', '!=', '')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::OBJECT_CHUNK)
                ->get(['id', 'avatar']);

            if ($rows->isEmpty()) {
                break;
            }

            // Keyset pagination, not offset: the rows are still present when
            // this runs (the model purge deletes them later), so an offset walk
            // would be O(n^2) on a big workspace.
            $lastId = (int) $rows->last()->id;

            $this->deleteObjects($disk, $rows->pluck('avatar')->all(), 'avatars');
            $deleted += $rows->count();
        }

        return $deleted;
    }

    /**
     * The workspace's white-label branding logo (monitoring_settings). One row
     * per workspace, but the guard and the shared helper keep it uniform.
     */
    private function purgeBrandingObjects(int $orgId): int
    {
        if (! Schema::hasTable('monitoring_settings') || ! Schema::hasColumn('monitoring_settings', 'branding_logo_path')) {
            return 0;
        }

        $paths = MonitoringSetting::withoutOrganizationScope()
            ->where('organization_id', $orgId)
            ->whereNotNull('branding_logo_path')
            ->where('branding_logo_path', '!=', '')
            ->limit(self::OBJECT_CHUNK)
            ->pluck('branding_logo_path')
            ->all();

        $this->deleteObjects(Storage::disk('avatars'), $paths, 'avatars');

        return count($paths);
    }

    /**
     * Delete a batch of objects, failing loudly.
     *
     * Both disks are configured with `'throw' => false`, so a storage error
     * comes back as a `false` return rather than an exception — which is
     * exactly the failure mode that would otherwise let an erasure report
     * success while leaving every file in place. Deleting an object that is
     * already gone is a no-op success on both the local and the S3 driver, so
     * `false` here means a real failure.
     *
     * @param  list<string>  $paths
     */
    private function deleteObjects(Filesystem $disk, array $paths, string $label): void
    {
        if ($paths === []) {
            return;
        }

        $ok = rescue(fn () => $disk->delete($paths), false, false);

        if ($ok !== true) {
            throw new RuntimeException(
                'Storage erasure failed: could not delete '.count($paths)." object(s) from the '{$label}' disk. "
                .'The database has NOT been purged, so no file has been orphaned.'
            );
        }
    }
}
