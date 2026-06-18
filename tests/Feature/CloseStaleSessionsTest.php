<?php

namespace Tests\Feature;

use App\Models\TrackingSession;
use App\Models\User;
use App\Models\WorkHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseStaleSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_active_sessions_are_closed_and_hours_synced(): void
    {
        $user = User::factory()->create();

        $stale = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'stale-1',
            'started_at' => now()->subHours(3),
            'last_heartbeat_at' => now()->subHours(2),
            'status' => TrackingSession::STATUS_ACTIVE,
            'total_seconds' => 3600,
            'source' => 'desktop',
        ]);

        $fresh = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'fresh-1',
            'started_at' => now()->subMinutes(30),
            'last_heartbeat_at' => now()->subMinutes(2),
            'status' => TrackingSession::STATUS_ACTIVE,
            'total_seconds' => 1500,
            'source' => 'desktop',
        ]);

        $this->artisan('monitoring:close-stale-sessions')->assertSuccessful();

        $stale->refresh();
        $fresh->refresh();

        $this->assertSame(TrackingSession::STATUS_STOPPED, $stale->status);
        // Stopped at the last heartbeat, not the sweep time — that is the
        // last moment we know the tracker was alive.
        $this->assertTrue($stale->stopped_at->equalTo($stale->last_heartbeat_at));
        $this->assertSame(TrackingSession::STATUS_ACTIVE, $fresh->status);

        $workHour = WorkHour::where('tracking_session_id', $stale->id)->first();
        $this->assertNotNull($workHour);
        $this->assertEqualsWithDelta(1.0, (float) $workHour->hours, 0.001);
        $this->assertSame('tracker', $workHour->source);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $user = User::factory()->create();

        $stale = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'stale-2',
            'started_at' => now()->subHours(3),
            'last_heartbeat_at' => now()->subHours(2),
            'status' => TrackingSession::STATUS_ACTIVE,
            'total_seconds' => 3600,
            'source' => 'desktop',
        ]);

        $this->artisan('monitoring:close-stale-sessions', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(TrackingSession::STATUS_ACTIVE, $stale->refresh()->status);
        $this->assertSame(0, WorkHour::where('tracking_session_id', $stale->id)->count());
    }
}
