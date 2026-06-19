<?php

namespace App\Console\Commands;

use App\Services\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Provision a new tenant by hand (alongside the self-serve signup). Delegates to
 * TenantProvisioner so the command and the signup flow stay identical.
 */
class CreateTenant extends Command
{
    protected $signature = 'tenant:create
        {name : Organization name}
        {email : Owner email address}
        {--owner-name= : Owner display name (defaults to the email)}
        {--password= : Owner password (a strong one is generated if omitted)}
        {--slug= : Subdomain slug (derived from the name if omitted)}
        {--trial-days=14 : Length of the trial}';

    protected $description = 'Provision a new tenant: organization + owner + default settings + a default team.';

    public function handle(TenantProvisioner $provisioner): int
    {
        $name = trim($this->argument('name'));
        $email = strtolower(trim($this->argument('email')));
        $ownerName = $this->option('owner-name') ?: $email;
        $generated = ! $this->option('password');
        $password = $this->option('password') ?: Str::password(16);

        if ($provisioner->emailTaken($email)) {
            $this->error("A user with email {$email} already exists.");

            return self::FAILURE;
        }

        $result = $provisioner->provision(
            orgName: $name,
            ownerName: $ownerName,
            email: $email,
            plainPassword: $password,
            slug: $this->option('slug'),
            trialDays: (int) $this->option('trial-days'),
        );

        $org = $result['organization'];
        $owner = $result['owner'];

        $this->info('Tenant provisioned.');
        $this->table(['Field', 'Value'], [
            ['Organization', $org->name.' (#'.$org->id.')'],
            ['Slug', $org->slug],
            ['Owner', $owner->email.' (#'.$owner->id.')'],
            ['Password', $generated ? $password : '(as provided)'],
            ['Trial ends', optional($org->trial_ends_at)->toDateString()],
        ]);

        return self::SUCCESS;
    }
}
