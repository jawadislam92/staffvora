<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarDeliveryTest extends TestCase
{
    public function test_public_avatar_can_be_served_without_a_storage_symlink(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/example.jpg', 'avatar-content');

        $response = $this->get('/storage/avatars/example.jpg');

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, public');

        $this->assertSame(
            'avatar-content',
            $response->streamedContent()
        );
    }

    public function test_missing_avatar_returns_not_found(): void
    {
        Storage::fake('public');

        $this->get('/storage/avatars/missing.jpg')->assertNotFound();
    }

    public function test_avatar_path_does_not_accept_nested_paths(): void
    {
        Storage::fake('public');

        $this->get('/storage/avatars/nested/example.jpg')->assertNotFound();
    }
}
