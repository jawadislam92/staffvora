<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The human-facing /desktop-downloads page and the raw installer downloads.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY NOTHING HERE NAMES A VERSION
 * ─────────────────────────────────────────────────────────────────────────────
 * This controller used to carry a hardcoded list of installer filenames and a
 * hardcoded version string. That list went stale the moment the desktop app was
 * rebuilt: the auto-updater kept working (it serves whatever filename the client
 * asks for, see updates()), so nobody noticed, while the download page quietly
 * told every human being that the app was "Not available". Bumping the constants
 * only moves that trap to the next release.
 *
 * So the page now reports what is ACTUALLY published, discovered in this order:
 *
 *   1. The updater's own manifest — latest.yml (Windows) / latest-mac.yml (mac).
 *      This is the authoritative "current release" pointer: it is what
 *      `desktop:set-active-version` writes and what installed clients read, so
 *      taking the filename and version from it guarantees the page and the
 *      updater can never disagree about which build is current.
 *   2. Failing that, a scan of the installer directory for the highest-versioned
 *      artifact with an allowed extension — so a build dropped in without its
 *      manifest is still offered rather than hidden.
 *   3. Failing that, nothing: `available => false`, `version => null`. An honest
 *      "not yet available" beats a download button that 404s.
 *
 * Filenames sourced from a manifest are treated as untrusted input and pass the
 * same basename/allow-list checks as the public updates() route.
 */
class DesktopDownloadController extends Controller
{
    /** Extensions we will serve, most-preferred first (a DMG beats a zipped .app). */
    private const WINDOWS_EXTENSIONS = ['exe'];

    private const MAC_EXTENSIONS = ['dmg', 'zip'];

    /**
     * How long a discovery result is cached.
     *
     * Discovery is a handful of stat/glob calls, but this is a page load and the
     * page is hit by every new employee onboarding, so it is not free. Sixty
     * seconds bounds the cost while keeping "I just uploaded the installer"
     * feedback within a minute. Note the key is deliberately NOT tenant-scoped:
     * the installers are one platform-wide set of files on disk, not tenant data,
     * so there is nothing here that could leak across organizations. A cached
     * path is re-stat'ed before use, so a deleted installer can never be handed
     * out as a live download link.
     */
    private const DISCOVERY_TTL = 60;

    private const CACHE_PREFIX = 'desktop-release:v2:';

    /**
     * The two halves of the public update feed, and why they are treated
     * completely differently.
     *
     * MANIFESTS (latest.yml, *.blockmap) are a few kilobytes. Every installed
     * copy of the desktop app polls them every four hours; if they ever start
     * failing, existing installs silently stop updating. They are therefore
     * NEVER throttled and NEVER deferred to the web server — they are cheap
     * enough that serving them from PHP costs nothing.
     *
     * PAYLOADS (.exe, .dmg, .zip) are ~100 MB each. Streaming one through PHP
     * occupies a PHP-FPM worker for the whole download, so a handful of
     * concurrent anonymous requests exhausts the pool and takes the site — and,
     * on this shared VPS, the owner's other live sites — down with it. They are
     * rate limited, and handed to the web server where that is configured.
     *
     * Only the CHEAP half is listed, so the classification fails safe: if the
     * route's extension allow-list ever grows, the new extension is treated as
     * a payload and throttled rather than silently exempted.
     */
    private const MANIFEST_EXTENSIONS = ['yml', 'blockmap'];

    /**
     * Fallback budgets, per client IP per minute, if config/desktop.php is
     * somehow absent. The real numbers live there and are env-tunable — see
     * `desktop.payload_hits_per_minute`.
     *
     * The full-file figure used to be a hardcoded 20, which was too tight for
     * the honest case its own comment named: a NAT'd office rolling the tracker
     * out to thirty people in a morning is thirty full downloads from ONE
     * address, and got 429s.
     *
     * Ranged fetches keep their own, far larger budget: electron-updater's
     * differential download reads the blockmap and then fetches only the changed
     * byte ranges — many small requests that together cost far LESS than one
     * full download. Counting them against the full-file budget would 429 the
     * auto-updater mid-update, which is the thing that must keep working.
     */
    private const PAYLOAD_HITS_PER_MINUTE = 60;

    private const PAYLOAD_RANGE_HITS_PER_MINUTE = 240;

    public function index()
    {
        return Inertia::render('DesktopDownloads', [
            'downloads' => [
                'windows' => $this->payload(self::windowsRelease(), 'desktop-downloads.windows'),
                'mac' => $this->payload(self::macRelease(), 'desktop-downloads.mac'),
            ],
        ]);
    }

    public function windows()
    {
        $release = self::windowsRelease();

        abort_unless($release, 404);

        return self::sendPayload($release['path'], $release['filename'], [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'X-Content-Type-Options' => 'nosniff',
        ], attachment: true);
    }

    public function mac()
    {
        $release = self::macRelease();

        abort_unless($release, 404);

        $contentType = str_ends_with(strtolower($release['filename']), '.zip')
            ? 'application/zip'
            : 'application/x-apple-diskimage';

        return self::sendPayload($release['path'], $release['filename'], [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
        ], attachment: true);
    }

    /**
     * electron-updater feed: latest.yml plus the artifacts it references.
     * Public — the desktop updater has no web session. Single path segment
     * only; the patterns below block traversal and non-update files.
     *
     * The manifest half stays exactly as it was — cheap, unthrottled, always
     * served — because every installed client polls it every four hours and
     * breaking it breaks every existing install. The ~100 MB payload half is
     * rate limited per IP and, where the web server is configured for it,
     * handed off so it never occupies a PHP-FPM worker. See the extension
     * constants above for the full reasoning.
     */
    public function updates(Request $request, string $file)
    {
        abort_unless(preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $file) === 1, 404);
        abort_unless(preg_match('/\.(yml|exe|blockmap|dmg|zip)$/i', $file) === 1, 404);

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $isPayload = ! in_array($extension, self::MANIFEST_EXTENSIONS, true);

        // Resolve the size BEFORE metering: the bucket depends on whether the
        // requested range covers the whole object, and that is unknowable
        // without it. A miss leaves $size null, which meters as a full download
        // (fail-safe) and then 404s below anyway.
        $size = null;

        if ($isPayload) {
            foreach (self::searchDirs() as $dir) {
                $candidate = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$file;

                if (File::isFile($candidate)) {
                    $size = File::size($candidate) ?: null;
                    break;
                }
            }
        }

        if ($isPayload && ($retryAfter = self::payloadRateLimit($request, $size)) !== null) {
            return response('Too many installer downloads from this address.', 429, [
                'Retry-After' => $retryAfter,
                'Cache-Control' => 'no-store',
            ]);
        }

        foreach (self::searchDirs() as $dir) {
            $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$file;

            if (! File::isFile($path)) {
                continue;
            }

            $headers = [
                'Cache-Control' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ];

            // Manifests keep the original inline BinaryFileResponse: a few KB,
            // and the updater's contract with this endpoint has not changed.
            if (! $isPayload) {
                return response()->file($path, $headers);
            }

            return self::sendPayload($path, $file, $headers, attachment: false);
        }

        abort(404);
    }

    /**
     * Per-IP budget for the big artifacts on the public update feed.
     *
     * Returns the number of seconds to wait when the caller is over budget, or
     * null when the request may proceed. Ranged requests are metered separately
     * (see PAYLOAD_RANGE_HITS_PER_MINUTE) so a differential auto-update — many
     * small ranges, less total traffic than one full download — is never the
     * thing that trips the limit.
     *
     * ⚠️ The bucket is chosen by what the range actually COSTS, never by the
     * mere presence of the header. `Range: bytes=0-` is a whole-file download
     * wearing a range hat: it is answered 206 with every byte of a ~100 MB
     * object and it pins a worker for the entire transfer, exactly like a plain
     * GET. Metering it as a "cheap differential fetch" handed one anonymous IP
     * five times the intended budget — a red team proved it by exhausting the
     * full-file bucket and then pulling twenty more complete copies. So a range
     * that covers (or omits an end and therefore reaches) the whole object is
     * charged to the full-download bucket.
     */
    private static function payloadRateLimit(Request $request, ?int $size = null): ?int
    {
        $ranged = $request->headers->has('Range')
            && ! self::rangeCoversWholeFile((string) $request->headers->get('Range'), $size);

        $limit = $ranged
            ? (int) config('desktop.payload_range_hits_per_minute', self::PAYLOAD_RANGE_HITS_PER_MINUTE)
            : (int) config('desktop.payload_hits_per_minute', self::PAYLOAD_HITS_PER_MINUTE);
        $limit = max(1, $limit);
        $key = 'desktop-payload:'.($ranged ? 'range:' : 'full:').sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return max(1, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    /**
     * Does this Range header ask for (effectively) the entire object?
     *
     * Fails SAFE: anything unparseable, multi-range, or suffix-based, and any
     * case where we do not know the object size, is treated as a whole-file
     * request. A cheap fetch mis-charged to the strict bucket costs a legitimate
     * updater nothing at 60/min; a whole-file fetch mis-charged to the generous
     * one is the hole this exists to close.
     */
    private static function rangeCoversWholeFile(string $header, ?int $size): bool
    {
        $spec = trim(str_ireplace('bytes=', '', $header));

        // Multi-range ("0-99,200-299") — cannot reason cheaply; charge it as full.
        if ($spec === '' || str_contains($spec, ',')) {
            return true;
        }

        [$from, $to] = array_pad(explode('-', $spec, 2), 2, '');
        $from = trim($from);
        $to = trim($to);

        // Suffix range ("-500") asks for the last N bytes: genuinely partial
        // whenever we know the object is bigger than N.
        if ($from === '') {
            return $size === null || $to === '' || (int) $to >= $size;
        }

        // Open-ended ("0-" or "1024-") runs to the end of the file. Only a start
        // of zero is the whole object; a later start is a genuine resume.
        if ($to === '') {
            return (int) $from === 0;
        }

        if ($size === null) {
            return true;
        }

        return (int) $from === 0 && (int) $to >= $size - 1;
    }

    /**
     * Serve a ~100 MB installer without holding a PHP-FPM worker open for the
     * whole transfer, when the web server has been told how.
     *
     * With DESKTOP_X_ACCEL_PREFIX set to an nginx `internal` location that maps
     * onto the installers directory, PHP returns an empty 200 carrying an
     * X-Accel-Redirect header and the worker is free immediately; nginx does the
     * transfer (including Range handling) itself. That is the difference between
     * "a few concurrent downloads exhaust the pool" and "downloads cost us
     * nothing but bandwidth".
     *
     * Without it we fall back to the previous behaviour — a BinaryFileResponse,
     * which still supports Range and sets Content-Length, but does occupy a
     * worker. The nginx side is an ops change; see the deploy runbook.
     */
    private static function sendPayload(string $path, string $filename, array $headers, bool $attachment): SymfonyResponse
    {
        $disposition = ($attachment ? 'attachment' : 'inline').'; filename="'.str_replace('"', '', $filename).'"';
        $prefix = trim((string) config('desktop.x_accel_prefix', ''));

        if ($prefix !== '') {
            return response('', 200, $headers + [
                // rawurlencode, not the raw name: filenames legitimately contain
                // spaces ("Staffvora Setup 0.4.5.exe") and this value is a URI
                // path that nginx re-resolves internally.
                'X-Accel-Redirect' => '/'.trim($prefix, '/').'/'.rawurlencode($filename),
                'Content-Disposition' => $disposition,
                'Content-Length' => 0,
            ]);
        }

        return response()->file($path, $headers + ['Content-Disposition' => $disposition]);
    }

    // ── Discovery ────────────────────────────────────────────────────────────

    /**
     * The currently published Windows build, or null if nothing is published.
     *
     * Public and static so anything else that needs to know what is downloadable
     * (the onboarding wizard, for one) can ask instead of keeping its own copy of
     * a filename list that will go stale exactly like this one did.
     *
     * @return array{path: string, filename: string, version: string|null}|null
     */
    public static function windowsRelease(): ?array
    {
        return self::release('windows', 'latest.yml', self::WINDOWS_EXTENSIONS);
    }

    /** @return array{path: string, filename: string, version: string|null}|null */
    public static function macRelease(): ?array
    {
        return self::release('mac', 'latest-mac.yml', self::MAC_EXTENSIONS);
    }

    /** Drop the cached discovery — call after publishing a new installer. */
    public static function forgetCachedReleases(): void
    {
        Cache::forget(self::CACHE_PREFIX.'windows');
        Cache::forget(self::CACHE_PREFIX.'mac');
    }

    /**
     * @param  string[]  $extensions
     * @return array{path: string, filename: string, version: string|null}|null
     */
    private static function release(string $platform, string $manifest, array $extensions): ?array
    {
        $key = self::CACHE_PREFIX.$platform;
        $cached = Cache::get($key);

        // `false` is the cached "nothing is published" answer; an array is a hit,
        // but only while the file it points at still exists.
        if ($cached === false) {
            return null;
        }

        if (is_array($cached) && isset($cached['path']) && File::isFile($cached['path'])) {
            return $cached;
        }

        $found = self::discover($manifest, $extensions);

        Cache::put($key, $found ?? false, self::DISCOVERY_TTL);

        return $found;
    }

    /**
     * @param  string[]  $extensions
     * @return array{path: string, filename: string, version: string|null}|null
     */
    private static function discover(string $manifest, array $extensions): ?array
    {
        foreach (self::searchDirs() as $dir) {
            $dir = rtrim($dir, '/\\');

            if (! File::isDirectory($dir)) {
                continue;
            }

            $found = self::fromManifest($dir, $manifest, $extensions)
                ?? self::fromDirectoryScan($dir, $extensions);

            if ($found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Read the artifact the updater is currently serving out of its own manifest.
     *
     * @param  string[]  $extensions
     * @return array{path: string, filename: string, version: string|null}|null
     */
    private static function fromManifest(string $dir, string $manifest, array $extensions): ?array
    {
        $manifestPath = $dir.DIRECTORY_SEPARATOR.$manifest;

        if (! File::isFile($manifestPath)) {
            return null;
        }

        $yaml = (string) File::get($manifestPath);

        $version = preg_match('/^version:\s*(\S+)/m', $yaml, $m)
            ? trim($m[1], " \t\"'")
            : null;

        // electron-builder writes both a top-level `path:` and one `url:` per
        // file; take every candidate and let the extension preference decide.
        $candidates = [];

        if (preg_match('/^path:\s*(.+)$/m', $yaml, $m)) {
            $candidates[] = $m[1];
        }

        if (preg_match_all('/^[\s\-]*url:\s*(.+)$/m', $yaml, $m)) {
            $candidates = array_merge($candidates, $m[1]);
        }

        foreach ($extensions as $extension) {
            foreach ($candidates as $candidate) {
                $name = self::safeInstallerName($candidate, [$extension]);

                if ($name === null) {
                    continue;
                }

                $path = $dir.DIRECTORY_SEPARATOR.$name;

                if (! File::isFile($path)) {
                    continue;
                }

                return [
                    'path' => $path,
                    'filename' => $name,
                    'version' => $version ?: self::versionFromFilename($name),
                ];
            }
        }

        return null;
    }

    /**
     * No usable manifest — offer the highest-versioned artifact actually sitting
     * in the directory, so a build uploaded without its latest.yml is still
     * downloadable by a human even though the updater cannot see it.
     *
     * @param  string[]  $extensions
     * @return array{path: string, filename: string, version: string|null}|null
     */
    private static function fromDirectoryScan(string $dir, array $extensions): ?array
    {
        $candidates = [];

        foreach (File::files($dir) as $file) {
            $name = self::safeInstallerName($file->getFilename(), $extensions);

            if ($name === null) {
                continue;
            }

            $candidates[] = [
                'path' => $file->getPathname(),
                'filename' => $name,
                'version' => self::versionFromFilename($name),
                'rank' => array_search(strtolower($file->getExtension()), $extensions, true),
                'mtime' => $file->getMTime(),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $byVersion = version_compare($b['version'] ?? '0.0.0', $a['version'] ?? '0.0.0');

            if ($byVersion !== 0) {
                return $byVersion;
            }

            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }

            return $b['mtime'] <=> $a['mtime'];
        });

        $best = $candidates[0];

        return [
            'path' => $best['path'],
            'filename' => $best['filename'],
            'version' => $best['version'],
        ];
    }

    /**
     * Validate a filename that came out of a manifest or a directory listing.
     *
     * A manifest is a file on the server, so this is defence in depth rather
     * than the front line — but "the release manifest said so" must never be a
     * way to read an .env or a key off disk. Single path segment, our character
     * allow-list, our extension allow-list. Nothing else is ever served.
     *
     * @param  string[]  $extensions
     */
    private static function safeInstallerName(string $raw, array $extensions): ?string
    {
        // electron-builder percent-encodes spaces in `url:`.
        $name = trim(rawurldecode(trim($raw)), " \t\r\n\"'");

        if ($name === '' || strlen($name) > 255) {
            return null;
        }

        if (str_contains($name, "\0") || str_contains($name, '..')) {
            return null;
        }

        // Rejects any directory component, on both separator conventions.
        if (str_contains($name, '/') || str_contains($name, '\\') || $name !== basename($name)) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $name) !== 1) {
            return null;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, $extensions, true) ? $name : null;
    }

    /**
     * Directories searched for installer artifacts, in priority order.
     *
     * The first is an absolute path set via DESKTOP_INSTALLERS_PATH — used in
     * production because Hostinger's git auto-deploy wipes untracked files
     * inside the deployed tree (including storage/app), so installers must
     * live OUTSIDE the working tree to survive a deploy. The rest are the
     * local-dev fallbacks from config/desktop.php.
     *
     * @return string[]
     */
    private static function searchDirs(): array
    {
        return array_values(array_filter(array_merge(
            [config('desktop.installers_path')],
            (array) config('desktop.fallback_paths', []),
        )));
    }

    private static function versionFromFilename(string $name): ?string
    {
        return preg_match('/(\d+\.\d+\.\d+)/', $name, $m) ? $m[1] : null;
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * @param  array{path: string, filename: string, version: string|null}|null  $release
     */
    private function payload(?array $release, string $routeName): array
    {
        $meta = $this->installerMeta($release['path'] ?? null);

        return [
            'available' => $meta !== null,
            'version' => $meta ? $release['version'] : null,
            'filename' => $meta ? $release['filename'] : null,
            'size' => $meta['size'] ?? null,
            'sha256' => $meta['sha256'] ?? null,
            'url' => $meta ? route($routeName) : null,
        ];
    }

    /**
     * Size + SHA-256 for an installer, cached keyed by path and mtime.
     *
     * Hashing the ~100MB artifacts on every page view is what made this the
     * slowest page in the app; the mtime in the key means a re-uploaded
     * installer invalidates its entry automatically.
     *
     * @return array{size: int, sha256: string}|null
     */
    private function installerMeta(?string $path): ?array
    {
        if (! $path || ! File::isFile($path)) {
            return null;
        }

        $mtime = File::lastModified($path);

        return Cache::rememberForever('installer-meta:'.md5($path).':'.$mtime, fn () => [
            'size' => File::size($path),
            'sha256' => hash_file('sha256', $path),
        ]);
    }
}
