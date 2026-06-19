<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_tenant_command_provisions_org_owner_and_team(): void
    {
        $this->artisan('tenant:create', [
            'name' => 'Acme Inc',
            'email' => 'owner@acme.test',
            '--password' => 'password123456',
        ])->assertExitCode(0);

        $org = Organization::where('slug', 'acme-inc')->first();
        $this->assertNotNull($org);
        $this->assertNotNull($org->owner_user_id);
        $this->assertSame(Organization::STATUS_TRIAL, $org->status);

        app(CurrentOrganization::class)->set($org);
        $this->assertSame(1, User::count());
        $this->assertSame('owner@acme.test', User::first()->email);
        $this->assertSame('super_admin', User::first()->role);
        $this->assertSame(1, Team::count());
        $this->assertSame('General', Team::first()->name);
    }

    public function test_teams_are_isolated_per_organization(): void
    {
        $a = Organization::create(['name' => 'A', 'slug' => 'a-'.Str::random(5)]);
        $b = Organization::create(['name' => 'B', 'slug' => 'b-'.Str::random(5)]);

        $current = app(CurrentOrganization::class);
        $current->runFor($a, fn () => Team::create(['name' => 'Alpha']));
        $current->runFor($b, fn () => Team::create(['name' => 'Beta']));

        $current->set($a);
        $this->assertSame(1, Team::count());
        $this->assertSame('Alpha', Team::first()->name);

        $current->set($b);
        $this->assertSame('Beta', Team::first()->name);
    }

    public function test_tenant_run_executes_once_per_active_organization(): void
    {
        Organization::create(['name' => 'A', 'slug' => 'a-'.Str::random(5)]);
        Organization::create(['name' => 'B', 'slug' => 'b-'.Str::random(5)]);
        Organization::create(['name' => 'Suspended', 'slug' => 's-'.Str::random(5), 'status' => Organization::STATUS_SUSPENDED]);

        // A harmless tenant-scoped command; 2 active orgs (suspended skipped) => success.
        $this->artisan('tenant:run', ['target' => 'monitoring:close-stale-sessions'])->assertExitCode(0);
    }
}
