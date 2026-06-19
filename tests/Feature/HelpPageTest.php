<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HelpPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_center_loads_for_any_signed_in_user(): void
    {
        $member = User::factory()->create(['role' => 'member', 'permissions' => []]);

        $this->actingAs($member)
            ->get(route('help.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Help/Index'));
    }

    public function test_help_center_requires_authentication(): void
    {
        $this->get(route('help.index'))->assertRedirect(route('login'));
    }
}
