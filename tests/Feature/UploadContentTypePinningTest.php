<?php

namespace Tests\Feature;

use App\Models\MonitoringSetting;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\ImageGuard;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Mockery;
use Tests\TestCase;

/**
 * REGRESSION GUARD — the stored file's type must come from its CONTENT.
 *
 * The desktop agent is attacker-controlled: every employee holds a
 * `desktop-tracker` token. When the client named the stored file, it named the
 * stored EXTENSION, and on S3 the extension is what becomes the object's
 * ContentType (Flysystem writes uploads as a stream, so its mime detector
 * cannot sniff the buffer and falls through to the key). A genuine PNG carrying
 * <script> in a tEXt chunk, stored as `payload.html`, was served back to the
 * manager as text/html from the app's own origin — stored XSS running in the
 * manager's logged-in session.
 *
 * Inverted from the audit's Area3FilePathAuditTest, which asserted the
 * vulnerable behaviour.
 */
class UploadContentTypePinningTest extends TestCase
{
    use RefreshDatabase;

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

    /** A real, decodable PNG that also carries an HTML/JS payload in a tEXt chunk. */
    private function polyglotPng(string $clientName): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        $payload = "Comment\0<script>alert(document.domain)</script>";

        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NN', 2, 2)."\x08\x02\x00\x00\x00")
            .$chunk('tEXt', $payload)
            .$chunk('IDAT', "\x78\x9c\x63\x60\x60\x60\xf8\x0f\x00\x01\x04\x01\x00")
            .$chunk('IEND', '');

        $path = tempnam(sys_get_temp_dir(), 'poly').'.png';
        file_put_contents($path, $png);

        return new UploadedFile($path, $clientName, 'image/png', null, true);
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

    private function upload(User $owner, TrackingSession $session, UploadedFile $file)
    {
        return $this->withHeaders($this->tokenHeaders($owner))->post('/api/desktop/screenshots', [
            'tracking_session_id' => $session->id,
            'captured_at' => now()->toIso8601String(),
            'image' => $file,
        ], ['Accept' => 'application/json']);
    }

    public function test_hostile_client_filename_never_reaches_the_storage_key(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Ext Co', 'owner@ext.test');
        $session = $this->activeSession($owner, 'uuid-ext');

        $response = $this->upload($owner, $session, $this->polyglotPng('payload.html'));
        $response->assertStatus(201);

        $shot = TrackingScreenshot::find($response->json('id'));

        $this->assertStringEndsWith('.png', $shot->image_path,
            'Stored extension must be derived from the image header, not the client filename.');
        $this->assertStringNotContainsString('payload', $shot->image_path);
        $this->assertStringNotContainsString('.html', $shot->image_path);

        // What the S3 driver would stamp as the object ContentType given this
        // key. Laravel writes the upload as a *stream*, so FinfoMimeTypeDetector
        // cannot sniff the buffer and falls through to the key's extension —
        // which is exactly why the extension has to be trustworthy.
        $stream = fopen('php://memory', 'r');
        $detected = (new FinfoMimeTypeDetector)->detectMimeType($shot->image_path, $stream);
        fclose($stream);

        $this->assertSame('image/png', $detected);
    }

    public function test_every_hostile_extension_is_neutralised(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Ext2 Co', 'owner@ext2.test');
        $session = $this->activeSession($owner, 'uuid-ext2');

        foreach (['x.svg', 'x.xhtml', 'x.htm', 'x.js', 'x.htaccess', 'x.pdf', 'x.php', 'x.'.str_repeat('a', 60)] as $name) {
            $response = $this->upload($owner, $session, $this->polyglotPng($name));

            // Either outcome is safe; what must never happen is the upload
            // being ACCEPTED under the client's extension. (`.php` is refused
            // outright by Laravel's own client-extension block, which is why
            // this loop tolerates a rejection.)
            if ($response->status() !== 201) {
                $this->assertSame(422, $response->status(), "Unexpected status for {$name}.");

                continue;
            }

            $path = TrackingScreenshot::find($response->json('id'))->image_path;

            $this->assertStringEndsWith('.png', $path, "Client filename {$name} leaked into the storage key.");
        }
    }

    public function test_stored_content_type_is_pinned_to_the_sniffed_image_type(): void
    {
        $fake = Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Pin Co', 'owner@pin.test');
        $session = $this->activeSession($owner, 'uuid-pin');

        $seen = [];

        // Partial mock so the ContentType handed to the driver is observable —
        // the local fake driver silently ignores write options, so asserting on
        // the stored file alone could never catch a regression here.
        $spy = Mockery::mock($fake)->makePartial();
        $spy->shouldReceive('putFileAs')
            ->once()
            ->andReturnUsing(function ($path, $file, $name, $options = []) use ($fake, &$seen) {
                $seen = $options;

                return $fake->putFileAs($path, $file, $name, []);
            });
        Storage::set('screenshots', $spy);

        $this->upload($owner, $session, $this->polyglotPng('payload.html'))->assertStatus(201);

        $this->assertSame('image/png', $seen['ContentType'] ?? null,
            'The stored object Content-Type must be pinned to the sniffed raster type.');
    }

    public function test_a_non_raster_upload_is_refused(): void
    {
        Storage::fake('screenshots');
        Bus::fake();

        $owner = $this->tenantOwner('Html Co', 'owner@html.test');
        $session = $this->activeSession($owner, 'uuid-html');

        $tmp = tempnam(sys_get_temp_dir(), 'html').'.png';
        file_put_contents($tmp, '<html><script>alert(1)</script></html>');
        $file = new UploadedFile($tmp, 'shot.png', 'image/png', null, true);

        // Rejected by validation (`image`/`mimes` both sniff the real content),
        // never stored.
        $this->upload($owner, $session, $file)->assertStatus(422);
        $this->assertSame(0, TrackingScreenshot::count());
    }

    public function test_image_guard_refuses_non_raster_bytes(): void
    {
        $this->assertNull(ImageGuard::inspectBytes('<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>'));
        $this->assertNull(ImageGuard::inspectBytes('not an image at all'));
        $this->assertNull(ImageGuard::inspectBytes(''));
    }
}
