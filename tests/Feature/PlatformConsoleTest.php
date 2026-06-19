<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdmin(): User
    {
        $owner = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];
        $owner->forceFill(['is_platform_admin' => true])->save();

        return $owner;
    }

    public function test_non_platform_admin_is_forbidden(): void
    {
        $owner = app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw')['owner'];

        $this->actingAs($owner)->get(route('platform.index'))->assertForbidden();
    }

    public function test_platform_admin_can_list_tenants(): void
    {
        $admin = $this->platformAdmin();
        app(TenantProvisioner::class)->provision('Globex', 'Owner', 'owner@globex.test', 'super-secret-pw');

        $this->actingAs($admin)->get(route('platform.index'))->assertOk();
    }

    public function test_platform_admin_can_suspend_and_resume_a_tenant(): void
    {
        $admin = $this->platformAdmin();
        $org = Organization::where('slug', 'acme')->first();

        $this->actingAs($admin)->post(route('platform.suspend', $org->id))->assertRedirect();
        $this->assertSame(Organization::STATUS_SUSPENDED, $org->fresh()->status);

        $this->actingAs($admin)->post(route('platform.resume', $org->id))->assertRedirect();
        $this->assertSame(Organization::STATUS_ACTIVE, $org->fresh()->status);
    }

    public function test_platform_admin_can_record_a_payment(): void
    {
        $admin = $this->platformAdmin();
        $org = Organization::where('slug', 'acme')->first();

        $this->actingAs($admin)
            ->post(route('platform.payment', $org->id), ['amount' => '80', 'months' => 1, 'currency' => 'USD'])
            ->assertRedirect();

        $this->assertSame(Organization::STATUS_ACTIVE, $org->fresh()->status);
        $this->assertSame('active', $org->fresh()->subscription->status);
    }
}
