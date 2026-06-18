<?php

namespace Tests\Feature;

use App\Models\TrackingActivitySample;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Models\WorkHour;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletionCascadeTest extends TestCase
{
    use RefreshDatabase;

    private function trackerSession(User $user, int $seconds = 3600): TrackingSession
    {
        $tz = config('app.timezone');
        $session = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-cascade',
            'started_at' => Carbon::parse('2026-06-12 10:00:00', $tz),
            'stopped_at' => Carbon::parse('2026-06-12 11:00:00', $tz),
            'total_seconds' => $seconds,
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        TrackingScreenshot::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => Carbon::parse('2026-06-12 10:30:00', $tz),
            'image_path' => 'shots/x.jpg',
        ]);
        TrackingActivitySample::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => Carbon::parse('2026-06-12 10:30:00', $tz),
            'keyboard_count' => 5,
            'mouse_count' => 5,
            'idle_seconds' => 0,
        ]);

        return $session;
    }

    public function test_deleting_a_tracker_work_entry_cascades_to_the_session(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $session = $this->trackerSession($user);

        $wh = WorkHour::create([
            'user_id' => $user->id,
            'date' => '2026-06-12',
            'hours' => 1.0,
            'work_type' => 'tracker',
            'tracking_session_id' => $session->id,
            'source' => 'tracker',
        ]);

        $this->actingAs($user)
            ->delete(route('work-hours.destroy', $wh))
            ->assertRedirect();

        $this->assertDatabaseMissing('work_hours', ['id' => $wh->id]);
        $this->assertDatabaseMissing('tracking_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('tracking_screenshots', ['tracking_session_id' => $session->id]);
        $this->assertDatabaseMissing('tracking_activity_samples', ['tracking_session_id' => $session->id]);
    }

    public function test_deleting_a_manual_entry_does_not_touch_any_session(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $session = $this->trackerSession($user); // unrelated session must survive

        $manual = WorkHour::create([
            'user_id' => $user->id,
            'date' => '2026-06-12',
            'hours' => 2.0,
            'work_type' => 'manual',
            'source' => 'logged',
        ]);

        $this->actingAs($user)
            ->delete(route('work-hours.destroy', $manual))
            ->assertRedirect();

        $this->assertDatabaseMissing('work_hours', ['id' => $manual->id]);
        $this->assertDatabaseHas('tracking_sessions', ['id' => $session->id]);
    }

    public function test_deleting_all_screenshots_purges_the_emptied_session(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $tz = config('app.timezone');

        // 4-minute session with two screenshots 2 minutes apart.
        $session = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-ghost',
            'started_at' => Carbon::parse('2026-06-12 10:00:00', $tz),
            'stopped_at' => Carbon::parse('2026-06-12 10:04:00', $tz),
            'total_seconds' => 240,
            'status' => 'stopped',
            'source' => 'desktop',
        ]);
        $a = TrackingScreenshot::create([
            'tracking_session_id' => $session->id, 'user_id' => $user->id,
            'captured_at' => Carbon::parse('2026-06-12 10:02:00', $tz), 'image_path' => 'a.jpg',
        ]);
        $b = TrackingScreenshot::create([
            'tracking_session_id' => $session->id, 'user_id' => $user->id,
            'captured_at' => Carbon::parse('2026-06-12 10:04:00', $tz), 'image_path' => 'b.jpg',
        ]);

        $this->actingAs($user)
            ->post(route('monitoring.screenshots.bulk-delete'), [
                'screenshot_ids' => [$a->id, $b->id],
                'reason' => 'test',
            ])
            ->assertRedirect();

        // Both shots gone AND the gutted session removed entirely.
        $this->assertDatabaseMissing('tracking_sessions', ['id' => $session->id]);
        $this->assertSame(0, TrackingScreenshot::where('tracking_session_id', $session->id)->count());
    }
}
