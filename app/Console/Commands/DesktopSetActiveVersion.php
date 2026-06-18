<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Switches which desktop build the auto-updater serves as "current".
 *
 * The update feed is driven by a single latest.yml. Every published version is
 * archived as latest-<version>.yml alongside its installer, so making a version
 * active (or rolling back to a previous good one) is just copying that archived
 * manifest over latest.yml. Combined with autoUpdater.allowDowngrade=true in the
 * desktop app, every client moves to the activated version on its next check —
 * no manual reinstall. Use --list to see what's available and what's active.
 */
class DesktopSetActiveVersion extends Command
{
    protected $signature = 'desktop:set-active-version
        {version? : Version to publish as current, e.g. 0.3.0}
        {--list : List archived versions and the currently active one}';

    protected $description = 'Publish (or roll back to) a desktop build by pointing latest.yml at its archived manifest.';

    public function handle(): int
    {
        $dir = $this->installersDir();
        if (! $dir) {
            $this->error('No installers directory found (DESKTOP_INSTALLERS_PATH / storage/app/desktop-installers / desktop/dist-app).');

            return self::FAILURE;
        }

        $available = $this->archivedVersions($dir);
        $active = $this->activeVersion($dir);

        if ($this->option('list') || ! $this->argument('version')) {
            $this->info("Installers dir: {$dir}");
            $this->line('Active (latest.yml): '.($active ?? 'none'));
            if (empty($available)) {
                $this->warn('No archived latest-<version>.yml manifests yet.');
            } else {
                $this->line('Archived versions:');
                foreach ($available as $v) {
                    $exe = $this->installerExists($dir, $v) ? '' : '  (⚠ installer missing)';
                    $mark = $v === $active ? '  ← active' : '';
                    $this->line("  - {$v}{$mark}{$exe}");
                }
            }

            return self::SUCCESS;
        }

        $version = $this->argument('version');
        $src = $dir.'/latest-'.$version.'.yml';

        if (! File::isFile($src)) {
            $this->error("No archived manifest latest-{$version}.yml in {$dir}.");
            $this->line('Available: '.(empty($available) ? 'none' : implode(', ', $available)));

            return self::FAILURE;
        }

        if (! $this->installerExists($dir, $version)) {
            $this->error("Manifest exists but the installer for {$version} is missing — refusing to point clients at a broken download.");

            return self::FAILURE;
        }

        File::copy($src, $dir.'/latest.yml');
        $this->info("Active desktop version is now {$version} (was {$active}).");
        $this->line('Clients move to it on their next update check (allowDowngrade is enabled, so this works for rollbacks too).');

        return self::SUCCESS;
    }

    private function installersDir(): ?string
    {
        foreach ([config('desktop.installers_path'), storage_path('app/desktop-installers'), base_path('desktop/dist-app')] as $dir) {
            if ($dir && File::isDirectory($dir)) {
                return rtrim($dir, '/');
            }
        }

        return null;
    }

    /** @return string[] sorted versions that have an archived manifest */
    private function archivedVersions(string $dir): array
    {
        $versions = [];
        foreach (File::glob($dir.'/latest-*.yml') as $path) {
            if (preg_match('/latest-(\d+\.\d+\.\d+)\.yml$/', $path, $m)) {
                $versions[] = $m[1];
            }
        }
        usort($versions, 'version_compare');

        return $versions;
    }

    private function activeVersion(string $dir): ?string
    {
        $path = $dir.'/latest.yml';
        if (! File::isFile($path)) {
            return null;
        }

        return preg_match('/^version:\s*(\S+)/m', File::get($path), $m) ? trim($m[1]) : null;
    }

    /** The installer artifact for a version (current "SA Track" name, or older "Timetracker Desktop"). */
    private function installerExists(string $dir, string $version): bool
    {
        foreach (["SA Track Setup {$version}.exe", "Timetracker Desktop Setup {$version}.exe"] as $name) {
            if (File::isFile($dir.'/'.$name)) {
                return true;
            }
        }

        return false;
    }
}
