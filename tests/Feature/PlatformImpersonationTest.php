<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $role = 'admin'): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Op', 'email' => 'op@platform.test', 'password' => 'operator-secret-pw', 'role' => $role,
        ]);
    }

    public function test_operator_can_impersonate_and_return(): void
    {
        $prov = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw');
        $org = $prov['organization'];
        $owner = $prov['owner'];

        // Start: returns a redirect to the signed hand-off on the customer host.
        $resp = $this->actingAs($this->operator(), 'platform')->post(route('platform.impersonate', $org->id));
        $resp->assertStatus(302);
        $location = $resp->headers->get('Location');
        $this->assertStringContainsString('/impersonate/enter', $location);
        $this->assertStringContainsString('signature=', $location);

        // Follow the hand-off: the customer is logged into the web guard.
        $this->get($location)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($owner, 'web');

        // Stop: the web session ends and we're sent back to the portal.
        $this->post(route('platform.stop-impersonating'))->assertStatus(302);
        $this->assertGuest('web');
    }

    public function test_hand_off_rejects_a_tampered_signature(): void
    {
        $this->get('/impersonate/enter?user=1&signature=deadbeef')->assertForbidden();
    }
}
