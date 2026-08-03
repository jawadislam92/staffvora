<?php

namespace Tests\Feature;

use App\Models\Machine;
use Tests\TestCase;

/**
 * The desktop app and the server agree on things that live in two different
 * repositories' worth of files and are only checked at runtime, on a customer's
 * PC, weeks after a release. Each of these has a specific way of going wrong
 * silently:
 *
 *  - The packaged agent's version drifting BELOW Machine::MIN_AGENT_VERSION.
 *    The agent still works, still tracks, still uploads — but the Compliance
 *    dashboard must then treat every machine running it as UNKNOWN, so the
 *    feature the workspace paid for reports nothing and nobody is told why.
 *  - The auto-update feed URL baked into the build pointing somewhere other
 *    than this app's own /desktop-updates route. Updates then never arrive and
 *    the fleet is frozen on whatever it installed, including its bugs.
 *  - The pre-rebrand deep-link scheme being dropped while the web app still
 *    emits it, which turns "open the desktop app" into a dead button.
 */
class DesktopReleaseContractTest extends TestCase
{
    private function packageJson(): array
    {
        $path = base_path('desktop/package.json');

        $this->assertFileExists($path, 'desktop/package.json is missing.');

        $data = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($data, 'desktop/package.json is not valid JSON.');

        return $data;
    }

    public function test_packaged_agent_version_is_at_least_the_servers_minimum(): void
    {
        $version = $this->packageJson()['version'] ?? null;

        $this->assertIsString($version, 'desktop/package.json has no version.');

        $this->assertTrue(
            version_compare($version, Machine::MIN_AGENT_VERSION, '>='),
            "The desktop app is version {$version}, below the server's minimum agent version "
            .Machine::MIN_AGENT_VERSION.'. Machines running it would be reported as stale/unknown in Machine Compliance.'
        );
    }

    public function test_the_update_feed_url_points_at_this_apps_own_route(): void
    {
        $publish = $this->packageJson()['build']['publish'][0] ?? [];

        $this->assertSame('generic', $publish['provider'] ?? null);
        $this->assertSame('https://staffvora.com/desktop-updates', $publish['url'] ?? null);

        // ...and that path must actually resolve here, ahead of the marketing
        // site's /{slug} catch-all.
        $route = app('router')->getRoutes()->getByName('desktop-updates.file');

        $this->assertNotNull($route, 'The desktop-updates route is not registered.');
        $this->assertSame('desktop-updates/{file}', $route->uri());
    }

    public function test_the_app_is_branded_staffvora(): void
    {
        $package = $this->packageJson();

        $this->assertSame('Staffvora Desktop', $package['productName'] ?? null);
        $this->assertSame('com.staffvora.desktop', $package['build']['appId'] ?? null);
    }

    public function test_the_legacy_deep_link_scheme_is_still_registered(): void
    {
        $schemes = $this->packageJson()['build']['protocols'][0]['schemes'] ?? [];

        $this->assertContains('staffvora', $schemes);

        // The web app still emits tracksuite:// (resources/js/Pages/Timeline).
        // Dropping the scheme here without changing that turns the "open the
        // desktop app" control into a no-op.
        $this->assertContains(
            'tracksuite',
            $schemes,
            'The legacy deep-link scheme was removed. Remove the tracksuite:// links from resources/js first.'
        );
    }
}
