<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * CHANGING THE WORKSPACE TYPE AFTER SIGNUP — and the line it must never cross.
 *
 * The owner's words: "Upwork should not be part of default. It should be either
 * selected when someone starts their own organization, or if they change their
 * organization to an Upwork-based agency."
 *
 * Two things follow, and both are asserted here.
 *
 *  1. THERE HAS TO BE A WAY IN. `workspace_type` could previously only be set in
 *     the first-run wizard, so a workspace that signed up General and later
 *     started taking marketplace work was stuck forever. Settings now owns a
 *     real control for it.
 *
 *  2. THAT WAY IN MUST NOT BE A BACK DOOR. `workspace_type` is a HINT — it
 *     shapes vocabulary and decides which optional modules are worth OFFERING.
 *     It is not, and must never become, an access decision. If ticking "Client
 *     services agency" on a settings screen granted `upwork_profiles`, every
 *     customer could award themselves a paid add-on for free, and the whole
 *     entitlement engine would be decoration.
 *
 * {@see WorkspaceTypeTest} is the sibling guardrail proving the ENTITLEMENT
 * alone opens and closes the module. This file proves the SWITCH is inert.
 */
class WorkspaceTypeSwitchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The entitlement refusal renders the Upgrade/Locked Inertia page, which is
     * not in the committed Vite manifest until the next asset build. These tests
     * assert the SERVER decision, not the bundle.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function makeOwner(string $type = 'general', string $email = 'owner@acme.test'): User
    {
        $result = app(TenantProvisioner::class)
            ->provision('Acme Co', 'Owner', $email, 'super-secret-pw', null, 14, $type);
        app(CurrentOrganization::class)->set($result['organization']);

        return $result['owner'];
    }

    // -----------------------------------------------------------------------
    // The control exists, and it works
    // -----------------------------------------------------------------------

    public function test_settings_exposes_the_current_workspace_type(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Settings/Index')
                ->where('workspaceType', 'general'));
    }

    public function test_owner_can_switch_a_general_workspace_to_an_agency_after_signup(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->put(route('settings.workspace-type.update'), ['workspace_type' => 'agency'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $org = $owner->organization->fresh();

        $this->assertSame('agency', $org->workspace_type);
        // The hint's whole job: terminology.
        $this->assertSame('Client', $org->clientTerm());
        $this->assertSame('Upwork Profile', $org->profileTerm());
    }

    public function test_the_switch_works_in_both_directions(): void
    {
        $owner = $this->makeOwner('agency');

        $this->actingAs($owner)
            ->put(route('settings.workspace-type.update'), ['workspace_type' => 'general'])
            ->assertRedirect();

        $org = $owner->organization->fresh();

        $this->assertSame('general', $org->workspace_type);
        $this->assertSame('Project', $org->clientTerm());
        $this->assertSame('Profile', $org->profileTerm());
    }

    public function test_the_new_terminology_reaches_the_shared_inertia_prop_immediately(): void
    {
        $owner = $this->makeOwner();

        // Prime the 5-minute per-org terminology cache with the OLD wording…
        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('workspace.client_term', 'Project'));

        $this->actingAs($owner)
            ->put(route('settings.workspace-type.update'), ['workspace_type' => 'bpo'])
            ->assertRedirect();

        // …and it must be gone on the very next request, not in five minutes.
        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('workspace.client_term', 'Account')
                ->where('workspace.profile_term', 'Profile'));
    }

    public function test_invalid_workspace_type_is_rejected(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->put(route('settings.workspace-type.update'), ['workspace_type' => 'upwork-agency'])
            ->assertSessionHasErrors('workspace_type');

        $this->assertSame('general', $owner->organization->fresh()->workspace_type);
    }

    public function test_a_member_cannot_change_the_workspace_type(): void
    {
        $owner = $this->makeOwner();
        $member = app(CurrentOrganization::class)->runFor($owner->organization, fn () => User::create([
            'name' => 'Member',
            'email' => 'member@acme.test',
            'password' => bcrypt('super-secret-pw'),
            'role' => 'member',
            'permissions' => [],
        ]));

        $this->actingAs($member)
            ->put(route('settings.workspace-type.update'), ['workspace_type' => 'agency'])
            ->assertForbidden();

        $this->assertSame('general', $owner->organization->fresh()->workspace_type);
    }

    // -----------------------------------------------------------------------
    // THE BOUNDARY: the switch grants nothing
    // -----------------------------------------------------------------------

    public function test_switching_to_agency_does_not_grant_the_upwork_entitlement(): void
    {
        $owner = $this->makeOwner();
        $org = $owner->organization;

        $this->assertFalse($org->usesProfiles());

        $this->actingAs($owner)
            ->put(route('settings.workspace-type.update'), ['workspace_type' => 'agency'])
            ->assertRedirect();

        Entitlements::flushMemo();
        $org = $org->fresh();

        // The column moved. Nothing else did.
        $this->assertSame('agency', $org->workspace_type);
        $this->assertFalse($org->usesProfiles());
        $this->assertFalse($org->can('upwork_profiles'));
        $this->assertFalse($org->can('machine_compliance'));

        // Not by attaching the paid add-on…
        $this->assertCount(0, $org->activeAddons()->get());
        // …and not by quietly writing a per-company override either.
        $this->assertArrayNotHasKey('upwork_profiles', $org->entitlement_overrides ?? []);

        // The module itself stays shut, which is the only test that matters.
        $this->actingAs($owner)->get(route('upwork-profiles.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('upwork-profiles.create'))->assertForbidden();
    }

    public function test_switching_type_repeatedly_never_accumulates_an_entitlement(): void
    {
        $owner = $this->makeOwner();

        foreach (['agency', 'general', 'agency', 'bpo', 'agency'] as $type) {
            $this->actingAs($owner)
                ->put(route('settings.workspace-type.update'), ['workspace_type' => $type])
                ->assertRedirect();
        }

        Entitlements::flushMemo();

        $this->assertFalse($owner->organization->fresh()->usesProfiles());
        $this->assertCount(0, $owner->organization->fresh()->activeAddons()->get());
    }

    public function test_the_operator_is_still_the_only_one_who_can_open_the_module(): void
    {
        $owner = $this->makeOwner();
        $org = $owner->organization;

        $this->actingAs($owner)
            ->put(route('settings.workspace-type.update'), ['workspace_type' => 'agency'])
            ->assertRedirect();

        $this->actingAs($owner)->get(route('upwork-profiles.index'))->assertForbidden();

        // The operator grants the add-on — the ONE path that opens it.
        //
        // Granted through the SAME Organization instance that `$owner` carries:
        // actingAs() authenticates this exact in-memory model, and
        // grantAddon() → forgetResolvedEntitlements() drops that instance's
        // per-object memo. Granting via a detached `->fresh()` copy would leave
        // the authenticated one memoising the old answer, and the request would
        // 403 on a workspace that really is entitled.
        $owner->organization->grantAddon(Addon::where('key', 'agency_toolkit')->firstOrFail());
        Entitlements::flushMemo();

        $this->assertTrue($org->fresh()->usesProfiles());
        $this->assertSame('addon', $org->fresh()->entitlementSource('upwork_profiles'));
        $this->actingAs($owner)->get(route('upwork-profiles.index'))->assertOk();
    }

    public function test_an_entitled_workspace_keeps_the_module_when_it_calls_itself_general(): void
    {
        $owner = $this->makeOwner('agency');
        $org = $owner->organization;

        $this->assertTrue($org->usesProfiles());

        $this->actingAs($owner)
            ->put(route('settings.workspace-type.update'), ['workspace_type' => 'general'])
            ->assertRedirect();

        Entitlements::flushMemo();

        // The hint cannot REVOKE either — only the entitlement decides.
        $this->assertSame('general', $org->fresh()->workspace_type);
        $this->assertTrue($org->fresh()->usesProfiles());
        $this->actingAs($owner)->get(route('upwork-profiles.index'))->assertOk();
    }

    // -----------------------------------------------------------------------
    // VISIBILITY: a workspace that is not entitled meets no marketplace wording
    // -----------------------------------------------------------------------

    /**
     * The complaint that started this: "Upwork Profiles" sat in the nav of every
     * workspace, locked, including plain in-house ones.
     *
     * The nav is React, so the assertion that can be made server-side is the one
     * the nav reads: the shared props a general workspace is handed must contain
     * no marketplace vocabulary and a definite `false` for the entitlement. The
     * DOM itself is asserted in the browser pass.
     */
    public function test_a_general_workspace_is_handed_no_marketplace_vocabulary(): void
    {
        $owner = $this->makeOwner();

        foreach (['dashboard', 'settings.index', 'clients.index'] as $name) {
            $response = $this->actingAs($owner)->get(route($name))->assertOk();

            $response->assertInertia(fn (AssertableInertia $p) => $p
                ->where('entitlements.upwork_profiles', false)
                ->where('workspace.profile_term', 'Profile'));

            // Inertia serialises every prop into the page's data-page
            // attribute, so this catches HUMAN-READABLE marketplace wording
            // leaking through any prop.
            //
            // Machine identifiers are deliberately not asserted against: the
            // entitlement map is keyed by `upwork_profiles` and always carries
            // the key (set to `false`) — that key IS the deny, and a workspace
            // that never renders it is exactly what we want. The rule is about
            // vocabulary a customer can read, not about internal key names.
            $response->assertDontSee('Upwork Profile', false);
            $response->assertDontSee('Upwork profile', false);
            $response->assertDontSee('marketplace', false);
            $response->assertDontSee('Marketplace', false);
        }
    }

    public function test_an_entitled_agency_workspace_does_get_the_module_and_the_wording(): void
    {
        $owner = $this->makeOwner('agency');

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('entitlements.upwork_profiles', true)
                ->where('workspace.profile_term', 'Upwork Profile'));

        $this->actingAs($owner)->get(route('upwork-profiles.index'))->assertOk();
    }

    // -----------------------------------------------------------------------
    // REGRESSION: the signup dropdown was a free checkout
    // -----------------------------------------------------------------------

    /**
     * Picking "Client services agency" on the PUBLIC register page used to
     * attach the paid `agency_toolkit` add-on with a NULL `ends_at` — i.e.
     * forever, free, with no operator involved. That is the same self-grant the
     * settings switch is forbidden from doing, just one screen earlier.
     *
     * It is now pinned to the workspace's own trial window: real during the
     * trial (nothing about day-one behaviour changed), gone when the trial ends
     * unless they actually buy it.
     */
    public function test_the_signup_agency_addon_is_scoped_to_the_trial_and_not_granted_forever(): void
    {
        $owner = $this->makeOwner('agency');
        $org = $owner->organization->fresh();

        // Real right now…
        $this->assertTrue($org->usesProfiles());
        $this->assertSame('addon', $org->entitlementSource('upwork_profiles'));

        $pivot = $org->addons()->where('addons.key', 'agency_toolkit')->firstOrFail();
        $this->assertNotNull(
            $pivot->pivot->ends_at,
            'A self-serve signup must never attach a paid add-on with no expiry.',
        );
        $this->assertSame(
            $org->trial_ends_at->toDateTimeString(),
            \Illuminate\Support\Carbon::parse($pivot->pivot->ends_at)->toDateTimeString(),
            'The free window must be exactly the trial, not longer.',
        );

        // …and gone the moment the trial lapses.
        $this->travel(15)->days();

        Entitlements::flushMemo();
        $fresh = Organization::find($org->id);
        $fresh->forgetResolvedEntitlements();

        $this->assertFalse($fresh->usesProfiles());

        // Belt and braces on the HTTP side: a lapsed trial is bounced to
        // billing by EnsureOrganizationIsUsable BEFORE the entitlement gate is
        // reached, so the module is unreachable for two independent reasons.
        // Asserting 403 here would be asserting the wrong guard.
        $this->actingAs($owner)->get(route('upwork-profiles.index'))->assertRedirect();

        $this->travelBack();
    }

    public function test_a_general_signup_still_gets_no_addon_at_all(): void
    {
        $owner = $this->makeOwner();

        $this->assertCount(0, $owner->organization->addons()->get());
        $this->assertFalse($owner->organization->usesProfiles());
    }
}
