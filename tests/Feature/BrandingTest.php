<?php

namespace Tests\Feature;

use App\Models\MonitoringSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_upload_a_logo_and_it_is_served(): void
    {
        Storage::fake('avatars');
        $admin = User::factory()->create(['role' => 'super_admin', 'permissions' => []]);

        $this->actingAs($admin)
            ->from('/developer')
            ->post(route('developer.branding.update'), [
                'logo' => UploadedFile::fake()->image('logo.png', 256, 256),
            ])
            ->assertRedirect('/developer')
            ->assertSessionHas('success');

        $path = MonitoringSetting::current()->branding_logo_path;
        $this->assertNotNull($path);
        Storage::disk('avatars')->assertExists($path);

        $this->get(route('branding.logo'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=604800, public');
    }

    public function test_member_cannot_upload_a_logo(): void
    {
        Storage::fake('avatars');
        $member = User::factory()->create(['role' => 'member', 'permissions' => []]);

        $this->actingAs($member)
            ->post(route('developer.branding.update'), [
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertForbidden();
    }

    public function test_logo_route_404s_when_none_uploaded(): void
    {
        $this->get(route('branding.logo'))->assertNotFound();
    }
}
