<?php

namespace App\Console\Commands;

use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provision a new tenant by hand (until self-serve signup ships in Phase 2):
 * creates the organization, its owner user, a default settings row, and a
 * default "General" team — all correctly stamped with the new organization_id.
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

    public function handle(CurrentOrganization $current): int
    {
        $name = trim($this->argument('name'));
        $email = strtolower(trim($this->argument('email')));
        $ownerName = $this->option('owner-name') ?: $email;
        $generated = ! $this->option('password');
        $password = $this->option('password') ?: Str::password(16);
        $slug = $this->option('slug') ? Str::slug($this->option('slug')) : $this->uniqueSlug($name);

        if (User::withoutOrganizationScope()->where('email', $email)->exists()) {
            $this->error("A user with email {$email} already exists.");

            return self::FAILURE;
        }

        $org = Organization::create([
            'name' => $name,
            'slug' => $slug,
            'status' => Organization::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays((int) $this->option('trial-days')),
        ]);

        $owner = $current->runFor($org, function () use ($org, $ownerName, $email, $password) {
            $owner = User::create([
                'name' => $ownerName,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => 'super_admin',
            ]);

            $org->update(['owner_user_id' => $owner->id]);
            Team::create(['name' => 'General']);
            MonitoringSetting::current(); // materialise the org's settings row

            return $owner;
        });

        $this->info('Tenant provisioned.');
        $this->table(['Field', 'Value'], [
            ['Organization', $org->name.' (#'.$org->id.')'],
            ['Slug', $org->slug],
            ['Owner', $owner->email.' (#'.$owner->id.')'],
            ['Password', $generated ? $password : '(as provided)'],
            ['Trial ends', $org->trial_ends_at->toDateString()],
        ]);

        return self::SUCCESS;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $i = 2;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
