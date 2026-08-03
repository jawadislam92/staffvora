<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `/desktop-updates/{file}` IS ANONYMOUS AND SERVES A ~100 MB FILE.
 *
 * Streaming that through PHP occupies a PHP-FPM worker for the entire download,
 * so a handful of concurrent unauthenticated requests exhausts the pool and the
 * site stops responding — and this VPS also hosts the owner's other live
 * businesses, which go down with it.
 *
 * The two halves of the feed must behave completely differently, and that is
 * what these tests pin:
 *
 *   - latest.yml / *.blockmap: a few KB, polled by every installed client every
 *     four hours. NEVER throttled. If these tests fail, existing installs stop
 *     auto-updating, which is worse than the DoS.
 *   - .exe / .dmg / .zip: rate limited per IP, with a separate and much larger
 *     budget for Range requests so a differential auto-update is never the
 *     thing that trips the limit, and handed to the web server (X-Accel-Redirect)
 *     when it has been configured for it.
 */
class DesktopInstallerDoSDefenceTest extends TestCase
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

    private function publish(string $name, string $contents = 'installer-bytes'): void
    {
        File::put($this->installers.'/'.$name, $contents);
    }

    /**
     * THE AUTO-UPDATER'S LIFELINE. Every installed copy polls this every four
     * hours; a 429 here silently strands the fleet on an old build.
     */
    public function test_the_update_manifest_and_blockmap_are_never_throttled(): void
    {
        $this->publish('latest.yml', "version: 0.4.6\npath: StaffvoraSetup.exe\n");
        $this->publish('StaffvoraSetup.exe.blockmap', 'blockmap-bytes');

        for ($i = 0; $i < 60; $i++) {
            $this->get('/desktop-updates/latest.yml')->assertOk();
            $this->get('/desktop-updates/StaffvoraSetup.exe.blockmap')->assertOk();
        }
    }

    /** The 100 MB half is bounded — one host cannot pin the worker pool. */
    public function test_anonymous_installer_downloads_are_rate_limited(): void
    {
        $this->publish('StaffvoraSetup.exe');

        for ($i = 0; $i < 20; $i++) {
            $this->get('/desktop-updates/StaffvoraSetup.exe')->assertOk();
        }

        $this->get('/desktop-updates/StaffvoraSetup.exe')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    /**
     * A differential update makes many small Range requests that together cost
     * far less than one full download. Counting them against the full-file
     * budget would 429 the updater mid-update.
     */
    public function test_a_ranged_request_keeps_its_own_budget_so_auto_update_survives(): void
    {
        $this->publish('StaffvoraSetup.exe', str_repeat('x', 64));

        // Exhaust the full-file budget entirely.
        for ($i = 0; $i < 20; $i++) {
            $this->get('/desktop-updates/StaffvoraSetup.exe')->assertOk();
        }
        $this->get('/desktop-updates/StaffvoraSetup.exe')->assertStatus(429);

        // The updater's ranged fetches must still go through.
        for ($i = 0; $i < 30; $i++) {
            $response = $this->get('/desktop-updates/StaffvoraSetup.exe', ['Range' => 'bytes=0-7']);

            $this->assertContains(
                $response->getStatusCode(),
                [200, 206],
                'A ranged auto-update fetch was blocked by the full-download rate limit.',
            );
        }
    }

    /** The manifest half stays reachable even once the payload budget is gone. */
    public function test_the_manifest_still_answers_after_the_payload_budget_is_exhausted(): void
    {
        $this->publish('latest.yml', "version: 0.4.6\npath: StaffvoraSetup.exe\n");
        $this->publish('StaffvoraSetup.exe');

        for ($i = 0; $i < 25; $i++) {
            $this->get('/desktop-updates/StaffvoraSetup.exe');
        }

        $this->get('/desktop-updates/latest.yml')->assertOk();
    }

    /**
     * With the web server configured, PHP hands the transfer off and the worker
     * is free immediately — the actual fix for "downloads exhaust the pool".
     */
    public function test_the_payload_is_handed_to_the_web_server_when_configured(): void
    {
        config()->set('desktop.x_accel_prefix', 'protected-installers');
        $this->publish('Staffvora Setup 0.4.6.exe');

        $response = $this->get('/desktop-updates/'.rawurlencode('Staffvora Setup 0.4.6.exe'));

        $response->assertOk()
            ->assertHeader('X-Accel-Redirect', '/protected-installers/Staffvora%20Setup%200.4.6.exe');

        // A plain (non-streamed, empty) response: nginx does the transfer, PHP
        // does not, and the worker is free the moment this returns.
        $this->assertSame('', $response->getContent(), 'PHP is still sending the installer bytes itself.');
    }

    /** Without the nginx side configured we must still serve the file. */
    public function test_the_payload_is_still_served_when_the_web_server_is_not_configured(): void
    {
        config()->set('desktop.x_accel_prefix', null);
        $this->publish('StaffvoraSetup.exe', 'real-installer-bytes');

        $response = $this->get('/desktop-updates/StaffvoraSetup.exe')->assertOk();

        $this->assertFalse($response->headers->has('X-Accel-Redirect'));
        $this->assertSame('real-installer-bytes', $response->streamedContent());
    }

    /** Traversal and non-update files stay refused — unchanged by the fix. */
    public function test_the_file_allow_list_is_unchanged(): void
    {
        $this->publish('secrets.env.txt', 'DB_PASSWORD=hunter2');

        $this->get('/desktop-updates/secrets.env.txt')->assertNotFound();
        $this->get('/desktop-updates/'.rawurlencode('../.env'))->assertNotFound();
    }
}
