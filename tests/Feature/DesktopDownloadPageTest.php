<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DesktopDownloadPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_open_desktop_downloads_page(): void
    {
        $user = User::factory()->create([
            'role' => 'member',
            'permissions' => [],
        ]);

        $this->actingAs($user)
            ->get(route('desktop-downloads.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('DesktopDownloads')
                ->has('downloads.windows')
                ->where('downloads.mac.available', false)
            );
    }
}
