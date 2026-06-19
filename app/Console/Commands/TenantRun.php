<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Runs another artisan command once per active organization, each bound to its
 * tenant via CurrentOrganization. Scheduled jobs run with no authenticated user,
 * so without this they would query across every tenant at once (wrong channel,
 * mixed data). Wrapping them here keeps each run scoped to one org. A failure in
 * one org is logged and does not stop the others.
 */
class TenantRun extends Command
{
    protected $signature = 'tenant:run
        {target : The artisan command name to run for each active organization}
        {--only= : Comma-separated organization ids to limit to}';

    protected $description = 'Run an artisan command once per active organization, bound to that tenant.';

    public function handle(CurrentOrganization $current): int
    {
        $command = $this->argument('target');
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        $orgs = Organization::query()
            ->when($only, fn ($q) => $q->whereIn('id', $only))
            ->whereIn('status', [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE])
            ->orderBy('id')
            ->get();

        $failures = 0;

        foreach ($orgs as $org) {
            try {
                $current->runFor($org, function () use ($command) {
                    Artisan::call($command, [], $this->getOutput());
                });
            } catch (\Throwable $e) {
                $failures++;
                $this->error("[org {$org->id}] {$command} failed: ".$e->getMessage());
                Log::error('tenant:run failed', [
                    'organization_id' => $org->id,
                    'command' => $command,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("tenant:run {$command} → {$orgs->count()} org(s), {$failures} failure(s).");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
