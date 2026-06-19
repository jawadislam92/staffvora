<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(string $name): User
    {
        return app(TenantProvisioner::class)
            ->provision($name, "$name Owner", strtolower($name).'@example.test', 'super-secret-pw')['owner'];
    }

    public function test_owner_can_create_rename_and_delete_a_team(): void
    {
        $owner = $this->ownerOf('Acme');

        $this->actingAs($owner)->post(route('teams.store'), ['name' => 'Engineering', 'color' => '#22c55e'])->assertRedirect();
        $team = app(CurrentOrganization::class)->runFor($owner->organization_id, fn () => Team::where('name', 'Engineering')->first());
        $this->assertNotNull($team);

        $this->actingAs($owner)->patch(route('teams.update', $team->id), ['name' => 'Eng', 'color' => '#3b82f6'])->assertRedirect();
        $this->actingAs($owner)->delete(route('teams.destroy', $team->id))->assertRedirect();

        $this->assertNull(Team::withoutOrganizationScope()->find($team->id));
    }

    public function test_a_tenant_cannot_touch_another_tenants_team(): void
    {
        $ownerA = $this->ownerOf('Acme');
        $ownerB = $this->ownerOf('Globex');

        $bTeam = app(CurrentOrganization::class)->runFor($ownerB->organization_id, fn () => Team::create(['name' => 'Secret']));

        $this->actingAs($ownerA)->patch(route('teams.update', $bTeam->id), ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($ownerA)->delete(route('teams.destroy', $bTeam->id))->assertNotFound();

        $this->assertNotNull(Team::withoutOrganizationScope()->find($bTeam->id));
    }
}
