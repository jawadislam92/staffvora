<?php

namespace App\Console\Commands\Concerns;

use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Marks a console command as PER-TENANT: it reads and writes one workspace's
 * data and must never run with no organization bound.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 * ─────────────────────────────────────────────────────────────────────────────
 * `OrganizationScope` is a no-op when nothing is bound — deliberately, so login
 * and platform queries work. The consequence is that a per-tenant command run
 * WITHOUT `tenant:run` does not fail; it quietly widens to EVERY tenant. For the
 * Slack commands that meant walking every workspace's employees and DMing them
 * — and, because "no tenant bound" used to be read as "platform message", doing
 * it on the OPERATOR's own bot token, into the operator's Slack workspace.
 *
 * Nothing about the command said it needed a tenant, so nothing could remind
 * anyone. This trait makes the requirement part of the command's own definition:
 * `use RequiresBoundTenant;` and the command physically cannot start unbound. It
 * refuses BEFORE handle() runs, so no query, no write and no Slack call happens.
 *
 * A companion guard test (tests/Feature/TenantCommandBindingTest) enumerates
 * every command in app/Console/Commands and fails the suite unless each one is
 * either tenant-bound via this trait or explicitly listed as platform-level —
 * so the NEXT command someone adds has to be classified, rather than defaulting
 * to the unsafe behaviour.
 */
trait RequiresBoundTenant
{
    /**
     * Exit code for "refused: no tenant bound". A plain failure — `tenant:run`
     * counts it, the scheduler sees a non-zero exit, and nothing runs.
     */
    public const NO_TENANT_EXIT_CODE = 1;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $current = app(CurrentOrganization::class);

        if (! $current->check()) {
            $name = $this->getName() ?: static::class;

            $message = sprintf(
                '%s is a PER-TENANT command and no organization is bound, so it refused to run. '
                .'Run it as: php artisan tenant:run %s   (or add --only=<org id> to that).',
                $name,
                $name
            );

            $output->writeln('<error>'.$message.'</error>');

            Log::warning('Per-tenant console command refused: no organization bound', [
                'command' => $name,
            ]);

            return self::NO_TENANT_EXIT_CODE;
        }

        return parent::execute($input, $output);
    }
}
