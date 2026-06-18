<?php

namespace Tests\Feature;

use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TimelineOvernightTest extends TestCase
{
    use RefreshDatabase;

    private function makeOvernightSession(User $user): TrackingSession
    {
        $tz = config('app.timezone');

        // Friday 21:20 -> Saturday 03:00 business time, 5h40m (20400s) tracked.
        $session = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-overnight',
            'started_at' => Carbon::parse('2026-06-12 21:20:00', $tz),
            'stopped_at' => Carbon::parse('2026-06-13 03:00:00', $tz),
            'total_seconds' => 20400,
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        TrackingScreenshot::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => Carbon::parse('2026-06-12 23:00:00', $tz),
            'image_path' => 'shots/friday.jpg',
        ]);
        TrackingScreenshot::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => Carbon::parse('2026-06-13 00:30:00', $tz),
            'image_path' => 'shots/saturday.jpg',
        ]);

        return $session;
    }

    public function test_start_day_shows_only_pre_midnight_share(): void
    {
        $user = User::factory()->create();
        $session = $this->makeOvernightSession($user);

        // Friday 21:20 -> midnight = 9600s and one screenshot.
        $this->actingAs($user)
            ->get('/timeline?date=2026-06-12')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('initialData.sessions.0.id', $session->id)
                ->where('initialData.sessions.0.day_seconds', 9600)
                ->where('initialData.sessions.0.started_before_day', false)
                ->where('initialData.sessions.0.continues_after_day', true)
                ->has('initialData.sessions.0.screenshots', 1)
                ->where(
                    'initialData.sessions.0.screenshots.0.captured_at',
                    fn ($iso) => str_contains((string) $iso, '23:00')
                )
                ->where('initialData.totals.day', 9600)
            );
    }

    public function test_next_day_shows_post_midnight_screenshots_and_share(): void
    {
        $user = User::factory()->create();
        $session = $this->makeOvernightSession($user);

        // Saturday midnight -> 03:00 = 10800s and the 00:30 screenshot.
        $this->actingAs($user)
            ->get('/timeline?date=2026-06-13')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('initialData.sessions.0.id', $session->id)
                ->where('initialData.sessions.0.day_seconds', 10800)
                ->where('initialData.sessions.0.started_before_day', true)
                ->where('initialData.sessions.0.continues_after_day', false)
                ->has('initialData.sessions.0.screenshots', 1)
                ->where(
                    'initialData.sessions.0.screenshots.0.captured_at',
                    fn ($iso) => str_contains((string) $iso, '00:30')
                )
                ->where('initialData.totals.day', 10800)
            );
    }

    public function test_unrelated_day_shows_nothing(): void
    {
        $user = User::factory()->create();
        $this->makeOvernightSession($user);

        $this->actingAs($user)
            ->get('/timeline?date=2026-06-14')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('initialData.sessions', 0)
                ->where('initialData.totals.day', 0)
            );
    }
}
