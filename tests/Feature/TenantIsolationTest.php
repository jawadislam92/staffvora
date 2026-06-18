<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The contract that makes the product safe to sell: one organization can never
 * see or touch another's data. These tests exercise the global scope, the
 * create-time stamping, the unscoped escape hatch, and the auth-driven binding.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
        ]);
    }

    private function current(): CurrentOrganization
    {
        return app(CurrentOrganization::class);
    }

    private function makeUser(Organization $org, string $email): User
    {
        return $this->current()->runFor($org, fn () => User::create([
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => 'member',
        ]));
    }

    public function test_reads_are_restricted_to_the_bound_organization(): void
    {
        $a = $this->org('Org A');
        $b = $this->org('Org B');

        $this->makeUser($a, 'a@example.com');
        $this->current()->runFor($a, fn () => Client::create(['name' => 'A Client', 'work_type' => 'fixed']));

        $this->makeUser($b, 'b@example.com');
        $this->current()->runFor($b, fn () => Client::create(['name' => 'B Client', 'work_type' => 'fixed']));

        $this->current()->set($a);
        $this->assertSame(1, User::count());
        $this->assertSame('a@example.com', User::first()->email);
        $this->assertSame('A Client', Client::first()->name);

        $this->current()->set($b);
        $this->assertSame(1, User::count());
        $this->assertSame('b@example.com', User::first()->email);
        $this->assertSame('B Client', Client::first()->name);
    }

    public function test_create_stamps_the_bound_organization(): void
    {
        $a = $this->org('Org A');

        $this->current()->set($a);
        $client = Client::create(['name' => 'X', 'work_type' => 'fixed']);

        $this->assertSame($a->id, $client->organization_id);
    }

    public function test_a_tenant_cannot_fetch_another_tenants_record_by_id(): void
    {
        $a = $this->org('Org A');
        $b = $this->org('Org B');

        $bClient = $this->current()->runFor($b, fn () => Client::create(['name' => 'Secret', 'work_type' => 'fixed']));

        $this->current()->set($a);
        $this->assertNull(Client::find($bClient->id), 'Org A must not be able to load Org B records by id');
    }

    public function test_unscoped_escape_hatch_sees_all_tenants(): void
    {
        $a = $this->org('Org A');
        $b = $this->org('Org B');
        $this->current()->runFor($a, fn () => Client::create(['name' => 'A', 'work_type' => 'fixed']));
        $this->current()->runFor($b, fn () => Client::create(['name' => 'B', 'work_type' => 'fixed']));

        $this->current()->set($a);
        $this->assertSame(1, Client::count());
        $this->assertSame(2, $this->current()->runUnscoped(fn () => Client::count()));
    }

    public function test_authenticating_a_user_binds_their_organization(): void
    {
        $a = $this->org('Org A');
        $b = $this->org('Org B');
        $userA = $this->makeUser($a, 'a@example.com');
        $this->makeUser($b, 'b@example.com');

        $this->current()->forget();
        $this->actingAs($userA);

        $this->assertSame($a->id, app(CurrentOrganization::class)->id());
        $this->assertSame(1, User::count());
        $this->assertSame('a@example.com', User::first()->email);
    }
}
