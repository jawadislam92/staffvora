<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use App\Jobs\GenerateScreenshotThumbnail;
use App\Models\Organization;
use App\Models\TimeEntry;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cover for the infrastructure-side hardening: trusted proxies (without which
 * every IP-keyed rate limiter in the product collapses into one global bucket),
 * the storage-agnostic and memory-bounded thumbnail job, and the set-based
 * rewrite of the per-tenant scheduled commands.
 */
class ScaleHardeningInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        unset($_SERVER['TRUSTED_PROXIES']);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function handleThrough(Request $request): void
    {
        (new TrustProxies)->handle($request, fn ($r) => $r);
    }

    public function test_no_trusted_proxy_configuration_keeps_the_peer_address(): void
    {
        unset($_SERVER['TRUSTED_PROXIES']);

        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
        ]);

        $this->handleThrough($request);

        // Trusting nothing is the safe default: an attacker-supplied
        // X-Forwarded-For must never become the rate-limit key.
        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function test_a_configured_proxy_range_reveals_the_real_client_ip(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = '10.0.0.0/8';

        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->handleThrough($request);

        // Behind the edge, throttles must key on the visitor — not on the one
        // proxy address every visitor on the internet shares.
        $this->assertSame('198.51.100.77', $request->ip());
        // And TLS termination at the edge must be visible, or every generated
        // URL (including signed URLs) comes out as http://.
        $this->assertTrue($request->isSecure());
    }

    public function test_an_untrusted_peer_cannot_spoof_its_address_with_a_header(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = '10.0.0.0/8';

        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',          // not in the trusted range
            'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
        ]);

        $this->handleThrough($request);

        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function test_the_cloudflare_token_expands_to_edge_ranges(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = 'cloudflare';

        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '173.245.48.10',        // inside 173.245.48.0/20
            'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
        ]);

        $this->handleThrough($request);

        $this->assertSame('198.51.100.77', $request->ip());
    }

    public function test_thumbnail_is_generated_through_the_disk_api_not_a_local_path(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is not available.');
        }

        Storage::fake('screenshots');

        $screenshot = $this->screenshotWithBytes($this->pngBytes(800, 600), 'shots/full.png');

        (new GenerateScreenshotThumbnail($screenshot->id))->handle();

        $screenshot->refresh();

        $this->assertNotNull($screenshot->thumbnail_path);
        $this->assertSame(800, $screenshot->width);
        $this->assertSame(600, $screenshot->height);
        Storage::disk('screenshots')->assertExists($screenshot->thumbnail_path);
    }

    public function test_a_decompression_bomb_is_refused_before_gd_allocates(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is not available.');
        }

        Storage::fake('screenshots');

        // A tiny file whose PNG header DECLARES 40,000 x 40,000 pixels. Decoding
        // it would ask GD for roughly 6.4 GB. The header-only dimension read must
        // refuse it, leaving thumbnail_path null rather than killing the worker.
        $screenshot = $this->screenshotWithBytes($this->pngHeaderOnly(40000, 40000), 'shots/bomb.png');

        (new GenerateScreenshotThumbnail($screenshot->id))->handle();

        $this->assertNull($screenshot->refresh()->thumbnail_path);
    }

    public function test_auto_clock_out_closes_a_dangling_clock_in_with_the_set_based_lookup(): void
    {
        $organization = Organization::factory()->timezone('Asia/Karachi')->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);
        $current = app(CurrentOrganization::class);
        $current->set($organization);

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);

        // Deactivated people must be skipped entirely — they were being scanned
        // every single pass for nothing.
        $archived = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => false,
        ]);

        foreach ([$user, $archived] as $person) {
            $clockIn = Carbon::now('Asia/Karachi')->subHours(20);
            TimeEntry::create([
                'user_id' => $person->id,
                'action_type' => 'clock_in',
                'action_timestamp' => $clockIn,
                'action_date' => $clockIn->toDateString(),
                'action_time' => $clockIn->format('H:i:s'),
            ]);
        }

        $this->artisan('attendance:auto-clock-out', ['--cap-hours' => 12])->assertExitCode(0);

        $this->assertSame(
            1,
            TimeEntry::query()->where('user_id', $user->id)->where('action_type', 'clock_out')->count()
        );
        $this->assertSame(
            0,
            TimeEntry::query()->where('user_id', $archived->id)->where('action_type', 'clock_out')->count()
        );
    }

    public function test_auto_clock_out_leaves_a_fresh_clock_in_alone(): void
    {
        $organization = Organization::factory()->timezone('Asia/Karachi')->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);
        app(CurrentOrganization::class)->set($organization);

        $user = User::factory()->create(['organization_id' => $organization->id]);

        $clockIn = Carbon::now('Asia/Karachi')->subHours(3);
        TimeEntry::create([
            'user_id' => $user->id,
            'action_type' => 'clock_in',
            'action_timestamp' => $clockIn,
            'action_date' => $clockIn->toDateString(),
            'action_time' => $clockIn->format('H:i:s'),
        ]);

        $this->artisan('attendance:auto-clock-out', ['--cap-hours' => 12])->assertExitCode(0);

        $this->assertSame(0, TimeEntry::query()->where('action_type', 'clock_out')->count());
    }

    public function test_silent_tracker_check_runs_with_batched_capture_lookups(): void
    {
        $organization = Organization::factory()->timezone('Asia/Karachi')->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);
        app(CurrentOrganization::class)->set($organization);

        $user = User::factory()->create(['organization_id' => $organization->id]);

        // The watchdog is break-aware now: it only alerts about someone who is
        // genuinely clocked in and working, so the fixture has to say so.
        $clockIn = Carbon::now('Asia/Karachi')->subHours(3);
        TimeEntry::create([
            'user_id' => $user->id,
            'action_type' => 'clock_in',
            'action_timestamp' => $clockIn,
            'action_date' => $clockIn->toDateString(),
            'action_time' => $clockIn->format('H:i:s'),
        ]);

        $silent = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-silent',
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subMinute(),
            'total_seconds' => 3600,
            'status' => TrackingSession::STATUS_ACTIVE,
            'source' => 'desktop',
        ]);

        // A second live session that IS capturing — the batched MAX(captured_at)
        // lookup must see its sample and leave it alone.
        $healthy = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-healthy',
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subMinute(),
            'total_seconds' => 3600,
            'status' => TrackingSession::STATUS_ACTIVE,
            'source' => 'desktop',
        ]);

        \App\Models\TrackingActivitySample::create([
            'tracking_session_id' => $healthy->id,
            'user_id' => $user->id,
            'captured_at' => now()->subMinute(),
        ]);

        // First pass records the paused-vs-blocked probe baseline; the counter
        // then advances (working) while capture stays dead, so the second pass
        // is the one that alerts.
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);
        TrackingSession::whereKey($silent->id)->update(['total_seconds' => 4800]);
        TrackingSession::whereKey($healthy->id)->update(['total_seconds' => 4800]);
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);

        $this->assertNotNull($silent->refresh()->health_alerted_at, 'The capture-less session should have been flagged.');
        $this->assertNull($healthy->refresh()->health_alerted_at, 'A session with a fresh sample must not be flagged.');
    }

    public function test_still_working_check_finds_long_running_clock_ins_with_the_set_based_lookup(): void
    {
        $organization = Organization::factory()->timezone('Asia/Karachi')->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);
        app(CurrentOrganization::class)->set($organization);

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Long Shift Person',
        ]);

        $clockIn = Carbon::now('Asia/Karachi')->subHours(10);
        TimeEntry::create([
            'user_id' => $user->id,
            'action_type' => 'clock_in',
            'action_timestamp' => $clockIn,
            'action_date' => $clockIn->toDateString(),
            'action_time' => $clockIn->format('H:i:s'),
        ]);

        // Came back from a break: still OPEN, and the set-based lookup must
        // treat break_end as open exactly like AttendanceCloser::openClockIn did.
        foreach (['break_start' => 6, 'break_end' => 5] as $type => $hoursAgo) {
            $at = Carbon::now('Asia/Karachi')->subHours($hoursAgo);
            TimeEntry::create([
                'user_id' => $user->id,
                'action_type' => $type,
                'action_timestamp' => $at,
                'action_date' => $at->toDateString(),
                'action_time' => $at->format('H:i:s'),
            ]);
        }

        $this->artisan('attendance:still-working-check', ['--dry-run' => true])
            ->expectsOutputToContain('Long Shift Person')
            ->assertExitCode(0);
    }

    private function screenshotWithBytes(string $bytes, string $path): TrackingScreenshot
    {
        $user = User::factory()->create();

        $session = TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => 'uuid-thumb-'.uniqid(),
            'started_at' => now()->subHour(),
            'status' => 'stopped',
            'source' => 'desktop',
        ]);

        Storage::disk('screenshots')->put($path, $bytes);

        return TrackingScreenshot::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => now(),
            'image_path' => $path,
        ]);
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** A structurally valid PNG header declaring arbitrary dimensions. */
    private function pngHeaderOnly(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('N2', $width, $height).pack('C5', 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    }
}
