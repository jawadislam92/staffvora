<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grant (or revoke) platform-admin access by email. Used to bootstrap the SaaS
 * operator account. Looks the user up across all tenants.
 */
class GrantPlatformAdmin extends Command
{
    protected $signature = 'platform:grant {email : User email} {--revoke : Remove platform-admin access instead}';

    protected $description = 'Grant or revoke platform (landlord) admin access for a user.';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));

        $user = User::withoutOrganizationScope()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user with email {$email}.");

            return self::FAILURE;
        }

        $user->is_platform_admin = ! $this->option('revoke');
        $user->save();

        $this->info(sprintf(
            '%s is %s a platform admin.',
            $user->email,
            $user->is_platform_admin ? 'now' : 'no longer',
        ));

        return self::SUCCESS;
    }
}
