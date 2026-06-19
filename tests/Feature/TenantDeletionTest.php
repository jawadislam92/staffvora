<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_tenant_erases_its_data_and_leaves_others_intact(): void
    {
        app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw');
        app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw');

        $orgA = Organization::where('slug', 'acme')->first();
        $orgB = Organization::where('slug', 'globex')->first();
        $current = app(CurrentOrganization::class);
        $current->runFor($orgA, fn () => Client::create(['name' => 'A Client', 'work_type' => 'fixed']));
        $current->runFor($orgB, fn () => Client::create(['name' => 'B Client', 'work_type' => 'fixed']));

        $this->artisan('tenant:delete', ['org' => $orgA->id, '--force' => true])->assertExitCode(0);

        // Org A and everything it owned is gone.
        $this->assertNull(Organization::find($orgA->id));
        $this->assertSame(0, User::withoutOrganizationScope()->where('organization_id', $orgA->id)->count());
        $this->assertSame(0, Client::withoutOrganizationScope()->where('organization_id', $orgA->id)->count());

        // Org B is untouched.
        $this->assertNotNull(Organization::find($orgB->id));
        $this->assertSame(1, User::withoutOrganizationScope()->where('organization_id', $orgB->id)->count());
        $this->assertSame(1, Client::withoutOrganizationScope()->where('organization_id', $orgB->id)->count());
    }
}
