<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\ImageGuard;
use App\Support\ImageResizer;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * REGRESSION GUARD — image uploads must be refused on a TOTAL pixel budget.
 *
 * A 2 MB single-colour PNG can declare 20,000 x 20,000 px. GD allocates the
 * full source raster (~4 bytes/pixel = ~1.6 GB) the moment ImageResizer
 * downscales it, which happens inside the ordinary web request that renders the
 * team page or the nav logo. This box also hosts the owner's other live sites,
 * so one such avatar is a memory-exhaustion lever on all of them.
 *
 * Per-axis bounds are the wrong shape of check on their own: 7999x7999 is
 * 63.9 Mpx and passes "max 8000 wide". Hence the two-part guard — a
 * `dimensions:` rule on the FormRequest AND a total-pixel check before storage —
 * plus a last-line budget inside ImageResizer itself, which is the single point
 * where every avatar / branding logo / site image is actually decoded.
 *
 * Inverted from the audit's Area3FilePathAuditTest, which asserted that a
 * 20000x20000 avatar was accepted.
 */
class ImageDecompressionBombTest extends TestCase
{
    use RefreshDatabase;

    private function tenantOwner(string $name, string $email): User
    {
        $owner = app(TenantProvisioner::class)->provision($name, 'Owner', $email, 'secret-pass-123')['owner'];
        app(CurrentOrganization::class)->set($owner->organization_id);

        return $owner->fresh();
    }

    /** A tiny PNG whose IHDR DECLARES huge dimensions (decompression bomb shape). */
    private function declaredSizePng(int $w, int $h, string $clientName = 'avatar.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bomb').'.png';
        file_put_contents($path, $this->declaredSizePngBytes($w, $h));

        return new UploadedFile($path, $clientName, 'image/png', null, true);
    }

    private function declaredSizePngBytes(int $w, int $h): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NN', $w, $h)."\x08\x02\x00\x00\x00")
            .$chunk('IDAT', "\x78\x9c\x63\x00\x00\x00\x02\x00\x01")
            .$chunk('IEND', '');
    }

    private function realPng(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    public function test_avatar_upload_rejects_a_declared_20000px_image(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('Bomb Co', 'owner@bomb.test');

        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@bomb.test',
            'avatar' => $this->declaredSizePng(20000, 20000),
        ])->assertSessionHasErrors('avatar');

        $this->assertNull($owner->fresh()->avatar);
    }

    public function test_avatar_upload_rejects_a_pixel_bomb_that_slips_past_per_axis_bounds(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('Axis Co', 'owner@axis.test');

        // 7999 x 7999 = 63.9 Mpx: inside "max 8000 wide/high", far outside the
        // 40 Mpx budget. This is the case only the total-pixel check can catch.
        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@axis.test',
            'avatar' => $this->declaredSizePng(7999, 7999),
        ])->assertSessionHasErrors('avatar');

        $this->assertNull($owner->fresh()->avatar);
    }

    public function test_an_ordinary_avatar_still_uploads(): void
    {
        Storage::fake('avatars');

        $owner = $this->tenantOwner('Normal Co', 'owner@normal.test');

        $path = tempnam(sys_get_temp_dir(), 'ok').'.png';
        file_put_contents($path, $this->realPng(240, 240));

        $this->actingAs($owner)->patch('/profile', [
            'name' => 'Owner',
            'email' => 'owner@normal.test',
            'avatar' => new UploadedFile($path, 'me.png', 'image/png', null, true),
        ])->assertSessionHasNoErrors();

        $stored = $owner->fresh()->avatar;
        $this->assertNotNull($stored);
        $this->assertStringEndsWith('.png', $stored);
        Storage::disk('avatars')->assertExists($stored);
    }

    public function test_image_guard_enforces_a_total_pixel_budget(): void
    {
        $this->assertNull(ImageGuard::inspectBytes($this->declaredSizePngBytes(20000, 20000)));
        $this->assertNull(ImageGuard::inspectBytes($this->declaredSizePngBytes(7999, 7999)));

        $ok = ImageGuard::inspectBytes($this->declaredSizePngBytes(1920, 1080));
        $this->assertSame('png', $ok['extension']);
        $this->assertSame('image/png', $ok['mime']);
    }

    public function test_image_resizer_refuses_to_decode_beyond_its_budget(): void
    {
        $bytes = $this->realPng(200, 200);

        // Budget deliberately below the real pixel count: the resizer must hand
        // the bytes back UNDECODED rather than allocate the source raster.
        $refused = ImageResizer::fit($bytes, 64, 100);
        $this->assertSame($bytes, $refused['bytes']);
        $this->assertNull($refused['mime']);

        // A bomb-shaped header is refused at the default budget too.
        $bomb = $this->declaredSizePngBytes(20000, 20000);
        $this->assertSame($bomb, ImageResizer::fit($bomb, 160)['bytes']);

        // ...and the normal path still downscales, so avatars stay fast.
        $resized = ImageResizer::fit($bytes, 64);
        $this->assertSame('image/png', $resized['mime']);
        $this->assertNotSame($bytes, $resized['bytes']);
        $this->assertSame([64, 64], array_slice(getimagesizefromstring($resized['bytes']), 0, 2));
    }
}
