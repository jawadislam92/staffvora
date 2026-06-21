<?php

namespace App\Services;

use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
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
        // The signup trial length comes from the Pro plan's configurable trial_days
        // (set in the plan editor), falling back to the caller's default.
        Plan::ensureSeeded();
        $trialDays = Plan::where('key', 'pro')->first()?->trial_days ?? $trialDays;

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

            // Materialise the org's settings row, and start attendance tracking
            // from today: a brand-new workspace has no history, so the monthly
            // grid must never brand anyone Absent for days before they signed up.
            MonitoringSetting::current()->update([
                'attendance_tracking_start_date' => now()->toDateString(),
            ]);

            // Start everyone on a full-featured Pro trial.
            Plan::ensureSeeded();
            $trialPlan = Plan::where('key', 'pro')->first() ?? Plan::orderBy('sort')->first();
            if ($trialPlan) {
                Subscription::create([
                    'plan_id' => $trialPlan->id,
                    'status' => 'trialing',
                    'trial_ends_at' => $org->trial_ends_at,
                ]);
            }

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
