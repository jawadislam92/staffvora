<?php

namespace Tests\Feature;

use App\Jobs\GenerateScreenshotThumbnail;
use App\Models\Client;
use App\Models\MonitoringSetting;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\UpworkProfile;
use App\Models\User;
use App\Models\WorkHour;
use App\Services\TenantProvisioner;
use App\Services\TrackingSessionService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression cover for the desktop-agent abuse surface. The desktop app runs on
 * the employee's own machine, so every value it sends is attacker-controlled:
 * these tests pin the server-side controls that stop it fabricating payroll
 * hours, brute-forcing logins, surviving deactivation, reaching another
 * tenant's data, and bombing the server with images.
 */
class DesktopAgentAbuseGuardsTest extends TestCase
{
    use RefreshDatabase;

    /** Provision a real tenant and return its owner, tenant bound. */
    private function tenantOwner(string $name, string $email): User
    {
        $owner = app(TenantProvisioner::class)->provision($name, 'Owner', $email, 'secret-pass-123')['owner'];
        app(CurrentOrganization::class)->set($owner->organization_id);

        return $owner->fresh();
    }

    private function tokenHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('Test Device', ['desktop-tracker'])->plainTextToken];
    }

    /**
     * A tiny PNG whose IHDR *declares* huge dimensions — exactly the shape of a
     * decompression bomb, and the reason the guard must read the header rather
     * than decode. Building a real 20000x20000 GD image here would OOM the test
     * runner, which is the point being defended against.
     */
    private function declaredSizePng(int $width, int $height): UploadedFile
    {
        $chunk = function (string $type, string $data): string {
            return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        };

        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NN', $width, $height)."\x08\x02\x00\x00\x00")
            .$chunk('IDAT', "\x78\x9c\x63\x00\x00\x00\x02\x00\x01")
            .$chunk('IEND', '');

        $path = tempnam(sys_get_temp_dir(), 'bomb').'.png';
        file_put_contents($path, $png);

        return new UploadedFile($path, 'bomb.png', 'image/png', null, true);
    }

    // ---------------------------------------------------------------- hours

    public function test_stop_clamps_fabricated_total_seconds_to_elapsed_wall_clock(): void
    {
        $owner = $this->tenantOwner('Clamp Co', 'owner@clamp.test');
        $headers = $this->tokenHeaders($owner);

        $start = $this->withHeaders($headers)->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-fabricate',
            'started_at' => now()->toIso8601String(),
        ])->assertStatus(201);

        // 100 hours claimed from a session that has been running for seconds.
        $this->withHeaders($headers)->postJson("/api/desktop/sessions/{$start->json('id')}/stop", [
            'stopped_at' => now()->toIso8601String(),
            'total_seconds' => 360000,
        ])->assertStatus(422);

        // And a value that passes the floor guard is still clamped to elapsed.
        $second = $this->withHeaders($headers)->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-fabricate-2',
            'started_at' => now()->toIso8601String(),
        ])->assertStatus(201);

        $this->withHeaders($headers)->postJson("/api/desktop/sessions/{$second->json('id')}/stop", [
            'stopped_at' => now()->toIso8601String(),
            'total_seconds' => 86000, // just under the 24h floor guard
        ])->assertOk();

        $session = TrackingSession::find($second->json('id'));

        $this->assertLessThanOrEqual(
            TrackingSessionService::CLAIM_GRACE_SECONDS + 60,
            $session->total_seconds,
            'total_seconds must be clamped to server-measured elapsed time.'
        );

        // And what reached payroll is minutes, not the ~24 hours claimed.
        $this->assertLessThan(
            0.5,
            (float) WorkHour::where('tracking_session_id', $session->id)->sum('hours'),
            'Fabricated hours must not reach work_hours.'
        );
    }

    public function test_backdated_start_cannot_buy_unbounded_hours(): void
    {
        $owner = $this->tenantOwner('Backdate Co', 'owner@backdate.test');
        $headers = $this->tokenHeaders($owner);

        $start = $this->withHeaders($headers)->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-backdate',
            'started_at' => now()->subYears(20)->toIso8601String(),
        ])->assertStatus(201);

        $session = TrackingSession::find($start->json('id'));

        // The 1999-style backdate is clamped into the offline-queue window.
        $this->assertTrue(
            $session->started_at->greaterThanOrEqualTo(
                now()->subSeconds(TrackingSessionService::MAX_BACKDATE_SECONDS + 60)
            ),
            'started_at must be clamped to a bounded window around server time.'
        );
    }

    public function test_heartbeat_cannot_inflate_total_seconds_beyond_elapsed(): void
    {
        $owner = $this->tenantOwner('Heartbeat Co', 'owner@heartbeat.test');
        $headers = $this->tokenHeaders($owner);

        $start = $this->withHeaders($headers)->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-heartbeat-inflate',
            'started_at' => now()->toIso8601String(),
        ])->assertStatus(201);

        $this->withHeaders($headers)->patchJson("/api/desktop/sessions/{$start->json('id')}/heartbeat", [
            'total_seconds' => 80000,
        ])->assertOk();

        $this->assertLessThanOrEqual(
            TrackingSessionService::CLAIM_GRACE_SECONDS + 60,
            TrackingSession::find($start->json('id'))->total_seconds
        );
    }

    public function test_stop_cannot_be_replayed_on_a_finished_session(): void
    {
        $owner = $this->tenantOwner('Replay Co', 'owner@replay.test');
        $headers = $this->tokenHeaders($owner);

        $start = $this->withHeaders($headers)->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-replay',
            'started_at' => now()->subHour()->toIso8601String(),
        ])->assertStatus(201);

        $id = $start->json('id');

        $this->withHeaders($headers)->postJson("/api/desktop/sessions/{$id}/stop", [
            'stopped_at' => now()->toIso8601String(),
            'total_seconds' => 3600,
        ])->assertOk();

        $this->withHeaders($headers)->postJson("/api/desktop/sessions/{$id}/stop", [
            'stopped_at' => now()->toIso8601String(),
            'total_seconds' => 3600,
        ])->assertStatus(409)->assertJsonPath('already_stopped', true);
    }

    public function test_absurd_date_span_cannot_spin_a_per_day_work_hour_loop(): void
    {
        $owner = $this->tenantOwner('Span Co', 'owner@span.test');

        // Write the hostile span directly: this is the shape of a row already in
        // the database from before the boundary clamp existed, and it is also
        // what the stale-session sweep would feed through finalize().
        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => 'uuid-span',
            'started_at' => now()->subYears(30),
            'stopped_at' => now()->addYears(30),
            'total_seconds' => 80000,
            'status' => TrackingSession::STATUS_STOPPED,
            'source' => 'desktop',
        ]);

        app(TrackingSessionService::class)->syncWorkHour($session->fresh());

        $rows = WorkHour::where('tracking_session_id', $session->id)->count();
        $this->assertGreaterThan(0, $rows);
        $this->assertLessThanOrEqual(
            TrackingSessionService::MAX_SPAN_DAYS,
            $rows,
            'A client-supplied date span must not be able to mass-create work_hours rows.'
        );
    }

    // ------------------------------------------------------------- lockout

    public function test_deactivated_user_cannot_log_in_via_the_desktop_api(): void
    {
        $owner = $this->tenantOwner('Fired Co', 'owner@fired.test');
        $owner->forceFill(['is_active' => false])->save();

        $this->postJson('/api/desktop/login', [
            'email' => 'owner@fired.test',
            'password' => 'secret-pass-123',
            'device_name' => 'Ex Employee Laptop',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_deactivated');

        $this->assertSame(0, $owner->tokens()->count());
    }

    public function test_existing_desktop_token_stops_working_once_the_user_is_deactivated(): void
    {
        $owner = $this->tenantOwner('Revoke Co', 'owner@revoke.test');
        $headers = $this->tokenHeaders($owner);

        $this->withHeaders($headers)->getJson('/api/desktop/me')->assertOk();

        $owner->forceFill(['is_active' => false])->save();

        // Tests share one container across "requests", so the guard instance
        // still holds the user it resolved a moment ago. Production gets a fresh
        // container per request; drop the cache so this mirrors it.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($headers)->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-after-deactivation',
            'started_at' => now()->toIso8601String(),
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_deactivated');

        $this->assertSame(0, TrackingSession::where('user_id', $owner->id)->count());
    }

    public function test_desktop_login_locks_out_after_repeated_failures(): void
    {
        RateLimiter::clear('desktop-login|owner@bruteforce.test|127.0.0.1');
        $this->tenantOwner('Brute Co', 'owner@bruteforce.test');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/desktop/login', [
                'email' => 'owner@bruteforce.test',
                'password' => 'wrong-guess-'.$i,
                'device_name' => 'Attacker',
            ])->assertStatus(422);
        }

        // Sixth attempt is refused before the credentials are even checked, and
        // the correct password no longer helps.
        $this->postJson('/api/desktop/login', [
            'email' => 'owner@bruteforce.test',
            'password' => 'secret-pass-123',
            'device_name' => 'Attacker',
        ])->assertStatus(429);
    }

    // ------------------------------------------------------ tenant isolation

    public function test_foreign_tenant_client_id_is_rejected_and_never_persisted(): void
    {
        $victim = $this->tenantOwner('Victim Co', 'owner@victim.test');
        $victimClient = Client::create(['name' => 'Victim Secret Client', 'work_type' => 'fixed']);

        $attacker = $this->tenantOwner('Attacker Co', 'owner@attacker.test');
        $headers = $this->tokenHeaders($attacker);

        $this->assertNotSame($victim->organization_id, $attacker->organization_id);

        $this->withHeaders($headers)->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-cross-tenant',
            'started_at' => now()->toIso8601String(),
            'client_id' => $victimClient->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_id');

        $this->assertSame(
            0,
            TrackingSession::withoutGlobalScopes()->where('client_id', $victimClient->id)->count(),
            "A foreign tenant's client_id must never be persisted onto a session."
        );
    }

    public function test_foreign_tenant_upwork_profile_id_is_rejected(): void
    {
        $this->tenantOwner('Profile Victim Co', 'owner@profile-victim.test');
        $victimProfile = UpworkProfile::create(['name' => 'Victim Profile']);

        $attacker = $this->tenantOwner('Profile Attacker Co', 'owner@profile-attacker.test');

        $this->withHeaders($this->tokenHeaders($attacker))->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-cross-tenant-profile',
            'started_at' => now()->toIso8601String(),
            'upwork_profile_id' => $victimProfile->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('upwork_profile_id');
    }

    public function test_desktop_created_session_is_stamped_with_the_callers_organization(): void
    {
        $owner = $this->tenantOwner('Stamp Co', 'owner@stamp.test');
        app(CurrentOrganization::class)->forget();

        $start = $this->withHeaders($this->tokenHeaders($owner))->postJson('/api/desktop/sessions/start', [
            'client_uuid' => 'uuid-stamped',
            'started_at' => now()->toIso8601String(),
        ])->assertStatus(201);

        $session = TrackingSession::withoutGlobalScopes()->find($start->json('id'));

        $this->assertSame($owner->organization_id, $session->organization_id);
    }

    // --------------------------------------------------------- screenshots

    public function test_screenshot_hourly_cap_cannot_be_bypassed_by_rotating_captured_at(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Shots Co', 'owner@shots.test');
        $headers = $this->tokenHeaders($owner);

        // current() memoizes per-org in the container, so mutate that instance.
        MonitoringSetting::current()->update([
            'screenshots_per_hour' => 2,
            'capture_enabled' => true,
        ]);

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => 'uuid-shots',
            'started_at' => now(),
            'status' => TrackingSession::STATUS_ACTIVE,
            'source' => 'desktop',
        ]);

        $upload = function (string $capturedAt) use ($headers, $session) {
            return $this->withHeaders($headers)->post('/api/desktop/screenshots', [
                'tracking_session_id' => $session->id,
                'captured_at' => $capturedAt,
                'image' => UploadedFile::fake()->image('shot.jpg', 400, 300),
            ], ['Accept' => 'application/json']);
        };

        $upload(now()->toIso8601String())->assertStatus(201);
        $upload(now()->toIso8601String())->assertStatus(201);

        // The classic bypass: advance the client's own captured_at by an hour so
        // the quota bucket reads empty. The counter is keyed on server receipt
        // time, so it does not move.
        $upload(now()->addHours(3)->toIso8601String())
            ->assertStatus(429)
            ->assertJsonPath('code', 'screenshot_limit_reached');

        $upload(now()->subYears(5)->toIso8601String())
            ->assertStatus(429);

        $this->assertSame(2, TrackingScreenshot::where('user_id', $owner->id)->count());
    }

    public function test_screenshot_captured_at_is_clamped_to_server_time(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Clamp Shots Co', 'owner@clamp-shots.test');

        MonitoringSetting::current()->update(['screenshots_per_hour' => 10, 'capture_enabled' => true]);

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => 'uuid-clamp-shots',
            'started_at' => now(),
            'status' => TrackingSession::STATUS_ACTIVE,
            'source' => 'desktop',
        ]);

        $response = $this->withHeaders($this->tokenHeaders($owner))->post('/api/desktop/screenshots', [
            'tracking_session_id' => $session->id,
            'captured_at' => now()->addYears(50)->toIso8601String(),
            'image' => UploadedFile::fake()->image('shot.jpg', 400, 300),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $screenshot = TrackingScreenshot::find($response->json('id'));

        $this->assertTrue(
            $screenshot->captured_at->lessThanOrEqualTo(now()->addMinutes(10)),
            'A forward-dated capture must be clamped so monitoring evidence cannot be planted.'
        );
    }

    public function test_oversized_image_dimensions_are_rejected_before_decoding(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Bomb Co', 'owner@bomb.test');

        MonitoringSetting::current()->update(['screenshots_per_hour' => 10, 'capture_enabled' => true]);

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => 'uuid-bomb',
            'started_at' => now(),
            'status' => TrackingSession::STATUS_ACTIVE,
            'source' => 'desktop',
        ]);

        $headers = $this->tokenHeaders($owner);

        // Per-axis bound: caught by the `dimensions` rule (header read only).
        $this->withHeaders($headers)->post('/api/desktop/screenshots', [
            'tracking_session_id' => $session->id,
            'captured_at' => now()->toIso8601String(),
            'image' => $this->declaredSizePng(20000, 20000),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        // Total-pixel budget: 7000x6000 = 42 Mpx passes every per-axis rule but
        // is still ~168 MB of GD allocation. Only the pixel budget stops it.
        $this->withHeaders($headers)->post('/api/desktop/screenshots', [
            'tracking_session_id' => $session->id,
            'captured_at' => now()->toIso8601String(),
            'image' => $this->declaredSizePng(7000, 6000),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'screenshot_too_large');

        $this->assertSame(0, TrackingScreenshot::where('user_id', $owner->id)->count());
        Bus::assertNotDispatched(GenerateScreenshotThumbnail::class);
    }
}
