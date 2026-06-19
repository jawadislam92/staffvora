<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_renders(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_signing_up_provisions_a_tenant_and_logs_the_owner_in(): void
    {
        $response = $this->post('/register', [
            'company' => 'Acme Inc',
            'name' => 'Jane Doe',
            'email' => 'jane@acme.test',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);

        $org = Organization::where('slug', 'acme-inc')->first();
        $this->assertNotNull($org);
        $this->assertSame(Organization::STATUS_TRIAL, $org->status);

        app(CurrentOrganization::class)->set($org);
        $owner = User::first();
        $this->assertSame('jane@acme.test', $owner->email);
        $this->assertSame('super_admin', $owner->role);
        $this->assertSame($owner->id, $org->owner_user_id);
        $this->assertSame(1, User::count());
        $this->assertSame(1, Team::count());
    }

    public function test_duplicate_email_is_rejected(): void
    {
        app(TenantProvisioner::class)->provision('First Co', 'First Owner', 'dup@example.test', 'super-secret-pw');

        $response = $this->from('/register')->post('/register', [
            'company' => 'Second Co',
            'name' => 'Second Owner',
            'email' => 'dup@example.test',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
