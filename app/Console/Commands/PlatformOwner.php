<?php

namespace App\Console\Commands;

use App\Models\PlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Bootstraps the operator portal: creates (or promotes) the first platform
 * Owner. This is the only out-of-band way in — there is no public signup for
 * platform staff; the Owner invites everyone else from inside the portal.
 *
 *   php artisan platform:owner you@example.com --name="Jane" --random
 *   php artisan platform:owner you@example.com --name="Jane" --password="…"
 *
 * Prefer --random: it generates a strong password server-side and prints it
 * once, so no secret ever lands on the command line / shell history.
 */
class PlatformOwner extends Command
{
    protected $signature = 'platform:owner {email} {--name=} {--password=} {--random : Generate a strong password and print it once}';

    protected $description = 'Create or promote the first platform Owner (operator portal).';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $name = $this->option('name') ?: $this->ask('Name', 'Owner');

        $generated = false;
        $password = $this->option('password');
        if (! $password && $this->option('random')) {
            $password = Str::password(20, letters: true, numbers: true, symbols: false, spaces: false);
            $generated = true;
        } elseif (! $password) {
            $password = $this->secret('Password (min 12 chars)');
        }

        if (strlen((string) $password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = PlatformUser::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password, 'role' => 'owner'],
        );

        $this->info("Platform Owner ready: {$user->email} (role: owner).");

        if ($generated) {
            $this->newLine();
            $this->warn('Temporary password (store it now, change it after first login):');
            $this->line('    '.$password);
            $this->newLine();
        }

        $this->line('Sign in at https://'.config('platform.host').'/login');

        return self::SUCCESS;
    }
}
