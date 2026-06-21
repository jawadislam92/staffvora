<?php

namespace App\Console\Commands;

use App\Models\PlatformUser;
use Illuminate\Console\Command;

/**
 * Bootstraps the operator portal: creates (or promotes) the first platform
 * Owner. This is the only out-of-band way in — there is no public signup for
 * platform staff; the Owner invites everyone else from inside the portal.
 *
 *   php artisan platform:owner you@example.com --name="Jane" --password="…"
 */
class PlatformOwner extends Command
{
    protected $signature = 'platform:owner {email} {--name=} {--password=}';

    protected $description = 'Create or promote the first platform Owner (operator portal).';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $name = $this->option('name') ?: $this->ask('Name', 'Owner');
        $password = $this->option('password') ?: $this->secret('Password (min 12 chars)');

        if (strlen((string) $password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = PlatformUser::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password, 'role' => 'owner'],
        );

        $this->info("Platform Owner ready: {$user->email} (role: owner).");
        $this->line('Sign in at https://'.config('platform.host').'/login');

        return self::SUCCESS;
    }
}
