<?php

namespace Tests\Feature;

use App\Models\Designation;
use App\Models\Organization;
use App\Models\UpworkProfile;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for [medium-23] / [medium-24]: upwork_profiles.name (and the
 * identical designations.name) were unique across ALL tenants, which both let
 * one workspace permanently squat a common name for every other customer and
 * turned the validation error into a cross-tenant existence oracle.
 */
class TenantNameUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $workspace): User
    {
        $owner = app(TenantProvisioner::class)
            ->provision($workspace, 'Owner', 'owner@'.$workspace.'.test', 'super-secret-pw')['owner'];

        $org = Organization::findOrFail($owner->organization_id);
        $org->forceFill([
            'entitlement_overrides' => array_merge($org->entitlement_overrides ?? [], ['upwork_profiles' => true]),
        ])->save();
        $org->forgetResolvedEntitlements();
        \App\Tenancy\Entitlements::flushMemo();

        return $owner;
    }

    public function test_two_workspaces_can_each_own_a_profile_with_the_same_name(): void
    {
        $acme = $this->owner('Acme');
        $globex = $this->owner('Globex');

        $this->actingAs($acme)
            ->post(route('upwork-profiles.store'), ['name' => 'Main'])
            ->assertSessionHasNoErrors();

        $this->actingAs($globex)
            ->post(route('upwork-profiles.store'), ['name' => 'Main'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, UpworkProfile::withoutOrganizationScope()->where('name', 'Main')->count());
    }

    public function test_a_workspace_still_cannot_duplicate_its_own_profile_name(): void
    {
        $acme = $this->owner('Acme');

        $this->actingAs($acme)->post(route('upwork-profiles.store'), ['name' => 'Main'])
            ->assertSessionHasNoErrors();

        $this->actingAs($acme)->post(route('upwork-profiles.store'), ['name' => 'Main'])
            ->assertSessionHasErrors('name');

        // Case-insensitive: MySQL's default collation collides these, SQLite does
        // not — the rule must behave the same on both.
        $this->actingAs($acme)->post(route('upwork-profiles.store'), ['name' => 'main'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, UpworkProfile::withoutOrganizationScope()->count());
    }

    public function test_renaming_a_profile_onto_another_workspaces_name_is_allowed(): void
    {
        $acme = $this->owner('Acme');
        $globex = $this->owner('Globex');

        $globexProfile = app(CurrentOrganization::class)->runFor(
            $globex->organization_id,
            fn () => UpworkProfile::create(['name' => 'Shared Brand'])
        );

        $acmeProfile = app(CurrentOrganization::class)->runFor(
            $acme->organization_id,
            fn () => UpworkProfile::create(['name' => 'Acme Original'])
        );

        $this->actingAs($acme)
            ->put(route('upwork-profiles.update', $acmeProfile), ['name' => 'Shared Brand'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Shared Brand', $acmeProfile->fresh()->name);
        $this->assertSame('Shared Brand', $globexProfile->fresh()->name);
    }

    public function test_a_profile_can_be_saved_under_its_own_unchanged_name(): void
    {
        $acme = $this->owner('Acme');

        $profile = app(CurrentOrganization::class)->runFor(
            $acme->organization_id,
            fn () => UpworkProfile::create(['name' => 'Main'])
        );

        $this->actingAs($acme)
            ->put(route('upwork-profiles.update', $profile), ['name' => 'Main', 'description' => 'edited'])
            ->assertSessionHasNoErrors();
    }

    public function test_designations_are_unique_per_workspace_not_platform_wide(): void
    {
        $acme = $this->owner('Acme');
        $globex = $this->owner('Globex');
        $current = app(CurrentOrganization::class);

        $current->runFor($acme->organization_id, fn () => Designation::create(['name' => 'Project Manager']));
        $current->runFor($globex->organization_id, fn () => Designation::create(['name' => 'Project Manager']));

        $this->assertSame(
            2,
            Designation::withoutOrganizationScope()->where('name', 'Project Manager')->whereNotNull('organization_id')->count()
        );
    }
}
