<?php

namespace App\Services;

use App\Models\Addon;
use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// RegionPricing needs no import — it is a sibling in App\Services. It decides
// VISIBILITY and PRICE only, never capability.

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
        string $workspaceType = 'general',
        ?string $country = null,
    ): array {
        // The signup plan is whichever plan the operator FLAGGED as the default
        // trial — never a hardcoded key. Renaming, reordering or archiving a
        // plan can therefore never break signup. Its configurable trial_days
        // sets the trial length, falling back to the caller's default.
        Plan::ensureSeeded();

        // The customer's stated country. Normalised to ISO alpha-2 or dropped;
        // it is a PRICING / VISIBILITY input only and never grants anything.
        $country = RegionPricing::normalizeCountry($country);

        // REGION ENFORCEMENT AT SIGNUP: the operator's default-trial plan still
        // wins, unless the visitor's region does not offer it — then they land
        // on the first plan that region DOES offer. Fails open (keeps the
        // default-trial plan) whenever the region offers nothing.
        $trialPlan = $this->regionTrialPlan($this->trialPlan(), $country);
        $trialDays = $trialPlan?->trial_days ?? $trialDays;

        $org = Organization::create([
            'name' => $orgName,
            'slug' => $this->uniqueSlug($slug ?: $orgName),
            'status' => Organization::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays($trialDays),
            'workspace_type' => in_array($workspaceType, ['general', 'agency', 'bpo', 'freelancer'], true) ? $workspaceType : 'general',
        ]);

        // forceFill rather than the create() array: `country` is deliberately
        // NOT in Organization::$fillable, so nothing can ever mass-assign a
        // company into a cheaper price list from an unrelated form. It is set
        // here at provisioning, and thereafter only by an operator.
        if ($country !== null) {
            $org->forceFill(['country' => $country])->save();
        }

        $owner = $this->current->runFor($org, function () use ($org, $ownerName, $email, $plainPassword, $trialPlan) {
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

            // Start the workspace on the operator's designated trial plan.
            if ($trialPlan) {
                Subscription::create([
                    'plan_id' => $trialPlan->id,
                    'status' => 'trialing',
                    'trial_ends_at' => $org->trial_ends_at,
                ]);
            }

            return $owner;
        });

        // The signup hint decides what goes in the basket, NOT what is allowed.
        $this->attachSignupAddons($org, $org->workspace_type);

        return ['organization' => $org->refresh(), 'owner' => $owner];
    }

    /**
     * Which add-ons a brand-new workspace starts with, chosen from the signup
     * HINT (`workspace_type`).
     *
     * This is a PROVISIONING default — "what did this customer sign up for" —
     * and emphatically NOT an access decision. Access is decided in exactly one
     * place (`Organization::can()`), which resolves per-company override →
     * active add-ons → plan → deny. All this does is put the Agency toolkit in
     * the new agency's basket so a self-serve agency signup behaves exactly as
     * it did before `usesProfiles()` became an entitlement.
     *
     * From that moment on the operator owns it completely: they can revoke the
     * add-on, move `upwork_profiles` onto a plan, or grant it to a General
     * workspace — and the customer's `workspace_type` never changes anything.
     *
     * A missing add-on row (a checkout that has not migrated yet) is not fatal:
     * the workspace simply starts without it, which is the deny direction.
     */
    private function attachSignupAddons(Organization $org, ?string $workspaceType): void
    {
        // Signup hint → add-on keys to pre-attach. Only 'agency' has one today.
        $keys = match ($workspaceType) {
            'agency' => ['agency_toolkit'],
            default => [],
        };

        if ($keys === []) {
            return;
        }

        try {
            Addon::whereIn('key', $keys)
                ->whereNull('archived_at')
                ->get()
                ->each(fn (Addon $addon) => $org->grantAddon($addon));
        } catch (\Throwable $e) {
            // Never let a missing catalogue table fail a signup.
        }
    }

    /**
     * The plan a brand-new signup lands on. NO PLAN KEY APPEARS HERE — the
     * owner decides in the panel:
     *   1. the plan flagged `is_default_trial` (and not archived), then
     *   2. the lowest-sorted public, non-archived plan, then
     *   3. the lowest-sorted non-archived plan of any visibility,
     * so signup survives any catalogue edit, including archiving the flagged
     * plan without flagging another.
     */
    private function trialPlan(): ?Plan
    {
        return Plan::where('is_default_trial', true)->notArchived()->ordered()->first()
            ?? Plan::visible()->ordered()->first()
            ?? Plan::notArchived()->ordered()->first();
    }

    /**
     * REGION ENFORCEMENT AT SIGNUP.
     *
     * The operator's `is_default_trial` plan is still the answer — this only
     * intervenes when the visitor's region rule says that plan is NOT offered
     * where they are (e.g. a region that only sells Free and Starter). Then they
     * land on the FIRST plan that region does offer, in the operator's own
     * display order.
     *
     * It can only ever move a signup to a plan the region OFFERS, and it never
     * touches entitlements: the plan itself still decides what the workspace
     * can do, through the single resolution path in `Organization::can()`. So a
     * visitor claiming a country can never trial a richer plan than the operator
     * chose to sell there.
     *
     * FAILS OPEN in every uncertain case — no country, no matching rule, or a
     * region offering nothing at all — by returning the default-trial plan
     * unchanged. A pricing rule must never be able to block a signup.
     */
    private function regionTrialPlan(?Plan $default, ?string $country): ?Plan
    {
        if ($country === null) {
            return $default;
        }

        $offer = RegionPricing::offer($country);

        // No rule matched, or the resolver already fell back to the public
        // catalogue: nothing to enforce.
        if ($offer['region'] === null || $offer['fell_back'] || $offer['plans'] === []) {
            return $default;
        }

        $offeredIds = array_column($offer['plans'], 'plan_id');

        if ($default !== null && in_array($default->id, $offeredIds, true)) {
            return $default;
        }

        // `offer()` returns plans in the operator's display order, so "the first
        // offered plan" is deterministic.
        return Plan::whereKey($offeredIds[0])->first() ?? $default;
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
