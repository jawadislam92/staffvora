<?php

namespace Tests\Feature;

use App\Http\Controllers\DesktopDownloadController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The download page must report what is ACTUALLY published.
 *
 * The bug these tests exist to prevent: the controller used to carry hardcoded
 * installer filenames and version strings. Auto-update kept working (it serves
 * files by requested name), so the drift was invisible — while every human
 * visiting /desktop-downloads was told the app was "Not available". Any test
 * here that starts needing a version bump to keep passing means the hardcoding
 * has crept back in.
 */
class DesktopDownloadPageTest extends TestCase
{
    use RefreshDatabase;

    private string $installers;

    protected function setUp(): void
    {
        parent::setUp();

        // A private installer folder per test, and NO fallback directories, so
        // whatever a developer happens to have in desktop/dist-app cannot make
        // these pass or fail.
        $this->installers = storage_path('framework/testing/installers-'.uniqid());
        File::ensureDirectoryExists($this->installers);

        config()->set('desktop.installers_path', $this->installers);
        config()->set('desktop.fallback_paths', []);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->installers);

        parent::tearDown();
    }

    private function member(): User
    {
        return User::factory()->create(['role' => 'member', 'permissions' => []]);
    }

    /** Write a file into the installer folder and drop the discovery cache. */
    private function publish(string $name, string $contents = 'installer-bytes'): string
    {
        File::put($this->installers.'/'.$name, $contents);
        DesktopDownloadController::forgetCachedReleases();

        return $this->installers.'/'.$name;
    }

    /** An electron-builder style manifest naming one artifact. */
    private function publishManifest(string $file, string $version, string $artifact): void
    {
        $this->publish($file, "version: {$version}\n"
            ."files:\n"
            ."  - url: {$artifact}\n"
            ."    sha512: irrelevant\n"
            ."    size: 15\n"
            ."path: {$artifact}\n"
            ."sha512: irrelevant\n"
            ."releaseDate: '2026-08-01T00:00:00.000Z'\n");
    }

    public function test_nothing_published_is_reported_honestly_rather_than_as_a_broken_link(): void
    {
        $this->actingAs($this->member())
            ->get(route('desktop-downloads.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('DesktopDownloads')
                ->where('downloads.windows.available', false)
                ->where('downloads.windows.version', null)
                ->where('downloads.windows.filename', null)
                ->where('downloads.windows.url', null)
                ->where('downloads.mac.available', false)
                ->where('downloads.mac.version', null)
            );

        // ...and the download endpoint 404s rather than exploding on a null path.
        $this->actingAs($this->member())->get(route('desktop-downloads.windows'))->assertNotFound();
        $this->actingAs($this->member())->get(route('desktop-downloads.mac'))->assertNotFound();
    }

    public function test_it_offers_the_version_the_update_manifest_names(): void
    {
        $body = 'staffvora-0.4.6-installer';
        $this->publish('Staffvora Desktop Setup 0.4.6.exe', $body);
        $this->publishManifest('latest.yml', '0.4.6', 'Staffvora Desktop Setup 0.4.6.exe');

        $this->actingAs($this->member())
            ->get(route('desktop-downloads.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('downloads.windows.available', true)
                ->where('downloads.windows.version', '0.4.6')
                ->where('downloads.windows.filename', 'Staffvora Desktop Setup 0.4.6.exe')
                ->where('downloads.windows.size', strlen($body))
                ->where('downloads.windows.sha256', hash('sha256', $body))
                ->where('downloads.windows.url', route('desktop-downloads.windows'))
            );

        $this->actingAs($this->member())
            ->get(route('desktop-downloads.windows'))
            ->assertOk()
            ->assertDownload('Staffvora Desktop Setup 0.4.6.exe');
    }

    /**
     * The regression guard proper: a NEW version must need no code edit. If
     * someone reintroduces a filename constant, this is the test that fails.
     */
    public function test_a_later_release_needs_no_code_change(): void
    {
        $this->publish('Staffvora Desktop Setup 0.4.6.exe');
        $this->publishManifest('latest.yml', '0.4.6', 'Staffvora Desktop Setup 0.4.6.exe');

        $this->publish('Staffvora Desktop Setup 1.2.3.exe');
        $this->publishManifest('latest.yml', '1.2.3', 'Staffvora Desktop Setup 1.2.3.exe');

        $this->actingAs($this->member())
            ->get(route('desktop-downloads.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('downloads.windows.version', '1.2.3')
                ->where('downloads.windows.filename', 'Staffvora Desktop Setup 1.2.3.exe')
            );
    }

    /**
     * `desktop:set-active-version` rolls back by pointing latest.yml at an older
     * archived manifest. The page must follow the manifest, not "newest file".
     */
    public function test_the_manifest_wins_over_a_newer_file_on_disk(): void
    {
        $this->publish('Staffvora Desktop Setup 0.4.6.exe');
        $this->publish('Staffvora Desktop Setup 0.5.0.exe');
        $this->publishManifest('latest.yml', '0.4.6', 'Staffvora Desktop Setup 0.4.6.exe');

        $this->actingAs($this->member())
            ->get(route('desktop-downloads.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('downloads.windows.version', '0.4.6')
                ->where('downloads.windows.filename', 'Staffvora Desktop Setup 0.4.6.exe')
            );
    }

    /** No manifest yet — still offer the newest build rather than hiding it. */
    public function test_it_falls_back_to_the_highest_version_on_disk_without_a_manifest(): void
    {
        $this->publish('Staffvora Desktop Setup 0.4.6.exe');
        $this->publish('Staffvora Desktop Setup 0.10.0.exe');
        $this->publish('Staffvora Desktop Setup 0.4.6.exe.blockmap');

        $this->actingAs($this->member())
            ->get(route('desktop-downloads.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('downloads.windows.available', true)
                // 0.10.0 > 0.4.6 — version ordering, not string ordering.
                ->where('downloads.windows.version', '0.10.0')
                ->where('downloads.windows.filename', 'Staffvora Desktop Setup 0.10.0.exe')
            );
    }

    public function test_a_manifest_filename_that_escapes_the_installer_folder_is_refused(): void
    {
        // A real, readable file one level up — if traversal worked, this is what
        // would be handed to a browser.
        $outside = dirname($this->installers).'/escaped-secret.exe';
        File::put($outside, 'must-never-be-served');

        try {
            foreach ([
                '../escaped-secret.exe',
                '..\\escaped-secret.exe',
                '/etc/passwd.exe',
                'C:\\Windows\\System32\\cmd.exe',
                '%2e%2e%2fescaped-secret.exe',
            ] as $attempt) {
                File::put($this->installers.'/latest.yml', "version: 9.9.9\npath: {$attempt}\n");
                DesktopDownloadController::forgetCachedReleases();

                $this->actingAs($this->member())
                    ->get(route('desktop-downloads.index'))
                    ->assertOk()
                    ->assertInertia(fn (AssertableInertia $page) => $page
                        ->where('downloads.windows.available', false)
                        ->where('downloads.windows.filename', null)
                        ->where('downloads.windows.version', null)
                    );

                $this->actingAs($this->member())
                    ->get(route('desktop-downloads.windows'))
                    ->assertNotFound();
            }
        } finally {
            @unlink($outside);
        }
    }

    /** Only our extension allow-list is ever offered, whatever the manifest says. */
    public function test_a_manifest_pointing_at_a_non_installer_file_is_refused(): void
    {
        $this->publish('secrets.env.txt', 'DB_PASSWORD=hunter2');
        File::put($this->installers.'/latest.yml', "version: 9.9.9\npath: secrets.env.txt\n");
        DesktopDownloadController::forgetCachedReleases();

        $this->actingAs($this->member())
            ->get(route('desktop-downloads.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('downloads.windows.available', false)
            );
    }

    public function test_a_manifest_naming_a_missing_file_does_not_advertise_a_download(): void
    {
        $this->publishManifest('latest.yml', '0.4.6', 'Staffvora Desktop Setup 0.4.6.exe');

        $this->actingAs($this->member())
            ->get(route('desktop-downloads.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('downloads.windows.available', false)
                ->where('downloads.windows.version', null)
            );
    }

    public function test_mac_prefers_a_dmg_over_a_zipped_app_bundle(): void
    {
        $this->publish('Staffvora Desktop-0.4.6-arm64-mac.zip');
        $this->publish('Staffvora Desktop-0.4.6-arm64.dmg');

        $this->actingAs($this->member())
            ->get(route('desktop-downloads.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('downloads.mac.available', true)
                ->where('downloads.mac.version', '0.4.6')
                ->where('downloads.mac.filename', 'Staffvora Desktop-0.4.6-arm64.dmg')
            );
    }

    public function test_mac_reads_its_own_manifest_not_the_windows_one(): void
    {
        $this->publish('Staffvora Desktop Setup 0.4.6.exe');
        $this->publishManifest('latest.yml', '0.4.6', 'Staffvora Desktop Setup 0.4.6.exe');

        $this->publish('Staffvora Desktop-0.4.5-arm64.dmg');
        $this->publishManifest('latest-mac.yml', '0.4.5', 'Staffvora Desktop-0.4.5-arm64.dmg');

        $this->actingAs($this->member())
            ->get(route('desktop-downloads.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('downloads.windows.filename', 'Staffvora Desktop Setup 0.4.6.exe')
                ->where('downloads.mac.filename', 'Staffvora Desktop-0.4.5-arm64.dmg')
                ->where('downloads.mac.version', '0.4.5')
            );
    }

    /**
     * A cached discovery must never outlive the file it points at, or the page
     * offers a link that 404s for up to a minute after an installer is pulled.
     */
    public function test_a_deleted_installer_stops_being_offered_immediately(): void
    {
        $path = $this->publish('Staffvora Desktop Setup 0.4.6.exe');
        $this->publishManifest('latest.yml', '0.4.6', 'Staffvora Desktop Setup 0.4.6.exe');

        $this->actingAs($this->member())
            ->get(route('desktop-downloads.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('downloads.windows.available', true));

        // Deleted WITHOUT clearing the cache, exactly as a person on the server
        // would do it.
        @unlink($path);

        $this->actingAs($this->member())
            ->get(route('desktop-downloads.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('downloads.windows.available', false)
                ->where('downloads.windows.url', null)
            );

        $this->actingAs($this->member())->get(route('desktop-downloads.windows'))->assertNotFound();
    }

    /** The updater feed is unauthenticated by design; the page is not. */
    public function test_the_download_page_requires_a_signed_in_user(): void
    {
        $this->get(route('desktop-downloads.index'))->assertRedirect(route('login'));
    }

    public function test_the_update_feed_still_blocks_traversal_and_foreign_extensions(): void
    {
        $this->publish('latest.yml', "version: 0.4.6\n");

        $this->get('/desktop-updates/latest.yml')->assertOk();
        $this->get('/desktop-updates/'.rawurlencode('../.env'))->assertNotFound();
        $this->get('/desktop-updates/secrets.txt')->assertNotFound();
    }
}
