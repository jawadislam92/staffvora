<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $name, string $contents): string
    {
        $path = sys_get_temp_dir().'/'.uniqid($name).'.csv';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_it_imports_users_and_clients_scoped_to_the_target_org(): void
    {
        $org = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['organization'];

        $users = $this->csv('users', "name,email,role,team\nAlice,alice@acme.test,member,Design\nBob,bob@acme.test,admin,\n");
        $clients = $this->csv('clients', "name,work_type,tags\nGlobex,hourly,\"vip, retainer\"\n");

        $this->artisan('tenant:import', ['org' => $org->id, '--users' => $users, '--clients' => $clients])
            ->assertExitCode(0);

        // Imported rows belong to the target org (read through the org scope).
        [$userCount, $alice, $clientCount, $globex] = app(CurrentOrganization::class)->runFor($org, fn () => [
            User::where('email', 'alice@acme.test')->count(),
            User::where('email', 'alice@acme.test')->first(),
            Client::where('name', 'Globex')->count(),
            Client::where('name', 'Globex')->first(),
        ]);

        $this->assertSame(1, $userCount);
        $this->assertSame('member', $alice->role);
        $this->assertNotNull($alice->team_id);
        $this->assertSame(1, $clientCount);
        $this->assertSame(['vip', 'retainer'], $globex->tags);

        @unlink($users);
        @unlink($clients);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $org = app(TenantProvisioner::class)->provision('Dry Co', 'Owner', 'owner@dry.test', 'super-secret-pw')['organization'];
        $users = $this->csv('users', "name,email\nCarol,carol@dry.test\n");

        $this->artisan('tenant:import', ['org' => $org->id, '--users' => $users, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertFalse(User::withoutGlobalScopes()->where('email', 'carol@dry.test')->exists());

        @unlink($users);
    }

    public function test_existing_emails_are_skipped_not_duplicated(): void
    {
        $org = app(TenantProvisioner::class)->provision('Dup Co', 'Owner', 'owner@dup.test', 'super-secret-pw')['organization'];
        // The owner already exists; importing the same email must not duplicate.
        $users = $this->csv('users', "name,email\nOwner Again,owner@dup.test\nNew Person,new@dup.test\n");

        $this->artisan('tenant:import', ['org' => $org->id, '--users' => $users])->assertExitCode(0);

        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'owner@dup.test')->count());
        $this->assertTrue(User::withoutGlobalScopes()->where('email', 'new@dup.test')->exists());

        @unlink($users);
    }
}
