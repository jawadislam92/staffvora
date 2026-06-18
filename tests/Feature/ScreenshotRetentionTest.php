<?php

namespace Tests\Feature;

use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScreenshotRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_command_deletes_old_screenshots_and_keeps_recent_ones(): void
    {
        Storage::fake('screenshots');

        $user = User::factory()->create();
        $session = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-retention',
            'started_at' => now()->subDays(100),
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        Storage::disk('screenshots')->put('old/shot.jpg', 'old-bytes');
        Storage::disk('screenshots')->put('old/thumb.jpg', 'old-thumb');
        Storage::disk('screenshots')->put('new/shot.jpg', 'new-bytes');

        $old = TrackingScreenshot::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => now()->subDays(90),
            'image_path' => 'old/shot.jpg',
            'thumbnail_path' => 'old/thumb.jpg',
        ]);

        $recent = TrackingScreenshot::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => now()->subDays(5),
            'image_path' => 'new/shot.jpg',
        ]);

        $this->artisan('monitoring:prune-screenshots', ['--days' => 60])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('tracking_screenshots', ['id' => $old->id]);
        $this->assertDatabaseHas('tracking_screenshots', ['id' => $recent->id]);
        Storage::disk('screenshots')->assertMissing('old/shot.jpg');
        Storage::disk('screenshots')->assertMissing('old/thumb.jpg');
        Storage::disk('screenshots')->assertExists('new/shot.jpg');
    }

    public function test_prune_command_removes_soft_deleted_rows_past_retention(): void
    {
        Storage::fake('screenshots');

        $user = User::factory()->create();
        $session = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-retention-trashed',
            'started_at' => now()->subDays(100),
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        $trashed = TrackingScreenshot::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => now()->subDays(90),
            'image_path' => 'trashed/shot.jpg',
        ]);
        $trashed->delete();

        $this->artisan('monitoring:prune-screenshots', ['--days' => 60])
            ->assertExitCode(0);

        $this->assertSame(0, TrackingScreenshot::withTrashed()->count());
    }

    public function test_dry_run_does_not_delete_anything(): void
    {
        Storage::fake('screenshots');

        $user = User::factory()->create();
        $session = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-retention-dry',
            'started_at' => now()->subDays(100),
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        Storage::disk('screenshots')->put('dry/shot.jpg', 'bytes');

        TrackingScreenshot::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => now()->subDays(90),
            'image_path' => 'dry/shot.jpg',
        ]);

        $this->artisan('monitoring:prune-screenshots', ['--days' => 60, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(1, TrackingScreenshot::count());
        Storage::disk('screenshots')->assertExists('dry/shot.jpg');
    }
}
