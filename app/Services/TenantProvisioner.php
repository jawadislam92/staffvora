<?php

namespace App\Services;

use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Single source of truth for standing up a new tenant: organization + owner +
 * default settings + a default team, all correctly org-stamped. Used by both
 * the self-serve signup (RegisteredUserController) and the manual tenant:create
 * command, so the two can never drift.
 */
class TenantProvisioner
{
    public function __construct(private CurrentOrganization $current) {}

    /** Global (cross-tenant) email-existence check — emails are unique per install. */
    public function emailTaken(string $email): bool
    {
        return User::withoutOrganizationScope()->where('email', $email)->exists();
    }

    /**
     * @return array{organization: Organization, owner: User}
     */
    public function provision(
        string $orgName,
        string $ownerName,
        string $email,
        string $plainPassword,
        ?string $slug = null,
        int $trialDays = 14,
    ): array {
        $org = Organization::create([
            'name' => $orgName,
            'slug' => $this->uniqueSlug($slug ?: $orgName),
            'status' => Organization::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays($trialDays),
        ]);

        $owner = $this->current->runFor($org, function () use ($org, $ownerName, $email, $plainPassword) {
            $owner = User::create([
                'name' => $ownerName,
                'email' => $email,
                'password' => Hash::make($plainPassword),
                'role' => 'super_admin',
            ]);

            $org->forceFill(['owner_user_id' => $owner->id])->save();
            Team::create(['name' => 'General']);
            MonitoringSetting::current(); // materialise the org's settings row

            return $owner;
        });

        return ['organization' => $org->refresh(), 'owner' => $owner];
    }

    public function uniqueSlug(string $name): string
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
