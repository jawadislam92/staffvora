<?php

namespace Tests\Feature;

use App\Console\Commands\DeleteTenant;
use App\Models\Client;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Feedback;
use App\Models\FeedbackMessage;
use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftOverride;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_tenant_erases_its_data_and_leaves_others_intact(): void
    {
        app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw');
        app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw');

        $orgA = Organization::where('slug', 'acme')->first();
        $orgB = Organization::where('slug', 'globex')->first();
        $current = app(CurrentOrganization::class);
        $current->runFor($orgA, fn () => Client::create(['name' => 'A Client', 'work_type' => 'fixed']));
        $current->runFor($orgB, fn () => Client::create(['name' => 'B Client', 'work_type' => 'fixed']));

        $this->artisan('tenant:delete', ['org' => $orgA->id, '--force' => true])->assertExitCode(0);

        // Org A and everything it owned is gone.
        $this->assertNull(Organization::find($orgA->id));
        $this->assertSame(0, User::withoutOrganizationScope()->where('organization_id', $orgA->id)->count());
        $this->assertSame(0, Client::withoutOrganizationScope()->where('organization_id', $orgA->id)->count());

        // Org B is untouched.
        $this->assertNotNull(Organization::find($orgB->id));
        $this->assertSame(1, User::withoutOrganizationScope()->where('organization_id', $orgB->id)->count());
        $this->assertSame(1, Client::withoutOrganizationScope()->where('organization_id', $orgB->id)->count());
    }

    /**
     * The erasure must not reach across tenants — specifically on the SOFT-DELETE
     * path, which the test above never exercised because neither User nor Client
     * soft-deletes.
     *
     * This is a real defect that shipped: `Eloquent\Builder::forceDelete()` is
     * `$this->query->delete()`, hitting the BASE builder, so applyScopes() never
     * runs and BOTH the SoftDeletingScope and the OrganizationScope are discarded.
     * The statement became an unqualified `DELETE FROM tracking_screenshots` and
     * erasing ONE tenant destroyed EVERY tenant's screenshots platform-wide.
     * TrackingScreenshot sorts first in $models, so it fired on the first
     * iteration of every single erasure — and the whole suite stayed green.
     */
    public function test_erasing_a_tenant_does_not_delete_another_tenants_soft_deleting_rows(): void
    {
        app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw');
        app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw');

        $orgA = Organization::where('slug', 'acme')->firstOrFail();
        $orgB = Organization::where('slug', 'globex')->firstOrFail();
        $current = app(CurrentOrganization::class);

        $shot = function (Organization $org) use ($current) {
            return $current->runFor($org, function () use ($org) {
                $userId = User::withoutOrganizationScope()
                    ->where('organization_id', $org->id)->value('id');

                // tracking_screenshots.tracking_session_id is NOT NULL.
                $session = TrackingSession::create([
                    'user_id' => $userId,
                    'started_at' => now()->subHour(),
                    'status' => 'stopped',
                ]);

                return TrackingScreenshot::create([
                    'tracking_session_id' => $session->id,
                    'user_id' => $userId,
                    'captured_at' => now(),
                    'image_path' => 'screenshots/'.$org->slug.'.jpg',
                ]);
            });
        };

        $shot($orgA);
        $keep = $shot($orgB);

        $this->artisan('tenant:delete', ['org' => $orgA->id, '--force' => true])->assertExitCode(0);

        // The erased tenant's rows are gone for real (force-deleted, not soft-deleted).
        $this->assertSame(0, TrackingScreenshot::withoutOrganizationScope()->withTrashed()
            ->where('organization_id', $orgA->id)->count());

        // …and the OTHER tenant's still exist. This is the assertion that fails
        // without the explicit organization predicate in DeleteTenant.
        $this->assertSame(1, TrackingScreenshot::withoutOrganizationScope()->withTrashed()
            ->where('organization_id', $orgB->id)->count(),
            'Erasing one tenant must never touch another tenant\'s soft-deleting rows.');
        $this->assertNotNull(TrackingScreenshot::withoutOrganizationScope()->withTrashed()->find($keep->id));
    }

    /**
     * Drift guard: the bug above was invisible because only ONE model in the purge
     * list soft-deletes. The moment a second one does, it silently joins the blast
     * radius. This asserts the purge is safe for EVERY soft-deleting model, so a
     * future `use SoftDeletes` cannot reintroduce a platform-wide delete.
     */
    public function test_every_soft_deleting_model_in_the_purge_list_is_organization_scoped(): void
    {
        $softDeleting = [];

        foreach (DeleteTenant::models() as $model) {
            if (! class_exists($model)) {
                continue;
            }

            if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                continue;
            }

            $softDeleting[] = $model;

            $this->assertContains(
                BelongsToOrganization::class,
                class_uses_recursive($model),
                $model.' soft-deletes and is purged by tenant:delete, so it MUST be organization-scoped.'
            );
        }

        // Sanity: if this ever hits zero the guard has stopped guarding anything
        // (e.g. the model list was refactored) and should be revisited.
        $this->assertNotEmpty(
            $softDeleting,
            'Expected at least one soft-deleting model in the purge list; the guard may be silently vacuous.'
        );
    }

    /**
     * [medium-18]: the erasure command omitted the Phase 4 tenant models, so a
     * customer told their data was erased kept every feedback ticket and reply
     * their employees wrote, plus their shift catalogue, in the database
     * forever — unreachable through the app, because the organization row was
     * gone.
     */
    public function test_erasure_removes_user_authored_content_and_the_shift_catalogue(): void
    {
        app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw');
        $org = Organization::where('slug', 'acme')->first();
        $current = app(CurrentOrganization::class);

        $current->runFor($org, function () use ($org) {
            $author = User::withoutOrganizationScope()->where('organization_id', $org->id)->firstOrFail();

            $feedback = Feedback::create([
                'user_id' => $author->id,
                'type' => 'bug',
                'subject' => 'Something is broken',
                'message' => 'Employee-authored free text.',
            ]);

            FeedbackMessage::create([
                'feedback_item_id' => $feedback->id,
                'user_id' => $author->id,
                'body' => 'A follow-up reply.',
            ]);

            Shift::create(['name' => 'Night', 'start_time' => '22:00', 'hours' => 8]);

            ShiftOverride::create([
                'user_id' => $author->id,
                'date' => '2026-06-01',
                'shift_start_time' => '22:00',
                'shift_hours' => 8,
            ]);
        });

        $this->artisan('tenant:delete', ['org' => $org->id, '--force' => true])->assertExitCode(0);

        foreach ([Feedback::class, FeedbackMessage::class, Shift::class, ShiftOverride::class] as $model) {
            $this->assertSame(
                0,
                $model::withoutOrganizationScope()->where('organization_id', $org->id)->count(),
                $model.' rows survived GDPR erasure'
            );
        }
    }

    /**
     * Drift guard. The purge list is an explicit ORDERED array (FK ordering is
     * not derivable, so it cannot be reflected at runtime) — this test is what
     * stops the next tenant-owned model from being silently forgotten, which is
     * exactly how [medium-18] happened.
     */
    public function test_every_tenant_owned_model_is_either_purged_or_explicitly_exempt(): void
    {
        $purged = DeleteTenant::models();
        $exempt = DeleteTenant::exemptModels();

        foreach (glob(app_path('Models').'/*.php') as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
                continue;
            }

            $this->assertTrue(
                in_array($class, $purged, true) || array_key_exists($class, $exempt),
                $class.' uses BelongsToOrganization but is neither purged by tenant:delete nor listed as exempt. '
                .'Add it to DeleteTenant::$models in FK-safe order (children first), or to DeleteTenant::$exempt with a reason.'
            );
        }
    }

    // ------------------------------------------------------------------
    // STORED OBJECTS
    //
    // /privacy states: "we permanently remove it and everything belonging to
    // it — users, time records, attendance, timesheets, activity samples,
    // screenshots and their stored image files". The command deleted DATABASE
    // ROWS ONLY. Every screenshot JPEG, every avatar and every branding logo
    // stayed on the disk (or in the S3 bucket) forever — and, because
    // monitoring:prune-screenshots also works from rows, removing the rows put
    // those files beyond the reach of EVERY automatic cleanup the product has.
    //
    // Storage::fake() swaps in a real local-driver disk, so these exercise the
    // same Storage::disk()->delete() path production runs against S3.
    // ------------------------------------------------------------------

    /**
     * Set up two workspaces, each with a screenshot (image + thumbnail), an
     * avatar and a branding logo actually written to the fake disks.
     *
     * @return array{0:Organization, 1:Organization, 2:array<string, list<string>>}
     */
    private function twoWorkspacesWithFiles(): array
    {
        app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw');
        app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw');

        $orgA = Organization::where('slug', 'acme')->firstOrFail();
        $orgB = Organization::where('slug', 'globex')->firstOrFail();
        $current = app(CurrentOrganization::class);

        $paths = [];

        foreach (['acme' => $orgA, 'globex' => $orgB] as $key => $org) {
            $paths[$key] = $current->runFor($org, function () use ($org) {
                $user = User::withoutOrganizationScope()->where('organization_id', $org->id)->firstOrFail();

                $session = TrackingSession::create([
                    'user_id' => $user->id,
                    'started_at' => now()->subHour(),
                    'status' => 'stopped',
                ]);

                // Paths mirror the real layout written by the desktop upload
                // endpoint: screenshots/{user_id}/{date}/{session_id}/… — note
                // there is NO organization segment, which is precisely why the
                // purge has to work from rows rather than a prefix delete.
                $image = "screenshots/{$user->id}/2026-08-01/{$session->id}/090000_a.jpg";
                $thumb = "screenshots/{$user->id}/2026-08-01/{$session->id}/090000_a_thumb.jpg";
                $avatar = "avatars/{$org->slug}-avatar.png";
                $logo = "branding/{$org->slug}-logo.png";

                Storage::disk('screenshots')->put($image, 'image-bytes');
                Storage::disk('screenshots')->put($thumb, 'thumb-bytes');
                Storage::disk('avatars')->put($avatar, 'avatar-bytes');
                Storage::disk('avatars')->put($logo, 'logo-bytes');

                TrackingScreenshot::create([
                    'tracking_session_id' => $session->id,
                    'user_id' => $user->id,
                    'captured_at' => now(),
                    'image_path' => $image,
                    'thumbnail_path' => $thumb,
                ]);

                $user->forceFill(['avatar' => $avatar])->save();
                MonitoringSetting::current()->update(['branding_logo_path' => $logo]);

                return [$image, $thumb, $avatar, $logo];
            });
        }

        return [$orgA, $orgB, $paths];
    }

    public function test_erasure_deletes_the_workspaces_stored_files_and_leaves_the_other_workspaces_alone(): void
    {
        Storage::fake('screenshots');
        Storage::fake('avatars');

        [$orgA, $orgB, $paths] = $this->twoWorkspacesWithFiles();

        $this->artisan('tenant:delete', ['org' => $orgA->id, '--force' => true])->assertExitCode(0);

        foreach ($paths['acme'] as $path) {
            $disk = str_starts_with($path, 'screenshots/') ? 'screenshots' : 'avatars';
            Storage::disk($disk)->assertMissing($path);
        }

        // THE ASSERTION THAT MATTERS MOST: erasing one workspace must not take
        // another workspace's objects with it. A prefix/wildcard delete would
        // fail here.
        foreach ($paths['globex'] as $path) {
            $disk = str_starts_with($path, 'screenshots/') ? 'screenshots' : 'avatars';
            Storage::disk($disk)->assertExists($path);
        }

        $this->assertNull(Organization::find($orgA->id));
        $this->assertNotNull(Organization::find($orgB->id));
    }

    /**
     * A soft-deleted screenshot still has its file on disk. "Deleted in the UI"
     * is not "erased", so the purge must use withTrashed().
     */
    public function test_erasure_deletes_files_belonging_to_soft_deleted_screenshots(): void
    {
        Storage::fake('screenshots');
        Storage::fake('avatars');

        [$orgA, , $paths] = $this->twoWorkspacesWithFiles();

        app(CurrentOrganization::class)->runFor($orgA, function () {
            TrackingScreenshot::query()->firstOrFail()->delete(); // soft delete
        });

        $this->artisan('tenant:delete', ['org' => $orgA->id, '--force' => true])->assertExitCode(0);

        Storage::disk('screenshots')->assertMissing($paths['acme'][0]);
        Storage::disk('screenshots')->assertMissing($paths['acme'][1]);
    }

    /**
     * A storage failure must NOT leave the database half-purged with the files
     * still out there and nothing left pointing at them — that is the exact
     * shape of the original defect. The command has to abort with the rows and
     * the organization intact so it can simply be run again.
     */
    public function test_a_storage_failure_aborts_the_erasure_instead_of_orphaning_the_files(): void
    {
        Storage::fake('screenshots');
        Storage::fake('avatars');

        [$orgA, $orgB] = $this->twoWorkspacesWithFiles();

        // The disks are configured with 'throw' => false, so a real storage
        // outage surfaces as a false return, not an exception — the failure
        // mode most likely to be silently ignored.
        $broken = \Mockery::mock(Filesystem::class);
        $broken->shouldReceive('delete')->andReturn(false);
        Storage::set('screenshots', $broken);

        $this->artisan('tenant:delete', ['org' => $orgA->id, '--force' => true])->assertExitCode(1);

        $this->assertNotNull(Organization::find($orgA->id), 'The organization row must survive a storage failure.');
        $this->assertSame(1, TrackingScreenshot::withoutOrganizationScope()->withTrashed()
            ->where('organization_id', $orgA->id)->count(), 'Screenshot rows must survive a storage failure so the erasure can be retried.');
        $this->assertSame(1, User::withoutOrganizationScope()->where('organization_id', $orgA->id)->count());

        // The other workspace is untouched either way.
        $this->assertNotNull(Organization::find($orgB->id));

        // …and once storage is healthy again, re-running finishes the job.
        Storage::fake('screenshots');
        $this->artisan('tenant:delete', ['org' => $orgA->id, '--force' => true])->assertExitCode(0);
        $this->assertNull(Organization::find($orgA->id));
    }

    /**
     * Bounded memory / bounded queries: a workspace with far more screenshots
     * than one batch must still be fully erased, and the batching must not
     * leave a tail behind (an offset-walk bug looks exactly like that).
     */
    public function test_erasure_handles_more_screenshots_than_one_batch(): void
    {
        Storage::fake('screenshots');
        Storage::fake('avatars');

        app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw');
        $org = Organization::where('slug', 'acme')->firstOrFail();

        $written = app(CurrentOrganization::class)->runFor($org, function () use ($org) {
            $user = User::withoutOrganizationScope()->where('organization_id', $org->id)->firstOrFail();
            $session = TrackingSession::create([
                'user_id' => $user->id,
                'started_at' => now()->subHour(),
                'status' => 'stopped',
            ]);

            $paths = [];

            // 1,200 rows against a 500-row batch: three passes, with a partial
            // final batch.
            for ($i = 0; $i < 1200; $i++) {
                $path = "screenshots/{$user->id}/2026-08-01/{$session->id}/shot{$i}.jpg";
                Storage::disk('screenshots')->put($path, 'x');
                $paths[] = $path;

                TrackingScreenshot::create([
                    'tracking_session_id' => $session->id,
                    'user_id' => $user->id,
                    'captured_at' => now(),
                    'image_path' => $path,
                ]);
            }

            return $paths;
        });

        $this->artisan('tenant:delete', ['org' => $org->id, '--force' => true])->assertExitCode(0);

        $this->assertSame(0, TrackingScreenshot::withoutOrganizationScope()->withTrashed()
            ->where('organization_id', $org->id)->count());

        foreach ([0, 499, 500, 999, 1000, 1199] as $index) {
            Storage::disk('screenshots')->assertMissing($written[$index]);
        }

        $this->assertEmpty(Storage::disk('screenshots')->allFiles());
    }
}
