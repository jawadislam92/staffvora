<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The entitlement refusal now renders the Upgrade/Locked Inertia page, a NEW
     * page component that is not in the committed Vite manifest until the next
     * asset build. These tests assert the SERVER decision, not the bundle, so
     * stub Vite out rather than making the suite depend on a build step.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** Provision a tenant and bind it as the current org for the test. */
    private function makeOwner(string $type = 'general'): User
    {
        $result = app(TenantProvisioner::class)
            ->provision('Acme Co', 'Owner', 'owner@acme.test', 'super-secret-pw', null, 14, $type);
        app(CurrentOrganization::class)->set($result['organization']);

        return $result['owner'];
    }

    public function test_signup_redirects_into_the_onboarding_wizard(): void
    {
        $this->post(route('register'), [
            'company' => 'Newco',
            'name' => 'Owner',
            'email' => 'owner@newco.test',
            'password' => 'super-secret-pw-1',
            'password_confirmation' => 'super-secret-pw-1',
        ])->assertRedirect(route('onboarding.show'));
    }

    public function test_new_workspace_needs_onboarding(): void
    {
        $owner = $this->makeOwner();
        $this->assertTrue($owner->organization->needsOnboarding());
    }

    public function test_owner_can_view_the_wizard(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->get(route('onboarding.show'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Onboarding')
                ->where('workspaceType', 'general')
                ->where('clientTerm', 'Project'));
    }

    public function test_members_are_redirected_away_from_the_wizard(): void
    {
        $owner = $this->makeOwner();
        $member = app(CurrentOrganization::class)->runFor($owner->organization, fn () => User::create([
            'name' => 'Member',
            'email' => 'member@acme.test',
            'password' => bcrypt('super-secret-pw'),
            'role' => 'member',
        ]));

        $this->actingAs($member)
            ->get(route('onboarding.show'))
            ->assertRedirect(route('dashboard'));
    }

    /**
     * The wizard writes the workspace-type HINT (it re-presets the terminology
     * and the starter work categories) — and nothing else.
     *
     * SECURITY: it must NOT grant capability. `upwork_profiles` is a paid
     * entitlement sold in the Agency toolkit; if picking "agency" in a
     * self-serve wizard switched it on, every customer could grant themselves a
     * paid feature for free. Only the operator (plan, add-on or per-company
     * override) can open it — see WorkspaceTypeTest.
     */
    public function test_owner_can_change_the_workspace_type_hint_but_it_grants_no_access(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->put(route('onboarding.workspace-type'), ['workspace_type' => 'agency'])
            ->assertRedirect();

        $org = $owner->organization->fresh();

        $this->assertSame('agency', $org->workspace_type);
        $this->assertSame('Upwork Profile', $org->profileTerm());

        // The hint changed the wording. It did not buy anything.
        $this->assertFalse($org->usesProfiles());
        $this->actingAs($owner)
            ->get(route('upwork-profiles.index'))
            ->assertForbidden();
    }

    /** The operator opens it, and only the operator. */
    public function test_operator_granting_the_entitlement_opens_the_profile_module(): void
    {
        $owner = $this->makeOwner();
        $org = $owner->organization;

        $org->forceFill(['entitlement_overrides' => ['upwork_profiles' => true]])->save();
        $org->forgetResolvedEntitlements();

        $this->assertTrue($org->fresh()->usesProfiles());
        $this->actingAs($owner)->get(route('upwork-profiles.index'))->assertOk();
    }

    public function test_invalid_workspace_type_is_rejected(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->put(route('onboarding.workspace-type'), ['workspace_type' => 'bogus'])
            ->assertSessionHasErrors('workspace_type');
    }

    public function test_owner_can_invite_a_teammate(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->post(route('onboarding.team'), ['name' => 'Jane', 'email' => 'jane@acme.test'])
            ->assertRedirect()
            ->assertSessionHas('invited');

        $member = User::where('email', 'jane@acme.test')->first();
        $this->assertNotNull($member);
        $this->assertSame('member', $member->role);
    }

    public function test_invite_respects_the_seat_limit(): void
    {
        $owner = $this->makeOwner();
        // Force a 1-seat ceiling; the owner already occupies it.
        $owner->organization->update(['entitlement_overrides' => ['max_users' => 1]]);

        $this->actingAs($owner)
            ->post(route('onboarding.team'), ['name' => 'Jane', 'email' => 'jane@acme.test'])
            ->assertSessionHasErrors('email');

        $this->assertNull(User::where('email', 'jane@acme.test')->first());
    }

    public function test_owner_can_create_the_first_client(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->post(route('onboarding.client'), ['name' => 'First Project', 'work_type' => 'tracker_manual'])
            ->assertRedirect();

        $this->assertNotNull(Client::where('name', 'First Project')->first());
    }

    public function test_completing_marks_the_workspace_and_redirects_to_dashboard(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->post(route('onboarding.complete'))
            ->assertRedirect(route('dashboard'));

        $this->assertFalse($owner->organization->fresh()->needsOnboarding());
    }

    public function test_members_cannot_mutate_onboarding(): void
    {
        $owner = $this->makeOwner();
        $member = app(CurrentOrganization::class)->runFor($owner->organization, fn () => User::create([
            'name' => 'Member',
            'email' => 'member@acme.test',
            'password' => bcrypt('super-secret-pw'),
            'role' => 'member',
        ]));

        $this->actingAs($member)
            ->post(route('onboarding.complete'))
            ->assertForbidden();
    }
}
