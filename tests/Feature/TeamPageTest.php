<?php

namespace Tests\Feature;

use App\Models\TrackingActivitySample;
use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TeamPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_page_requires_timeline_view_others_permission(): void
    {
        $member = User::factory()->create(['role' => 'member', 'permissions' => []]);

        $this->actingAs($member)->get('/team')->assertForbidden();
        $this->actingAs($member)->get('/team/apps')->assertForbidden();
    }

    public function test_team_page_loads_for_permitted_user(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'permissions' => ['timeline.view_others'],
        ]);

        $this->actingAs($admin)->get('/team')->assertOk();
    }

    public function test_team_apps_aggregates_samples_by_app_and_domain(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'permissions' => ['timeline.view_others'],
        ]);
        $worker = User::factory()->create(['role' => 'member']);

        $session = TrackingSession::create([
            'user_id' => $worker->id,
            'client_uuid' => 'uuid-team-apps',
            'started_at' => now()->subHour(),
            'total_seconds' => 3600,
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        foreach (range(1, 3) as $i) {
            TrackingActivitySample::create([
                'tracking_session_id' => $session->id,
                'user_id' => $worker->id,
                'captured_at' => now()->subMinutes($i),
                'keyboard_count' => 10,
                'mouse_count' => 5,
                'idle_seconds' => 0,
                'active_app' => 'Visual Studio Code',
                'url_domain' => null,
            ]);
        }

        TrackingActivitySample::create([
            'tracking_session_id' => $session->id,
            'user_id' => $worker->id,
            'captured_at' => now()->subMinutes(10),
            'keyboard_count' => 2,
            'mouse_count' => 1,
            'idle_seconds' => 0,
            'active_app' => 'Google Chrome',
            'url_domain' => 'github.com',
        ]);

        $this->actingAs($admin)
            ->get('/team/apps?range=today')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Team/Apps')
                ->where('apps.0.name', 'Visual Studio Code')
                ->where('apps.0.user_count', 1)
                ->where('urls.0.name', 'github.com')
            );
    }
}
