<?php

namespace Tests\Feature;

use App\Console\Commands\DeleteTenant;
use App\Models\Client;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Feedback;
use App\Models\FeedbackMessage;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftOverride;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
