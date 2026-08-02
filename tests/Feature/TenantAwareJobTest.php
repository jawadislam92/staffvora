<?php

namespace Tests\Feature;

use App\Jobs\Concerns\TenantAware;
use App\Jobs\GenerateScreenshotThumbnail;
use App\Models\Organization;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A QUEUED JOB IS A TENANCY HOLE UNLESS SOMETHING RE-BINDS THE TENANT
 * ─────────────────────────────────────────────────────────────────────────────
 * `CurrentOrganization` is set by middleware from the authenticated user. A queue
 * WORKER has no request and no user, so its value is null — and `OrganizationScope`
 * deliberately emits NO PREDICATE when the id is null, because login, the operator
 * portal and un-tenanted console commands all depend on that.
 *
 * The consequence is silent, not loud:
 *   • every `Model::query()` in the job spans EVERY tenant;
 *   • every `Model::create()` is stamped with a NULL organization_id and is then
 *     invisible to scoped reads, to retention and to tenant deletion;
 *   • `Model::find($id)` returns the row whoever owns it, so an id from a stale or
 *     replayed payload walks straight across the boundary.
 *
 * Under `QUEUE_CONNECTION=sync` none of this appears, because the "job" runs inside
 * the dispatching request and inherits its binding. It appears the day the queue
 * becomes a real driver — which is exactly what this phase did (`config/queue.php`
 * now defaults to `database`). The test suite runs on `sync` (phpunit.xml), so
 * these tests deliberately simulate the worker by FORGETTING the binding before
 * calling `handle()`. Without that, every assertion here would pass for the wrong
 * reason.
 */
class TenantAwareJobTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{org: Organization, users: array<int, int>} */
    private array $a;

    /** @var array{org: Organization, users: array<int, int>} */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->workspace('Alpha', 2);
        $this->b = $this->workspace('Bravo', 3);

        // Whatever the fixtures left bound is irrelevant — a worker starts clean.
        app(CurrentOrganization::class)->forget();
    }

    /** @return array{org: Organization, users: array<int, int>} */
    private function workspace(string $name, int $users): array
    {
        $org = Organization::factory()->create([
            'name' => $name,
            'status' => Organization::STATUS_ACTIVE,
        ]);

        $ids = app(CurrentOrganization::class)->runFor($org, function () use ($org, $users) {
            $made = [];
            for ($i = 0; $i < $users; $i++) {
                $made[] = User::factory()->create(['organization_id' => $org->id])->id;
            }

            return $made;
        });

        sort($ids);

        return ['org' => $org, 'users' => $ids];
    }

    /** Build a job as a real dispatcher would: with the tenant bound. */
    private function dispatchedUnder(Organization $org): TenantAwareProbeJob
    {
        return app(CurrentOrganization::class)->runFor($org, fn () => new TenantAwareProbeJob);
    }

    /**
     * THE CORE PROPERTY. Dispatched for org A, run on a worker with nothing bound,
     * the job must see org A and ONLY org A.
     */
    public function test_a_job_dispatched_for_one_tenant_rebinds_it_on_a_worker_and_touches_only_that_tenant(): void
    {
        $job = $this->dispatchedUnder($this->a['org']);

        $current = app(CurrentOrganization::class);
        $this->assertNull($current->id(), 'The simulated worker must start with no tenant bound.');

        $job->handle();

        $this->assertSame(
            $this->a['org']->id,
            $job->boundInside,
            'The captured tenant must be re-bound for the duration of handle().'
        );

        $this->assertSame(
            $this->a['users'],
            $job->visibleUserIds,
            'A job dispatched for org A read rows belonging to another tenant. With no binding the '.
            'OrganizationScope emits no predicate at all, so every query spans the whole platform.'
        );

        foreach ($this->b['users'] as $foreign) {
            $this->assertNotContains($foreign, $job->visibleUserIds);
        }

        $this->assertNull(
            $current->id(),
            'The binding must be released after handle(); a worker runs many jobs in one process and '.
            'leaking one job\'s tenant into the next is the same bug in a different costume.'
        );
    }

    /**
     * A write inside the job must be stamped with the captured tenant. Unbound, the
     * `BelongsToOrganization` creating hook stamps NULL — producing a row that is
     * invisible to every scoped read, to retention AND to tenant deletion, i.e.
     * orphaned the moment it is written.
     */
    public function test_a_row_created_inside_the_job_is_stamped_with_the_captured_tenant(): void
    {
        $job = $this->dispatchedUnder($this->b['org']);
        $job->createUser = true;

        $job->handle();

        $this->assertNotNull($job->createdUserId);

        $stamped = User::withoutOrganizationScope()->findOrFail($job->createdUserId);
        $this->assertSame(
            $this->b['org']->id,
            $stamped->organization_id,
            'A row written by a queued job must carry the dispatching tenant, never NULL.'
        );
    }

    /**
     * FAILS SAFE, NOT OPEN. If the captured organization has since been deleted, the
     * job must stay scoped to that (now dead) id and read nothing — it must NOT fall
     * back to running unscoped, which would turn a deleted-tenant job into a
     * platform-wide read.
     */
    public function test_a_job_whose_organization_no_longer_exists_reads_nothing_instead_of_everything(): void
    {
        $job = $this->dispatchedUnder($this->a['org']);

        // The workspace row is gone; the job payload still names its id.
        Organization::whereKey($this->a['org']->id)->delete();
        User::withoutOrganizationScope()->where('organization_id', $this->a['org']->id)->delete();

        $job->handle();

        $this->assertSame($this->a['org']->id, $job->boundInside);
        $this->assertSame(
            [],
            $job->visibleUserIds,
            'A job for a missing organization must fail SAFE (see nothing), not open (see every tenant).'
        );
    }

    /**
     * The thumbnail job is the one real user of the trait. Handed an id belonging to
     * ANOTHER tenant it must find nothing and write nothing — `find()` is scoped, so
     * a stale or replayed payload cannot reach across the boundary.
     */
    public function test_the_thumbnail_job_cannot_touch_another_tenants_screenshot(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is not available.');
        }

        Storage::fake('screenshots');

        $foreign = $this->screenshotFor($this->b['org'], 'b/shot.png');

        // Dispatched by org A, but pointed at org B's row.
        $job = app(CurrentOrganization::class)->runFor(
            $this->a['org'],
            fn () => new GenerateScreenshotThumbnail($foreign->id)
        );

        app(CurrentOrganization::class)->forget();
        $job->handle();

        $this->assertNull(
            TrackingScreenshot::withoutOrganizationScope()->findOrFail($foreign->id)->thumbnail_path,
            'A job dispatched by one tenant processed another tenant\'s screenshot.'
        );
        Storage::disk('screenshots')->assertMissing('b/thumbs/shot.jpg');
    }

    /** The same job, pointed at its OWN tenant's row, must work — the guard is not just "do nothing". */
    public function test_the_thumbnail_job_still_works_for_its_own_tenant_on_a_worker(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is not available.');
        }

        Storage::fake('screenshots');

        $own = $this->screenshotFor($this->a['org'], 'a/shot.png');

        $job = app(CurrentOrganization::class)->runFor(
            $this->a['org'],
            fn () => new GenerateScreenshotThumbnail($own->id)
        );

        app(CurrentOrganization::class)->forget();
        $job->handle();

        $this->assertNotNull(
            TrackingScreenshot::withoutOrganizationScope()->findOrFail($own->id)->thumbnail_path
        );
    }

    /**
     * The tenant is CAPTURED from the dispatching request, never accepted as an
     * argument. A job whose tenant could be chosen by its payload would be a
     * cross-tenant read primitive for anybody who can enqueue one.
     */
    public function test_the_captured_tenant_is_not_caller_supplied(): void
    {
        foreach ($this->tenantAwareJobClasses() as $class) {
            foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                $this->assertDoesNotMatchRegularExpression(
                    '/^(organization|organisation|org|tenant).*(id)?$/i',
                    $parameter->getName(),
                    $class.'::__construct takes $'.$parameter->getName().'. The tenant must be captured from '.
                    'the dispatching request via captureTenant(), never taken from a caller-supplied argument.'
                );
            }
        }

        // And the capture really happens in the constructor, not lazily on the worker.
        $job = $this->dispatchedUnder($this->b['org']);
        $this->assertSame($this->b['org']->id, $job->tenantOrganizationId);
    }

    /**
     * DRIFT GUARD. The trait only protects the jobs that use it. A new queued job
     * added without it looks fine on `sync` and spans every tenant on a real worker
     * — which is precisely the failure this whole file exists to prevent.
     */
    public function test_every_queued_job_uses_the_tenant_aware_trait(): void
    {
        $jobs = $this->tenantAwareJobClasses(includeUntagged: true);

        $this->assertNotEmpty($jobs, 'No queued jobs found — the guard would be vacuous.');

        foreach ($jobs as $class) {
            $this->assertContains(
                TenantAware::class,
                class_uses_recursive($class),
                $class.' is a queued job but does not use the TenantAware trait. On a real queue driver it '.
                'would run with NO tenant bound, so every query in it spans the whole platform and every '.
                'write is stamped with a NULL organization_id.'
            );
        }
    }

    /**
     * DOCUMENTED, DELIBERATE EXCEPTION — pinned here so it is a decision and not a
     * surprise. A job constructed with NO tenant bound (an un-tenanted console
     * command) runs UNBOUND, which is fail-OPEN by design: the trait's docblock
     * argues that guessing a tenant is worse than having none.
     *
     * Nothing in this codebase dispatches a job that way today — the only dispatch
     * site is the desktop screenshot ingest, which is behind `EnsureTenantBound`. If
     * that ever changes, the safer behaviour is to refuse rather than run unscoped;
     * this test is what will have to be updated to say so.
     *
     * What is asserted unconditionally is the part that must NEVER regress: the
     * unbound path still cannot leak a binding into the next job on the worker.
     */
    public function test_a_job_with_no_tenant_at_dispatch_runs_unbound_and_leaks_nothing(): void
    {
        $current = app(CurrentOrganization::class);
        $this->assertNull($current->id());

        $unbound = new TenantAwareProbeJob;
        $this->assertNull($unbound->tenantOrganizationId);

        $unbound->handle();

        $this->assertNull($unbound->boundInside, 'The unbound path is fail-open by design — see the docblock.');
        $this->assertSame(
            array_merge($this->a['users'], $this->b['users']),
            $unbound->visibleUserIds,
            'Documenting the cost of the unbound path: it really does span every tenant.'
        );

        // The part that is NOT negotiable: no binding survives into the next job.
        $this->assertNull($current->id());

        $next = $this->dispatchedUnder($this->a['org']);
        $current->forget();
        $next->handle();
        $this->assertSame($this->a['org']->id, $next->boundInside);
        $this->assertNull($current->id());
    }

    /**
     * Queued job classes under app/Jobs.
     *
     * @return array<int, class-string>
     */
    private function tenantAwareJobClasses(bool $includeUntagged = false): array
    {
        $classes = [];

        foreach (glob(app_path('Jobs').'/*.php') as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }

            if (! $includeUntagged && ! in_array(TenantAware::class, class_uses_recursive($class), true)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    private function screenshotFor(Organization $org, string $path): TrackingScreenshot
    {
        return app(CurrentOrganization::class)->runFor($org, function () use ($org, $path) {
            $userId = User::withoutOrganizationScope()->where('organization_id', $org->id)->value('id');

            $session = TrackingSession::create([
                'user_id' => $userId,
                'client_uuid' => 'uuid-'.uniqid(),
                'started_at' => now()->subHour(),
                'status' => 'stopped',
                'source' => 'desktop',
            ]);

            $image = imagecreatetruecolor(120, 90);
            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();
            imagedestroy($image);

            Storage::disk('screenshots')->put($path, $bytes);

            return TrackingScreenshot::create([
                'tracking_session_id' => $session->id,
                'user_id' => $userId,
                'captured_at' => now(),
                'image_path' => $path,
            ]);
        });
    }
}

/**
 * A minimal job that does what every real job does — read, and optionally write —
 * and records what it saw. Handling it directly (rather than through the queue)
 * is the point: the test controls exactly what is bound when `handle()` runs.
 */
class TenantAwareProbeJob implements ShouldQueue
{
    use TenantAware;

    public ?int $boundInside = null;

    /** @var array<int, int> */
    public array $visibleUserIds = [];

    public bool $createUser = false;

    public ?int $createdUserId = null;

    public function __construct()
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $this->forTenant(function () {
            $this->boundInside = app(CurrentOrganization::class)->id();
            $this->visibleUserIds = User::query()->orderBy('id')->pluck('id')->all();

            if ($this->createUser) {
                $this->createdUserId = User::factory()->create()->id;
            }
        });
    }
}
