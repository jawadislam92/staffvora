<?php

namespace App\Jobs\Concerns;

use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Log;

/**
 * A QUEUED JOB RUNS WITH NO TENANT BOUND. This trait is how a job gets one back.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS DANGEROUS AND NOT MERELY INCONVENIENT
 * ─────────────────────────────────────────────────────────────────────────────
 * `CurrentOrganization` is a request-scoped singleton set by middleware from the
 * authenticated user. A worker process has no request and no user, so its value
 * is null — and `OrganizationScope::apply()` deliberately becomes a NO-OP when
 * the id is null, because login, the operator portal and un-tenanted console
 * commands all have to keep working.
 *
 * The consequence, which is easy to miss because nothing errors:
 *
 *   • every `Model::query()` inside the job spans EVERY tenant. A job that
 *     "finds the newest screenshot" finds another customer's newest screenshot;
 *   • every `Model::create()` writes with a NULL `organization_id`, because
 *     `BelongsToOrganization`'s creating hook stamps from the same null. The row
 *     is then invisible to every scoped read, to tenant deletion, and to
 *     retention — it is orphaned the moment it is written;
 *   • `Model::find($id)` returns the row whatever tenant owns it, so an id from
 *     a stale/replayed payload reaches across the boundary.
 *
 * Under `QUEUE_CONNECTION=sync` none of this shows up, because the "job" runs
 * inside the dispatching request and simply inherits its binding. It appears the
 * day the queue is switched to a real driver — which is exactly what this phase
 * did. Hence the trait, and hence the test that asserts the binding rather than
 * trusting the code comment.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW TO USE IT
 * ─────────────────────────────────────────────────────────────────────────────
 *   use TenantAware;
 *
 *   public function __construct(public int $screenshotId)
 *   {
 *       $this->captureTenant();      // records the CURRENT tenant at dispatch
 *   }
 *
 *   public function handle(): void
 *   {
 *       $this->forTenant(function () { ...real work... });
 *   }
 *
 * `captureTenant()` runs in the constructor — i.e. in the dispatching request,
 * where the tenant IS bound — and stores a plain int on the job payload. Nothing
 * is resolved from the container at run time, so the binding cannot silently
 * come back null on the worker.
 *
 * The id is captured, never taken from a caller-supplied argument: a job whose
 * tenant could be chosen by its payload would be a cross-tenant read primitive
 * for anyone who can enqueue.
 *
 * NO TENANT AT DISPATCH is a legitimate case (a job dispatched from an
 * un-tenanted console command). `forTenant()` then runs the callback unbound and
 * logs it at debug level, rather than guessing — guessing a tenant is worse than
 * having none.
 */
trait TenantAware
{
    /**
     * The organization this job belongs to, captured at DISPATCH time and
     * serialised with the payload. Public so it survives job serialisation and
     * is visible in `failed_jobs` when something goes wrong.
     */
    public ?int $tenantOrganizationId = null;

    /** Record the tenant bound right now. Call this from the constructor. */
    protected function captureTenant(): void
    {
        $this->tenantOrganizationId = app(CurrentOrganization::class)->id();
    }

    /**
     * Run the job's real work with the captured tenant bound, restoring whatever
     * the worker had before (normally nothing) afterwards — a worker processes
     * many jobs in one process, and leaking one job's tenant into the next is
     * the same bug in a different costume.
     */
    protected function forTenant(callable $callback): mixed
    {
        $current = app(CurrentOrganization::class);

        if ($this->tenantOrganizationId === null) {
            Log::debug('Queued job ran with no tenant bound', [
                'job' => static::class,
            ]);

            // Belt and braces: even the unbound path is wrapped, so a job that
            // binds a tenant internally cannot leak it to the next job.
            $previous = $current->id();

            try {
                return $callback();
            } finally {
                $current->set($previous);
            }
        }

        return $current->runFor($this->tenantOrganizationId, $callback);
    }
}
