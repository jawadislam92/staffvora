<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\DemoDataService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/**
 * Load / remove / inspect the sample-data demo workspace from the console.
 *
 * The customer-facing path is the button on the dashboard; this exists so
 * support can do the same thing for a workspace over the phone, and so the
 * removal promise can be verified from outside the browser:
 *
 *     php artisan staffvora:demo-data status --tenant=acme
 *     php artisan staffvora:demo-data load   --tenant=acme
 *     php artisan staffvora:demo-data remove --tenant=acme
 *
 * A tenant MUST be named — there is no "current" workspace on the console, and
 * silently writing sample people into whichever org happens to be first would
 * be exactly the sort of accident this product cannot afford.
 */
class DemoData extends Command
{
    protected $signature = 'staffvora:demo-data
        {action : load, remove or status}
        {--tenant= : Organization slug or id (required)}';

    protected $description = 'Load, remove or inspect a workspace\'s clearly-labelled demo data.';

    public function handle(CurrentOrganization $current, DemoDataService $demo): int
    {
        $action = strtolower((string) $this->argument('action'));

        if (! in_array($action, ['load', 'remove', 'status'], true)) {
            $this->error('Action must be one of: load, remove, status.');

            return self::FAILURE;
        }

        $tenant = (string) $this->option('tenant');

        if ($tenant === '') {
            $this->error('--tenant is required (organization slug or id).');

            return self::FAILURE;
        }

        $org = Organization::where('slug', $tenant)->first()
            ?? (ctype_digit($tenant) ? Organization::find((int) $tenant) : null);

        if (! $org) {
            $this->error("No workspace matches '{$tenant}'.");

            return self::FAILURE;
        }

        return $current->runFor($org, function () use ($action, $demo, $org) {
            if ($action === 'status') {
                $summary = $demo->summary();
                $this->line($org->name.': '.($summary['loaded']
                    ? "demo data LOADED — {$summary['people']} demo people, {$summary['entries']} demo work entries."
                    : 'no demo data.'));

                return self::SUCCESS;
            }

            $result = $action === 'load' ? $demo->load() : $demo->remove();

            if (! ($result['ok'] ?? false)) {
                $this->error($result['message']);

                return self::FAILURE;
            }

            $this->info($result['message']);

            if (isset($result['removed'])) {
                foreach ($result['removed'] as $table => $count) {
                    $this->line("  {$table}: {$count}");
                }
            }

            return self::SUCCESS;
        });
    }
}
