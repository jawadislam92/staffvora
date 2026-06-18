<?php

namespace Tests\Feature;

use App\Jobs\GenerateScreenshotThumbnail;
use App\Models\Client;
use App\Models\TimeEntry;
use App\Models\TrackingActivitySample;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\UpworkProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DesktopApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_login_issues_token_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'tester@example.com',
            'password' => bcrypt('secret-pass-123'),
        ]);

        $this->postJson('/api/desktop/login', [
            'email' => 'tester@example.com',
            'password' => 'secret-pass-123',
            'device_name' => 'Test Device',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_desktop_login_rejects_bad_credentials(): void
    {
        User::factory()->create([
            'email' => 'tester@example.com',
            'password' => bcrypt('secret-pass-123'),
        ]);

        $this->postJson('/api/desktop/login', [
            'email' => 'tester@example.com',
            'password' => 'wrong',
            'device_name' => 'Test Device',
        ])->assertStatus(422);
    }

    public function test_me_endpoint_requires_token(): void
    {
        $this->getJson('/api/desktop/me')->assertUnauthorized();
    }

    public function test_session_start_is_idempotent_per_client_uuid(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['desktop-tracker']);

        $payload = [
            'client_uuid' => 'uuid-aaaa',
            'started_at' => now()->toIso8601String(),
            'work_type' => 'tracker_manual',
            'task_note' => 'Initial task',
            'device_name' => 'Test Device',
            'platform' => 'win32',
            'app_version' => '0.1.0',
        ];

        $first = $this->postJson('/api/desktop/sessions/start', $payload)->assertStatus(201);
        $second = $this->postJson('/api/desktop/sessions/start', $payload)->assertStatus(201);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, TrackingSession::count());
    }

    public function test_clients_endpoint_returns_profile_via_pivot_when_legacy_column_is_null(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['desktop-tracker']);

        $profile = UpworkProfile::create(['name' => 'Pivot Profile']);
        $client = Client::create([
            'name' => 'Pivot Client',
            'work_type' => 'fixed',
            'upwork_profile_id' => null, // legacy column unset
        ]);
        $client->upworkProfiles()->attach($profile->id);

        $response = $this->getJson('/api/desktop/clients')->assertOk();
        $row = collect($response->json('clients'))->firstWhere('id', $client->id);

        $this->assertSame($profile->id, $row['upwork_profile_id']);
        $this->assertSame('Pivot Profile', $row['upwork_profile_name']);
    }

    public function test_session_start_derives_profile_from_pivot_when_legacy_column_is_null(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['desktop-tracker']);

        $profile = UpworkProfile::create(['name' => 'Pivot Tracker']);
        $client = Client::create([
            'name' => 'Pivot Client For Session',
            'work_type' => 'fixed',
            'upwork_profile_id' => null,
        ]);
        $client->upworkProfiles()->attach($profile->id);

        $response = $this->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-pivot-derived',
            'started_at' => now()->toIso8601String(),
            'client_id' => $client->id,
            'task_note' => 'Pivot session',
        ])->assertStatus(201);

        $session = TrackingSession::find($response->json('id'));
        $this->assertSame($profile->id, $session->upwork_profile_id);
    }

    public function test_session_start_uses_user_work_type_and_derives_profile_from_client(): void
    {
        $user = User::factory()->create();
        $profile = UpworkProfile::create(['name' => 'Main Tracker']);
        $client = Client::create([
            'name' => 'Attached Client',
            'work_type' => 'tracker_manual',
            'upwork_profile_id' => $profile->id,
        ]);
        Sanctum::actingAs($user, ['desktop-tracker']);

        $response = $this->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-client-derived',
            'started_at' => now()->toIso8601String(),
            'client_id' => $client->id,
            'work_type' => 'manual',
            'upwork_profile_id' => null,
            'task_note' => 'Client derived setup',
        ])->assertStatus(201);

        $session = TrackingSession::find($response->json('id'));

        $this->assertSame($client->id, $session->client_id);
        // The user's explicit per-entry choice wins over the client engagement type.
        $this->assertSame('manual', $session->work_type);
        // The profile is still derived from the client.
        $this->assertSame($profile->id, $session->upwork_profile_id);
    }

    public function test_session_start_normalises_tracker_manual_to_tracker(): void
    {
        $user = User::factory()->create();
        $client = Client::create([
            'name' => 'Tracker/Manual Client',
            'work_type' => 'tracker_manual',
        ]);
        Sanctum::actingAs($user, ['desktop-tracker']);

        // No explicit work_type: derive from client and normalise.
        $response = $this->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-normalise',
            'started_at' => now()->toIso8601String(),
            'client_id' => $client->id,
            'task_note' => 'Derived from client',
        ])->assertStatus(201);

        $session = TrackingSession::find($response->json('id'));
        $this->assertSame('tracker', $session->work_type);
    }

    public function test_stopped_session_creates_work_hour_with_normalised_work_type(): void
    {
        $user = User::factory()->create();
        $client = Client::create([
            'name' => 'WH Client',
            'work_type' => 'tracker_manual',
        ]);
        Sanctum::actingAs($user, ['desktop-tracker']);

        $start = $this->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-wh-normalise',
            'started_at' => now()->subHour()->toIso8601String(),
            'client_id' => $client->id,
            'task_note' => 'Real work',
        ])->assertStatus(201);

        $sessionId = $start->json('id');

        $this->postJson("/api/desktop/sessions/{$sessionId}/stop", [
            'stopped_at' => now()->toIso8601String(),
            'total_seconds' => 3600,
            'activity_percent' => 50,
            'task_note' => 'Real work',
        ])->assertOk();

        $this->assertDatabaseHas('work_hours', [
            'tracking_session_id' => $sessionId,
            'work_type' => 'tracker',
            'source' => 'tracker',
        ]);
        $this->assertDatabaseMissing('work_hours', ['work_type' => 'tracker_manual']);
    }

    public function test_heartbeat_and_stop_update_session(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['desktop-tracker']);

        $start = $this->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-bbbb',
            'started_at' => now()->subMinutes(30)->toIso8601String(),
        ])->assertStatus(201);

        $sessionId = $start->json('id');

        $this->patchJson("/api/desktop/sessions/{$sessionId}/heartbeat", [
            'total_seconds' => 600,
            'activity_percent' => 42,
        ])->assertOk();

        $this->postJson("/api/desktop/sessions/{$sessionId}/stop", [
            'stopped_at' => now()->toIso8601String(),
            'total_seconds' => 1800,
            'activity_percent' => 55,
            'task_note' => 'Wrapped up',
        ])->assertOk();

        $session = TrackingSession::find($sessionId);
        $this->assertSame('stopped', $session->status);
        $this->assertSame(1800, $session->total_seconds);
        $this->assertSame(55, $session->activity_percent);
        $this->assertSame('Wrapped up', $session->task_note);
    }

    public function test_today_sessions_include_client_name(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Today Client', 'work_type' => 'outside_of_upwork']);
        Sanctum::actingAs($user, ['desktop-tracker']);

        TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-today-client',
            'client_id' => $client->id,
            'started_at' => now(),
            'total_seconds' => 120,
            'status' => 'active',
            'source' => 'desktop',
            'task_note' => 'Today task',
        ]);

        $this->getJson('/api/desktop/sessions/today')
            ->assertOk()
            ->assertJsonPath('sessions.0.client_name', 'Today Client')
            ->assertJsonPath('sessions.0.task_note', 'Today task');
    }

    public function test_week_summary_returns_trailing_seven_days_with_totals(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['desktop-tracker']);

        TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-week-today',
            'started_at' => now(),
            'total_seconds' => 3600,
            'status' => 'stopped',
            'source' => 'desktop',
        ]);
        TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-week-past',
            'started_at' => now()->subDays(2),
            'total_seconds' => 1800,
            'status' => 'stopped',
            'source' => 'desktop',
        ]);
        // Another user's time must not leak into this user's chart.
        TrackingSession::create([
            'user_id' => User::factory()->create()->id,
            'client_uuid' => 'uuid-week-other',
            'started_at' => now(),
            'total_seconds' => 7200,
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        $response = $this->getJson('/api/desktop/sessions/week')->assertOk();

        $days = $response->json('days');
        $this->assertCount(7, $days);
        $this->assertSame(now()->subDays(6)->toDateString(), $days[0]['date']);
        $this->assertSame(now()->toDateString(), $days[6]['date']);
        $this->assertTrue($days[6]['is_today']);
        $this->assertSame(3600, $days[6]['total_seconds']);
        $this->assertSame(1800, $days[4]['total_seconds']);
    }

    public function test_recent_clients_returns_unique_clients_newest_first(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['desktop-tracker']);

        $clientA = Client::create(['name' => 'Client A', 'work_type' => 'tracker_manual']);
        $clientB = Client::create(['name' => 'Client B', 'work_type' => 'fixed']);

        // Two sessions for A (older + newest) and one for B in between.
        TrackingSession::create([
            'user_id' => $user->id, 'client_uuid' => 'uuid-rc-1', 'client_id' => $clientA->id,
            'work_type' => 'tracker', 'task_note' => 'old A note',
            'started_at' => now()->subDays(3), 'status' => 'stopped', 'source' => 'desktop',
        ]);
        TrackingSession::create([
            'user_id' => $user->id, 'client_uuid' => 'uuid-rc-2', 'client_id' => $clientB->id,
            'work_type' => 'fixed', 'task_note' => 'B note',
            'started_at' => now()->subDays(2), 'status' => 'stopped', 'source' => 'desktop',
        ]);
        TrackingSession::create([
            'user_id' => $user->id, 'client_uuid' => 'uuid-rc-3', 'client_id' => $clientA->id,
            'work_type' => 'manual', 'task_note' => 'newest A note',
            'started_at' => now()->subDay(), 'status' => 'stopped', 'source' => 'desktop',
        ]);
        // Other user's session must not leak.
        TrackingSession::create([
            'user_id' => User::factory()->create()->id, 'client_uuid' => 'uuid-rc-4', 'client_id' => $clientB->id,
            'started_at' => now(), 'status' => 'stopped', 'source' => 'desktop',
        ]);

        $rows = $this->getJson('/api/desktop/sessions/recent-clients')
            ->assertOk()
            ->json('clients');

        $this->assertCount(2, $rows);
        $this->assertSame($clientA->id, $rows[0]['client_id']);
        $this->assertSame('manual', $rows[0]['last_work_type']);
        $this->assertSame('newest A note', $rows[0]['last_task_note']);
        $this->assertSame($clientB->id, $rows[1]['client_id']);
    }

    public function test_time_clock_enforces_action_sequence(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['desktop-tracker']);

        // Fresh day: only clock_in is available.
        $this->getJson('/api/desktop/time-clock')
            ->assertOk()
            ->assertJsonPath('last_action', null)
            ->assertJsonPath('available', ['clock_in']);

        // Break before clocking in is rejected with the human message.
        $this->postJson('/api/desktop/time-clock', ['action_type' => 'break_start'])
            ->assertStatus(422);

        $this->postJson('/api/desktop/time-clock', ['action_type' => 'clock_in'])
            ->assertStatus(201)
            ->assertJsonPath('last_action', 'clock_in');

        $this->postJson('/api/desktop/time-clock', ['action_type' => 'break_start'])
            ->assertStatus(201);

        // Cannot clock out while on break.
        $this->postJson('/api/desktop/time-clock', ['action_type' => 'clock_out'])
            ->assertStatus(422);

        $this->postJson('/api/desktop/time-clock', ['action_type' => 'break_end'])
            ->assertStatus(201);
        $this->postJson('/api/desktop/time-clock', ['action_type' => 'clock_out'])
            ->assertStatus(201)
            ->assertJsonPath('available', ['clock_in']);

        $this->assertSame(4, TimeEntry::where('user_id', $user->id)->count());
    }

    public function test_screenshot_upload_persists_file_and_dispatches_thumbnail_job(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['desktop-tracker']);

        $session = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-cccc',
            'started_at' => now(),
            'status' => 'active',
            'source' => 'desktop',
        ]);

        $response = $this->postJson('/api/desktop/screenshots', [
            'tracking_session_id' => $session->id,
            'captured_at' => now()->toIso8601String(),
            'image' => UploadedFile::fake()->image('shot.jpg', 800, 600),
            'activity_percent' => 70,
            'keyboard_count' => 12,
            'mouse_count' => 30,
            'active_app' => 'Code.exe',
            'active_window_title' => 'tests/DesktopApiTest.php',
            'url_domain' => null,
        ])->assertStatus(201);

        $screenshot = TrackingScreenshot::find($response->json('id'));
        $this->assertNotNull($screenshot);
        Storage::disk('screenshots')->assertExists($screenshot->image_path);
        Bus::assertDispatched(GenerateScreenshotThumbnail::class);
    }

    public function test_screenshot_upload_rejects_other_users_session(): void
    {
        Storage::fake('screenshots');

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => 'uuid-dddd',
            'started_at' => now(),
            'status' => 'active',
            'source' => 'desktop',
        ]);

        Sanctum::actingAs($intruder, ['desktop-tracker']);

        $this->postJson('/api/desktop/screenshots', [
            'tracking_session_id' => $session->id,
            'captured_at' => now()->toIso8601String(),
            'image' => UploadedFile::fake()->image('shot.jpg'),
        ])->assertForbidden();
    }

    public function test_activity_batch_inserts_samples(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['desktop-tracker']);

        $session = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-eeee',
            'started_at' => now(),
            'status' => 'active',
            'source' => 'desktop',
        ]);

        $this->postJson('/api/desktop/activity/batch', [
            'tracking_session_id' => $session->id,
            'samples' => [
                [
                    'captured_at' => now()->toIso8601String(),
                    'keyboard_count' => 5,
                    'mouse_count' => 8,
                    'idle_seconds' => 0,
                    'active_app' => 'Code.exe',
                ],
                [
                    'captured_at' => now()->addMinute()->toIso8601String(),
                    'keyboard_count' => 0,
                    'mouse_count' => 0,
                    'idle_seconds' => 60,
                ],
            ],
        ])->assertStatus(201)->assertJson(['accepted' => 2]);

        $this->assertSame(2, TrackingActivitySample::count());
    }

    public function test_meta_endpoints_return_data(): void
    {
        $user = User::factory()->create();
        Client::create(['name' => 'Acme Co', 'work_type' => 'tracker_manual']);

        Sanctum::actingAs($user, ['desktop-tracker']);

        $this->getJson('/api/desktop/clients')
            ->assertOk()
            ->assertJsonStructure(['clients' => [[
                'id',
                'name',
                'work_type',
                'work_type_label',
                'upwork_profile_id',
                'upwork_profile_name',
            ]]]);
        $this->getJson('/api/desktop/work-types')->assertOk()->assertJsonStructure(['work_types']);
        $this->getJson('/api/desktop/settings')
            ->assertOk()
            ->assertJsonStructure(['settings' => [
                'screenshot_interval_min_seconds',
                'screenshot_interval_max_seconds',
                'idle_threshold_seconds',
                'activity_sample_interval_seconds',
                'capture_enabled',
            ]]);
    }
}
