<?php

namespace Tests\Feature;

use App\Models\UpworkProfile;
use App\Models\User;
use App\Models\WorkHour;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Regression cover for audit customer-app-rest: GET /upwork-profiles/{id} used
 * to 500 because the controller rendered an Inertia page (UpworkProfiles/Show)
 * that did not exist. The page now exists and the controller shapes bounded
 * data for it.
 *
 * The entitlement gate still decides access: an agency workspace (with the
 * upwork_profiles entitlement) renders it; a general workspace is refused before
 * the controller runs.
 */
class UpworkProfileShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The Locked/Upgrade refusal page isn't in the committed Vite manifest;
        // these tests assert the SERVER decision, not the bundle.
        $this->withoutVite();
    }

    private function provision(string $name, string $email, string $workspaceType = 'general'): User
    {
        return app(TenantProvisioner::class)
            ->provision($name, 'Owner', $email, 'super-secret-pw', null, 14, $workspaceType)['owner'];
    }

    private function makeProfile(User $owner, string $name = 'Agency Seller'): UpworkProfile
    {
        return app(CurrentOrganization::class)->runFor(
            $owner->organization,
            fn () => UpworkProfile::create(['name' => $name])
        );
    }

    public function test_entitled_agency_workspace_renders_the_show_page(): void
    {
        $owner = $this->provision('Agency Co', 'owner@agency-show.test', 'agency');
        $profile = $this->makeProfile($owner);

        // A work-hour matched by tracker == profile name exercises the aggregate
        // + recent-slice query the controller runs.
        app(CurrentOrganization::class)->runFor($owner->organization, fn () => WorkHour::create([
            'user_id' => $owner->id,
            'date' => '2026-06-15',
            'hours' => 5,
            'tracker' => $profile->name,
            'source' => WorkHour::SOURCE_TRACKER,
        ]));

        $this->actingAs($owner)
            ->get(route('upwork-profiles.show', $profile))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('UpworkProfiles/Show')
                ->where('profile.id', $profile->id)
                ->where('profile.name', $profile->name)
                ->where('stats.entries', 1)
                ->where('stats.hours', fn ($v) => (float) $v === 5.0)
                ->has('recent', 1));
    }

    public function test_general_workspace_is_forbidden(): void
    {
        $owner = $this->provision('Inhouse Co', 'owner@inhouse-show.test');
        $profile = $this->makeProfile($owner, 'Internal Profile');

        // The profile exists in this org, so route-model binding resolves; the
        // entitlement middleware is what refuses.
        $this->actingAs($owner)
            ->get(route('upwork-profiles.show', $profile))
            ->assertForbidden();
    }
}
