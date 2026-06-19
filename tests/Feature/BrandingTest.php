<?php

namespace Tests\Feature;

use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_on_white_label_plan_can_upload_a_logo_and_it_is_served(): void
    {
        Storage::fake('avatars');
        // Provisioning starts a Pro trial, which includes white-label.
        $admin = app(TenantProvisioner::class)->provision('Acme', 'Owner', 'owner@acme.test', 'super-secret-pw')['owner'];

        $this->actingAs($admin)
            ->from('/settings/branding')
            ->post(route('settings.branding.logo'), [
                'logo' => UploadedFile::fake()->image('logo.png', 256, 256),
            ])
            ->assertRedirect('/settings/branding')
            ->assertSessionHas('success');

        $path = MonitoringSetting::current()->branding_logo_path;
        $this->assertNotNull($path);
        Storage::disk('avatars')->assertExists($path);

        $this->get(route('branding.logo'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=604800, public');
    }

    public function test_branding_is_blocked_without_the_white_label_plan(): void
    {
        Storage::fake('avatars');
        $admin = app(TenantProvisioner::class)->provision('Free Co', 'Owner', 'owner@freeco.test', 'super-secret-pw')['owner'];

        // Downgrade to Free (no white-label).
        app(CurrentOrganization::class)->runFor($admin->organization_id, fn () =>
            $admin->organization->subscription->update(['plan_id' => Plan::where('key', 'free')->first()->id]));

        $this->actingAs($admin)
            ->post(route('settings.branding.logo'), ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('settings.branding.save'), ['display_name' => 'Hacme', 'accent_color' => '#3b82f6'])
            ->assertForbidden();
    }

    public function test_member_cannot_upload_a_logo(): void
    {
        Storage::fake('avatars');
        $member = User::factory()->create(['role' => 'member', 'permissions' => []]);

        $this->actingAs($member)
            ->post(route('settings.branding.logo'), [
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertForbidden();
    }

    public function test_logo_route_404s_when_none_uploaded(): void
    {
        $this->get(route('branding.logo'))->assertNotFound();
    }
}
