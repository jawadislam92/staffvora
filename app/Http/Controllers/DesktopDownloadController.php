<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;

class DesktopDownloadController extends Controller
{
    private const WINDOWS_INSTALLER = 'SA Track Setup 0.3.4.exe';

    private const WINDOWS_VERSION = '0.3.4';

    private const MAC_VERSION = '0.3.2';

    /**
     * Accepted macOS artifact names, preferred first. A DMG is preferred, but
     * a zipped .app bundle is a perfectly valid distribution too (download,
     * unzip, drag to Applications). The SA Track-branded 0.3.2 build is the
     * current one; older names are kept as fallbacks.
     */
    private const MAC_INSTALLERS = [
        'SA Track-0.3.3-arm64.dmg',
        'SA Track-0.3.3-arm64-mac.zip',
        'SA Track-0.3.2-arm64.dmg',
        'SA Track-0.3.2-universal.dmg',
        'SA Track-0.3.2-arm64-mac.zip',
        'Timetracker Desktop-0.2.0-arm64.dmg',
        'Timetracker Desktop-0.1.0-arm64.dmg',
        'Timetracker Desktop-0.1.0-arm64-mac.zip',
        'Timetracker Desktop.app.zip',
    ];

    public function index()
    {
        $windows = $this->installerMeta($this->windowsInstaller());
        $mac = $this->installerMeta($this->macInstaller());

        return Inertia::render('DesktopDownloads', [
            'downloads' => [
                'windows' => [
                    'available' => $windows !== null,
                    'version' => self::WINDOWS_VERSION,
                    'filename' => self::WINDOWS_INSTALLER,
                    'size' => $windows['size'] ?? null,
                    'sha256' => $windows['sha256'] ?? null,
                    'url' => $windows ? route('desktop-downloads.windows') : null,
                ],
                'mac' => [
                    'available' => $mac !== null,
                    'version' => $mac ? ($this->versionFromFilename(basename($mac['path'])) ?? self::MAC_VERSION) : null,
                    'filename' => $mac ? basename($mac['path']) : null,
                    'size' => $mac['size'] ?? null,
                    'sha256' => $mac['sha256'] ?? null,
                    'url' => $mac ? route('desktop-downloads.mac') : null,
                ],
            ],
        ]);
    }

    /**
     * Size + SHA-256 for an installer, cached keyed by path and mtime.
     *
     * Hashing the ~100MB artifacts on every page view is what made this the
     * slowest page in the app; the mtime in the key means a re-uploaded
     * installer invalidates its entry automatically.
     *
     * @return array{path: string, size: int, sha256: string}|null
     */
    private function installerMeta(?string $path): ?array
    {
        if (! $path) {
            return null;
        }

        $mtime = File::lastModified($path);

        return Cache::rememberForever('installer-meta:'.md5($path).':'.$mtime, fn () => [
            'path' => $path,
            'size' => File::size($path),
            'sha256' => hash_file('sha256', $path),
        ]);
    }

    public function windows()
    {
        $path = $this->windowsInstaller();

        abort_unless($path, 404);

        return response()->download($path, self::WINDOWS_INSTALLER, [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * electron-updater feed: latest.yml plus the artifacts it references.
     * Public — the desktop updater has no web session. Single path segment
     * only; the patterns below block traversal and non-update files.
     */
    public function updates(string $file)
    {
        abort_unless(preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $file) === 1, 404);
        abort_unless(preg_match('/\.(yml|exe|blockmap|dmg|zip)$/i', $file) === 1, 404);

        foreach ($this->searchDirs() as $dir) {
            $path = rtrim($dir, '/').'/'.$file;
            if (File::isFile($path)) {
                return response()->file($path, [
                    'Cache-Control' => 'no-cache',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        abort(404);
    }

    public function mac()
    {
        $path = $this->macInstaller();

        abort_unless($path, 404);

        $contentType = str_ends_with(strtolower($path), '.zip')
            ? 'application/zip'
            : 'application/x-apple-diskimage';

        return response()->download($path, basename($path), [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Directories searched for installer artifacts, in priority order.
     *
     * The first is an absolute path set via DESKTOP_INSTALLERS_PATH — used in
     * production because Hostinger's git auto-deploy wipes untracked files
     * inside the deployed tree (including storage/app), so installers must
     * live OUTSIDE the working tree to survive a deploy. The remaining paths
     * are local-dev fallbacks.
     */
    private function searchDirs(): array
    {
        return array_filter([
            config('desktop.installers_path'),
            storage_path('app/desktop-installers'),
            base_path('desktop/dist-app'),
        ]);
    }

    private function versionFromFilename(string $name): ?string
    {
        return preg_match('/(\d+\.\d+\.\d+)/', $name, $m) ? $m[1] : null;
    }

    private function windowsInstaller(): ?string
    {
        return $this->findInstaller([self::WINDOWS_INSTALLER]);
    }

    private function macInstaller(): ?string
    {
        return $this->findInstaller(self::MAC_INSTALLERS);
    }

    /**
     * @param  string[]  $filenames
     */
    private function findInstaller(array $filenames): ?string
    {
        foreach ($this->searchDirs() as $dir) {
            foreach ($filenames as $name) {
                $path = rtrim($dir, '/').'/'.$name;
                if (File::isFile($path)) {
                    return $path;
                }
            }
        }

        return null;
    }
}
