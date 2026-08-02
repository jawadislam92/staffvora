<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Client;
use App\Models\Organization;
use App\Models\UpworkProfile;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Upwork "Profiles" module is gated by the `upwork_profiles` ENTITLEMENT and
 * by nothing else.
 *
 * `organizations.workspace_type` used to decide it, through a second gate class
 * (`EnsureProfilesAreEnabled`) and a second client-side flag
 * (`workspace.uses_profiles`). Both are gone. `workspace_type` now survives ONLY
 * as a signup hint: it picks the starter work categories, the client/profile
 * wording, and which add-ons go in the new workspace's basket — it grants
 * nothing and denies nothing.
 *
 * These tests are the guardrail for that: they prove the entitlement alone opens
 * and closes the module, in BOTH directions, regardless of workspace_type.
 */
class WorkspaceTypeTest extends TestCase
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

    private function provision(string $name, string $email, string $workspaceType = 'general'): \App\Models\User
    {
        return app(TenantProvisioner::class)
            ->provision($name, 'Owner', $email, 'super-secret-pw', null, 14, $workspaceType)['owner'];
    }

    /** Flip one feature key for one company through the ONE override path. */
    private function setOverride(Organization $org, string $key, mixed $value): void
    {
        $org->forceFill([
            'entitlement_overrides' => array_merge($org->entitlement_overrides ?? [], [$key => $value]),
        ])->save();

        $org->forgetResolvedEntitlements();
        Entitlements::flushMemo();
    }

    // -----------------------------------------------------------------------
    // The gate itself
    // -----------------------------------------------------------------------

    public function test_the_parallel_workspace_type_gate_no_longer_exists(): void
    {
        $this->assertFalse(
            class_exists(\App\Http\Middleware\EnsureProfilesAreEnabled::class),
            'The parallel edition gate must stay deleted — entitlements are the only access path.',
        );

        $this->assertArrayNotHasKey('workspace.profiles', app('router')->getMiddleware());

        // And no route may still reference it.
        foreach (Route::getRoutes() as $route) {
            $this->assertNotContains('workspace.profiles', $route->gatherMiddleware());
        }
    }

    public function test_profile_routes_are_gated_by_the_upwork_profiles_entitlement(): void
    {
        $names = [
            'upwork-profiles.index', 'upwork-profiles.create', 'upwork-profiles.store',
            'upwork-profiles.bulk-status', 'upwork-profiles.set-status', 'upwork-profiles.show',
            'upwork-profiles.edit', 'upwork-profiles.update', 'upwork-profiles.destroy',
        ];

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Missing route {$name}");
            $this->assertContains(
                'entitlement:upwork_profiles',
                $route->gatherMiddleware(),
                "{$name} must be gated by the entitlement middleware.",
            );
        }
    }

    // -----------------------------------------------------------------------
    // The shared prop — one client-side source of truth
    // -----------------------------------------------------------------------

    public function test_general_workspace_sees_zero_upwork(): void
    {
        $owner = $this->provision('Acme Co', 'owner@acme.test');

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('entitlements.upwork_profiles', false)
                ->where('workspace.profile_term', 'Profile')
                ->where('workspace.client_term', 'Project')
                // The duplicate client-side source of truth is gone for good.
                ->missing('workspace.uses_profiles'));

        $this->assertFalse($owner->organization->usesProfiles());
    }

    public function test_agency_signup_still_ends_up_with_the_entitlement(): void
    {
        $owner = $this->provision('Agency Co', 'owner@agency.test', 'agency');

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('entitlements.upwork_profiles', true)
                ->where('workspace.profile_term', 'Upwork Profile')
                ->missing('workspace.uses_profiles'));

        // And it is granted by the ADD-ON, never by the workspace_type column.
        $this->assertSame('addon', $owner->organization->entitlementSource('upwork_profiles'));
        $this->assertTrue($owner->organization->usesProfiles());
    }

    // -----------------------------------------------------------------------
    // workspace_type is a HINT, the entitlement is the decision
    // -----------------------------------------------------------------------

    public function test_a_general_workspace_granted_the_entitlement_gets_the_whole_module(): void
    {
        $owner = $this->provision('Inhouse Co', 'owner@inhouse.test');
        $org = $owner->organization;

        // Closed to begin with…
        $this->actingAs($owner)->get(route('upwork-profiles.index'))
            ->assertForbidden();

        // …the operator grants the feature to this ONE company, and nothing else
        // changes — workspace_type is still 'general'.
        $this->setOverride($org, 'upwork_profiles', true);

        $this->assertSame('general', $org->fresh()->workspace_type);
        $this->actingAs($owner)->get(route('upwork-profiles.index'))->assertOk();

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('entitlements.upwork_profiles', true));
    }

    public function test_an_agency_workspace_with_the_entitlement_revoked_loses_the_module(): void
    {
        $owner = $this->provision('Agency Co', 'owner@agency-revoked.test', 'agency');
        $org = $owner->organization;

        $this->actingAs($owner)->get(route('upwork-profiles.index'))->assertOk();

        // A per-company `false` beats the add-on and the plan.
        $this->setOverride($org, 'upwork_profiles', false);

        $this->assertSame('agency', $org->fresh()->workspace_type);
        $this->assertFalse($org->fresh()->usesProfiles());
        $this->actingAs($owner)->get(route('upwork-profiles.index'))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('entitlements.upwork_profiles', false));
    }

    public function test_revoking_the_agency_addon_closes_the_module(): void
    {
        $owner = $this->provision('Agency Co', 'owner@agency-addon.test', 'agency');
        $org = $owner->organization;

        $this->actingAs($owner)->get(route('upwork-profiles.index'))->assertOk();

        $org->revokeAddon(Addon::where('key', 'agency_toolkit')->firstOrFail());

        $this->actingAs($owner)->get(route('upwork-profiles.index'))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Signup stores the hint (and only the hint)
    // -----------------------------------------------------------------------

    public function test_signup_stores_the_chosen_workspace_type_as_a_hint_and_grants_the_entitlement(): void
    {
        $this->post(route('register'), [
            'company' => 'Agency LLC',
            'workspace_type' => 'agency',
            'name' => 'Owner',
            'email' => 'owner@agencyllc.test',
            'password' => 'super-secret-pw-1',
            'password_confirmation' => 'super-secret-pw-1',
        ])->assertRedirect();

        $org = Organization::where('slug', 'agency-llc')->first();
        $this->assertNotNull($org);
        $this->assertSame('agency', $org->workspace_type);
        $this->assertTrue($org->usesProfiles());
        $this->assertSame('addon', $org->entitlementSource('upwork_profiles'));
    }

    public function test_signup_defaults_to_general_when_unspecified(): void
    {
        $this->post(route('register'), [
            'company' => 'Inhouse Co',
            'name' => 'Owner',
            'email' => 'owner@inhouse-signup.test',
            'password' => 'super-secret-pw-1',
            'password_confirmation' => 'super-secret-pw-1',
        ])->assertRedirect();

        $org = Organization::where('slug', 'inhouse-co')->first();
        $this->assertSame('general', $org->workspace_type);
        $this->assertFalse($org->usesProfiles());
        $this->assertCount(0, $org->activeAddons()->get());
    }

    public function test_permission_catalogue_has_no_upwork_group(): void
    {
        $groups = array_keys(config('access.permissions'));

        $this->assertContains('Profiles', $groups);
        $this->assertNotContains('Upwork Profiles', $groups);
    }

    // -----------------------------------------------------------------------
    // Downstream behaviour is unchanged (it all reads usesProfiles())
    // -----------------------------------------------------------------------

    public function test_general_workspace_can_create_project_without_a_profile_and_cannot_open_profile_module(): void
    {
        $owner = $this->provision('Product Co', 'owner@product.test');

        $this->actingAs($owner)
            ->post(route('clients.store'), [
                'name' => 'Website Redesign',
                'work_type' => 'tracker_manual',
                'tags' => [],
                'upwork_profile_ids' => [],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('clients.index'));

        $this->assertDatabaseHas('clients', [
            'organization_id' => $owner->organization_id,
            'name' => 'Website Redesign',
            'upwork_profile_id' => null,
        ]);

        // The one gate now answers with the plan-upgrade page, not a bare 404.
        $this->actingAs($owner)->get(route('upwork-profiles.index'))
            ->assertForbidden();
    }

    public function test_agency_workspace_requires_an_organization_scoped_profile_for_profile_work(): void
    {
        $owner = $this->provision('Agency Co', 'owner@agency-client.test', 'agency');
        app(CurrentOrganization::class)->set($owner->organization_id);

        $this->actingAs($owner)
            ->post(route('clients.store'), [
                'name' => 'Marketplace Client',
                'work_type' => 'fixed',
                'tags' => [],
                'upwork_profile_ids' => [],
            ])
            ->assertSessionHasErrors('upwork_profile_ids');

        $profile = UpworkProfile::create(['name' => 'Agency Seller Account']);

        $this->actingAs($owner)
            ->post(route('clients.store'), [
                'name' => 'Marketplace Client',
                'work_type' => 'fixed',
                'tags' => [],
                'upwork_profile_ids' => [$profile->id],
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_general_desktop_metadata_is_generic_and_manual_entries_ignore_profile_values(): void
    {
        $owner = $this->provision('General Co', 'owner@general.test');
        app(CurrentOrganization::class)->set($owner->organization_id);
        UpworkProfile::create(['name' => 'Legacy Hidden Profile']);
        $project = Client::create(['name' => 'Internal Launch', 'work_type' => 'tracker_manual']);

        Sanctum::actingAs($owner, ['desktop-tracker']);
        $this->getJson(route('desktop.work-types'))
            ->assertOk()
            ->assertJsonPath('work_types.0.label', 'Tracked or manual time');
        $this->getJson(route('desktop.upwork-profiles'))
            ->assertOk()
            ->assertJsonCount(0, 'profiles');

        $this->actingAs($owner)
            ->post(route('work-hours.store'), [
                'date' => now()->toDateString(),
                'description' => 'Public product work',
                'work_type' => 'tracker',
                'client_id' => $project->id,
                'tracker' => 'Legacy Hidden Profile',
                'entry_mode' => 'duration',
                'hours' => 1,
                'minutes' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_hours', [
            'organization_id' => $owner->organization_id,
            'client_id' => $project->id,
            'tracker' => null,
        ]);
    }
}
