<?php

namespace App\Console\Commands;

use App\Http\Middleware\TrustHosts;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * "Is this server actually ready to serve customers?" — one command, plain English.
 *
 * Every check here exists because the thing it checks has silently broken a
 * Laravel deploy on shared/VPS hosting before:
 *
 *  - Migrations not applied. The host's git deploy copies CODE only; nothing
 *    runs `migrate`. The app boots and then 500s on the first query that wants
 *    a new column, which is usually the first customer, not us.
 *  - `storage:link` missing after a re-clone, so every uploaded logo/avatar 404s.
 *  - Queue driver is `database` (our default) with NO worker anywhere. Jobs are
 *    written to the `jobs` table and sit there forever. Nothing errors. The
 *    feature just never happens.
 *  - Cron never installed, so `schedule:run` never fires: no retention pruning,
 *    no stale-session closing, no digests, and the queue fallback drain in
 *    Console\Kernel never runs either.
 *  - APP_DEBUG left on, which prints database credentials on any exception.
 *  - Mail left on the `log` driver, so invitations and password resets are
 *    "sent" into a file and the customer is locked out.
 *  - A user with organization_id = NULL — an unscoped cross-customer account.
 *
 * Exit code is 1 if anything FAILED, so it can gate a deploy script. Warnings
 * do not fail the run unless --strict is passed.
 */
class DeployPreflight extends Command
{
    protected $signature = 'deploy:preflight
        {--strict : Treat warnings as failures too}';

    protected $description = 'Check that this server is really ready to serve customers (plain-English pass/fail report).';

    private const STATUS_OK = 'ok';

    private const STATUS_WARN = 'warn';

    private const STATUS_FAIL = 'blocker';

    /** @var list<array{status:string,title:string,detail:string,fix:string|null}> */
    private array $results = [];

    public function handle(): int
    {
        $env = (string) config('app.env');

        $this->newLine();
        $this->line('  '.config('brand.name', 'Staffvora').' pre-flight check');
        $this->line('  Environment: '.$env.'   ·   URL: '.(config('app.url') ?: 'not set'));
        $this->line('  '.str_repeat('-', 68));
        $this->newLine();

        $this->checkAppKey();
        $this->checkDebug();
        $this->checkEnvironment();
        $this->checkAppUrl();
        $this->checkHostProtection();
        $this->checkDatabase();
        $this->checkMigrations();
        $this->checkStorageLink();
        $this->checkWritablePaths();
        $this->checkQueue();
        $this->checkScheduler();
        $this->checkMail();
        $this->checkTenancy();
        $this->checkSessionCookie();
        $this->checkBranding();
        $this->checkDesktopFeed();

        return $this->report();
    }

    // ── Checks ────────────────────────────────────────────────────────────────

    private function checkAppKey(): void
    {
        $key = (string) config('app.key');

        if ($key === '') {
            $this->blocker(
                'Application key',
                'APP_KEY is empty. Nothing encrypted by the app can be read, and logins will not work.',
                'Run: php artisan key:generate --force   (do this ONCE — regenerating it later logs everyone out and makes existing encrypted values unreadable).'
            );

            return;
        }

        $this->pass('Application key', 'APP_KEY is set.');
    }

    private function checkDebug(): void
    {
        $debug = (bool) config('app.debug');

        if (! $debug) {
            $this->pass('Debug mode', 'Debug mode is off, which is correct for a live site.');

            return;
        }

        if ($this->isProduction()) {
            $this->blocker(
                'Debug mode',
                'Debug mode is ON. Any error page will show your database password and file paths to whoever hit it.',
                'Set APP_DEBUG=false in .env, then run: php artisan config:clear && php artisan config:cache'
            );

            return;
        }

        $this->caution('Debug mode', 'Debug mode is on. Fine here, but it must be off on the live server.', 'Set APP_DEBUG=false in the production .env.');
    }

    private function checkEnvironment(): void
    {
        if ($this->isProduction()) {
            $this->pass('Environment', 'APP_ENV is "production".');

            return;
        }

        $this->caution(
            'Environment',
            'APP_ENV is "'.config('app.env').'", not "production". On the live server this should say production.',
            'Set APP_ENV=production in .env on the server.'
        );
    }

    private function checkAppUrl(): void
    {
        $url = (string) config('app.url');

        if ($url === '') {
            $this->blocker('Site address', 'APP_URL is empty. Emailed links and desktop-app links will be broken.', 'Set APP_URL=https://staffvora.com in .env.');

            return;
        }

        if (! $this->isProduction()) {
            $this->pass('Site address', 'APP_URL is '.$url.'.');

            return;
        }

        if (! str_starts_with($url, 'https://')) {
            $this->blocker(
                'Site address',
                'APP_URL is "'.$url.'" — it is not https. Password-reset and invitation links will point at an insecure address.',
                'Set APP_URL=https://staffvora.com in .env, then: php artisan config:cache'
            );

            return;
        }

        if (str_contains($url, 'localhost') || str_contains($url, '127.0.0.1') || str_contains($url, '.test')) {
            $this->blocker('Site address', 'APP_URL still points at a development address ('.$url.').', 'Set APP_URL=https://staffvora.com in .env.');

            return;
        }

        $this->pass('Site address', 'APP_URL is '.$url.'.');
    }

    /**
     * Is the Host header actually being validated on this box?
     *
     * Two silent ways it is not, both of which end with a customer receiving a
     * genuine password-reset token on a link that points at somebody else:
     *
     *  1. APP_ENV=local. Laravel's TrustHosts::shouldSpecifyTrustedHosts() makes
     *     the whole middleware a no-op outside production-ish environments, and
     *     the repo .env says local. Copy that .env onto the server — which is
     *     exactly how these boxes get set up — and host validation is off while
     *     everything else looks fine.
     *  2. An empty trusted-host list. Symfony reads "no patterns" as "trust
     *     every host on earth", so an APP_URL it cannot parse plus a blank
     *     PLATFORM_HOST used to disable the protection instead of enabling it.
     *     TrustHosts now substitutes a never-matching pattern so that state
     *     fails CLOSED (the site refuses everything) — loud, but the site is
     *     down, so catch it here before the deploy rather than after.
     */
    private function checkHostProtection(): void
    {
        $patterns = (new TrustHosts($this->laravel))->publicHostPatterns();

        if ($patterns === [] || $patterns === [TrustHosts::NEVER_MATCHES]) {
            $this->blocker(
                'Host protection',
                'The trusted host list resolves to NOTHING — APP_URL cannot be parsed and PLATFORM_HOST is blank. The site will refuse every request (it fails closed on purpose), and on older builds it would instead have trusted any Host header, which lets an attacker put their own domain inside a real customer\'s password-reset link.',
                'Set APP_URL to the full site address, e.g. APP_URL=https://staffvora.com (and PLATFORM_HOST if the operator console lives on its own domain), then run: php artisan config:clear && php artisan config:cache'
            );

            return;
        }

        if ($this->isProduction()) {
            $this->pass('Host protection', 'Host header validation is active for '.implode(' and ', $patterns).'.');

            return;
        }

        $message = 'APP_ENV is "'.config('app.env').'", so Laravel switches Host header validation OFF. Anyone can then post to /forgot-password with their own Host header and have a real customer emailed a genuine reset token on a link that points at the attacker.';
        $fix = 'Set APP_ENV=production in the .env ON THE SERVER (the repo .env is a development one), then run: php artisan config:clear && php artisan config:cache';

        if ($this->looksLikeALiveSite()) {
            $this->blocker('Host protection', $message, $fix);

            return;
        }

        $this->caution('Host protection', $message.' Fine on a development machine, never on the live server.', $fix);
    }

    /**
     * Does this box look like it is serving real customers, whatever APP_ENV
     * claims? A public https address that is not a development hostname is the
     * honest signal — it is what customers, and attackers, actually reach.
     */
    private function looksLikeALiveSite(): bool
    {
        $url = strtolower((string) config('app.url'));

        if (! str_starts_with($url, 'https://')) {
            return false;
        }

        foreach (['localhost', '127.0.0.1', '.test', '.local', '.localhost'] as $development) {
            if (str_contains($url, $development)) {
                return false;
            }
        }

        return true;
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $this->pass('Database', 'Connected to the "'.DB::connection()->getName().'" database successfully.');
        } catch (Throwable $e) {
            $this->blocker(
                'Database',
                'Could not connect to the database. The site cannot serve a single page like this.',
                'Check DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env. Error was: '.$this->shorten($e->getMessage())
            );
        }
    }

    private function checkMigrations(): void
    {
        try {
            /** @var \Illuminate\Database\Migrations\Migrator $migrator */
            $migrator = app('migrator');
            $repository = $migrator->getRepository();

            if (! $repository->repositoryExists()) {
                $this->blocker(
                    'Database updates (migrations)',
                    'The migrations table does not exist — the database has never been set up on this server.',
                    'Run: php artisan migrate --force'
                );

                return;
            }

            $ran = $repository->getRan();
            $files = $migrator->getMigrationFiles($migrator->paths() ?: [database_path('migrations')]);
            $pending = array_diff(array_keys($files), $ran);
            $count = count($pending);

            if ($count === 0) {
                $this->pass('Database updates (migrations)', 'All '.count($ran).' database updates have been applied.');

                return;
            }

            $this->blocker(
                'Database updates (migrations)',
                $count.' database update(s) have NOT been applied. The code on this server expects tables or columns that do not exist yet — pages will crash. (The host copies code but never runs migrations, so this step is always manual.)',
                'Run: php artisan migrate --force        Not yet applied: '.implode(', ', array_slice($pending, 0, 5)).($count > 5 ? ' … and '.($count - 5).' more' : '')
            );
        } catch (Throwable $e) {
            $this->caution('Database updates (migrations)', 'Could not read the migration status.', $this->shorten($e->getMessage()));
        }
    }

    private function checkStorageLink(): void
    {
        $link = public_path('storage');

        if (is_link($link) || is_dir($link)) {
            $this->pass('Uploaded files link', 'public/storage exists, so uploaded files are reachable.');

            return;
        }

        $this->blocker(
            'Uploaded files link',
            'public/storage is missing. Uploaded logos, avatars and exports will show as broken images / 404s. (A git re-deploy deletes this link, so it must be recreated after every deploy that re-clones the tree.)',
            'Run: php artisan storage:link'
        );
    }

    private function checkWritablePaths(): void
    {
        $paths = [
            'storage/framework' => storage_path('framework'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $bad = [];

        foreach ($paths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $bad[] = $label;
            }
        }

        if ($bad === []) {
            $this->pass('Folder permissions', 'The folders the app writes to are writable.');

            return;
        }

        $this->blocker(
            'Folder permissions',
            'The app cannot write to: '.implode(', ', $bad).'. Sessions, logs and caches will fail and the site will show a blank error page.',
            'On the server run: chmod -R ug+rw storage bootstrap/cache  (and make sure they are owned by the web-server user).'
        );
    }

    private function checkQueue(): void
    {
        $driver = (string) config('queue.default');

        if ($driver === 'sync') {
            $this->caution(
                'Background jobs (queue)',
                'The queue driver is "sync", so background work runs inside the web request. It works, but screenshot thumbnails and emails will make pages feel slow.',
                'Recommended: set QUEUE_CONNECTION=database in .env and make sure a worker is running (see the deploy runbook).'
            );

            return;
        }

        if ($driver !== 'database') {
            $this->pass('Background jobs (queue)', 'Queue driver is "'.$driver.'". Make sure a worker for it is running.');

            return;
        }

        $table = (string) config('queue.connections.database.table', 'jobs');

        if (! Schema::hasTable($table)) {
            $this->blocker(
                'Background jobs (queue)',
                'The queue driver is "database" but the "'.$table.'" table does not exist. Every queued job will throw.',
                'Run: php artisan migrate --force'
            );

            return;
        }

        try {
            $waiting = DB::table($table)->count();
            $oldest = DB::table($table)->min('available_at');
            $ageMinutes = $oldest ? (int) floor((time() - (int) $oldest) / 60) : 0;

            if ($waiting === 0) {
                $this->pass('Background jobs (queue)', 'The queue is empty — nothing is stuck.');
            } elseif ($ageMinutes >= 15) {
                $this->blocker(
                    'Background jobs (queue)',
                    $waiting.' background job(s) are waiting and the oldest has been waiting '.$ageMinutes.' minutes. That means NOTHING is processing the queue — jobs pile up silently and the work never happens.',
                    'Start a worker (supervisor/systemd running "php artisan queue:work"), OR make sure the cron entry for schedule:run is installed — the schedule has a fallback drain that empties the queue every minute.'
                );
            } else {
                $this->pass('Background jobs (queue)', $waiting.' job(s) waiting, oldest '.$ageMinutes.' minute(s) old — a worker appears to be keeping up.');
            }
        } catch (Throwable $e) {
            $this->caution('Background jobs (queue)', 'Could not read the queue table.', $this->shorten($e->getMessage()));
        }

        $failedTable = 'failed_jobs';

        if (Schema::hasTable($failedTable)) {
            try {
                $failed = DB::table($failedTable)->count();

                if ($failed > 0) {
                    $this->caution(
                        'Failed background jobs',
                        $failed.' background job(s) have failed and are sitting in the failed list.',
                        'Look at them with: php artisan queue:failed   ·  retry with: php artisan queue:retry all'
                    );
                } else {
                    $this->pass('Failed background jobs', 'No failed background jobs.');
                }
            } catch (Throwable) {
                // Not worth failing a deploy over.
            }
        }
    }

    private function checkScheduler(): void
    {
        // Console\Kernel touches this file every minute. Its timestamp is the
        // only honest answer to "is cron actually running?", whether the
        // scheduler is driven by host cron or the HTTP trigger.
        $heartbeat = storage_path('framework/schedule-heartbeat');

        if (! file_exists($heartbeat)) {
            $this->blocker(
                'Scheduled tasks (cron)',
                'The scheduler has never run on this server. Nothing that happens automatically will happen: no retention cleanup, no stale-session closing, no digests, and no queue fallback.',
                'Add this cron entry on the server (one line, replace the path):'."\n".
                '            * * * * * cd /home/USER/staffvora && /usr/bin/php artisan schedule:run >> /dev/null 2>&1'
            );

            return;
        }

        $ageMinutes = (int) floor((time() - (int) filemtime($heartbeat)) / 60);

        if ($ageMinutes <= 5) {
            $this->pass('Scheduled tasks (cron)', 'The scheduler ran '.$ageMinutes.' minute(s) ago — cron is working.');

            return;
        }

        if ($ageMinutes <= 60) {
            $this->caution(
                'Scheduled tasks (cron)',
                'The scheduler last ran '.$ageMinutes.' minutes ago. It should run every minute.',
                'Check the cron entry for "schedule:run" is still installed and that the PHP path in it is correct.'
            );

            return;
        }

        $this->blocker(
            'Scheduled tasks (cron)',
            'The scheduler last ran '.$this->humanAge($ageMinutes).' ago. Automatic work has stopped: retention cleanup, stale-session closing, digests and the queue fallback are all dead.',
            'Re-install the cron entry:'."\n".
            '            * * * * * cd /home/USER/staffvora && /usr/bin/php artisan schedule:run >> /dev/null 2>&1'
        );
    }

    private function checkMail(): void
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        if (in_array($mailer, ['log', 'array', ''], true)) {
            $method = $this->isProduction() ? 'blocker' : 'caution';
            $this->{$method}(
                'Email',
                'Email is set to "'.($mailer ?: 'none').'", which does not actually send anything. Invitations and password resets would go nowhere and new customers would be locked out.',
                'Set MAIL_MAILER=smtp plus MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION and MAIL_FROM_ADDRESS in .env.'
            );

            return;
        }

        if ($from === '' || str_contains($from, 'example.com') || str_contains($from, 'yourdomain')) {
            $this->blocker(
                'Email',
                'The "from" address is '.($from ?: 'empty').' — a placeholder. Mail providers will reject it or it will land in spam.',
                'Set MAIL_FROM_ADDRESS to a real mailbox on the staffvora.com domain in .env.'
            );

            return;
        }

        if ($mailer === 'smtp' && (string) config('mail.mailers.smtp.host') === '') {
            $this->blocker('Email', 'MAIL_MAILER is smtp but MAIL_HOST is empty, so nothing can be sent.', 'Set MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD in .env.');

            return;
        }

        $this->pass('Email', 'Email is configured ("'.$mailer.'", from '.$from.'). Send yourself a test to be certain.');
    }

    private function checkTenancy(): void
    {
        try {
            $orphans = User::withoutOrganizationScope()->whereNull('organization_id')->count();
        } catch (Throwable $e) {
            $this->caution('Customer separation', 'Could not run the tenancy check.', $this->shorten($e->getMessage()));

            return;
        }

        if ($orphans === 0) {
            $this->pass('Customer separation', 'Every user account belongs to a workspace — no account can see another customer\'s data.');

            return;
        }

        $this->blocker(
            'Customer separation',
            $orphans.' user account(s) belong to NO workspace. Such an account can read and write EVERY customer\'s data. This is the most serious problem this check can find.',
            'Run: php artisan tenancy:audit --details   then delete or re-home those accounts before letting anyone use the site.'
        );
    }

    private function checkSessionCookie(): void
    {
        if (! $this->isProduction()) {
            return;
        }

        if (config('session.secure') === true) {
            $this->pass('Login cookie', 'Login cookies are marked https-only.');

            return;
        }

        $this->caution(
            'Login cookie',
            'Login cookies are not marked https-only, so they could be sent over a plain http connection.',
            'Set SESSION_SECURE_COOKIE=true in .env (the site is https-only anyway).'
        );
    }

    private function checkBranding(): void
    {
        $values = [
            'APP_NAME' => (string) config('app.name'),
            'BRAND_NAME' => (string) config('brand.name'),
            'BRAND_LEGAL_NAME' => (string) config('brand.legal_name'),
        ];

        $stale = [];

        foreach ($values as $key => $value) {
            if (stripos($value, 'tracksuite') !== false || stripos($value, 'timetracker') !== false) {
                $stale[] = $key.'="'.$value.'"';
            }
        }

        if ($stale === []) {
            $this->pass('Brand name', 'The app calls itself "'.$values['APP_NAME'].'".');

            return;
        }

        $this->blocker(
            'Brand name',
            'The server still uses the old product name: '.implode(', ', $stale).'. Customers would see it in emails, page titles and invoices.',
            'Set APP_NAME="Staffvora", BRAND_NAME="Staffvora" and BRAND_LEGAL_NAME to the registered company name in .env, then: php artisan config:cache'
        );
    }

    private function checkDesktopFeed(): void
    {
        $dir = config('desktop.installers_path');

        if (! $dir) {
            $this->caution(
                'Desktop app downloads',
                'DESKTOP_INSTALLERS_PATH is not set, so the download page and the desktop auto-update feed have nowhere to read installers from.',
                'Create a folder OUTSIDE the deployed code (a git deploy wipes untracked files inside it), e.g. /home/USER/staffvora-installers, upload the installers there, and set DESKTOP_INSTALLERS_PATH to it in .env.'
            );

            return;
        }

        if (! is_dir($dir)) {
            $this->blocker(
                'Desktop app downloads',
                'DESKTOP_INSTALLERS_PATH points at "'.$dir.'", which does not exist. Downloads and auto-update will 404.',
                'Create that folder on the server and upload the installers into it.'
            );

            return;
        }

        $latest = rtrim((string) $dir, '/\\').DIRECTORY_SEPARATOR.'latest.yml';

        if (! is_file($latest)) {
            $this->caution(
                'Desktop app downloads',
                'The installers folder exists but there is no latest.yml in it, so installed desktop apps will never find an update.',
                'Upload latest.yml (produced next to the .exe by the desktop build) into '.$dir.', or run: php artisan desktop:set-active-version --list'
            );

            return;
        }

        $this->pass('Desktop app downloads', 'Installer folder and update feed file (latest.yml) are present.');
    }

    // ── Result plumbing ───────────────────────────────────────────────────────

    private function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

    private function pass(string $title, string $detail): void
    {
        $this->results[] = ['status' => self::STATUS_OK, 'title' => $title, 'detail' => $detail, 'fix' => null];
        $this->line('  <fg=green>[  OK  ]</>  '.$title.' — '.$detail);
    }

    private function caution(string $title, string $detail, ?string $fix = null): void
    {
        $this->results[] = ['status' => self::STATUS_WARN, 'title' => $title, 'detail' => $detail, 'fix' => $fix];
        $this->line('  <fg=yellow>[ WARN ]</>  '.$title.' — '.$detail);

        if ($fix) {
            $this->line('            → '.$fix);
        }
    }

    private function blocker(string $title, string $detail, ?string $fix = null): void
    {
        $this->results[] = ['status' => self::STATUS_FAIL, 'title' => $title, 'detail' => $detail, 'fix' => $fix];
        $this->line('  <fg=red>[ FAIL ]</>  '.$title.' — '.$detail);

        if ($fix) {
            $this->line('            → '.$fix);
        }
    }

    private function report(): int
    {
        $failed = $this->countOf(self::STATUS_FAIL);
        $warned = $this->countOf(self::STATUS_WARN);
        $passed = $this->countOf(self::STATUS_OK);

        $this->newLine();
        $this->line('  '.str_repeat('-', 68));

        if ($failed === 0 && $warned === 0) {
            $this->line('  <fg=green>ALL CLEAR</> — '.$passed.' checks passed. This server is ready to serve customers.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line('  '.$passed.' passed · '.$warned.' warning(s) · '.$failed.' problem(s) that must be fixed.');

        if ($failed > 0) {
            $this->newLine();
            $this->line('  <fg=red>DO NOT ANNOUNCE THE SITE YET.</> Fix the [ FAIL ] items above and run this again.');
        } elseif ($this->option('strict')) {
            $this->newLine();
            $this->line('  <fg=yellow>Warnings are being treated as failures (--strict).</>');
        } else {
            $this->newLine();
            $this->line('  <fg=yellow>No blockers.</> The warnings above are worth fixing but will not stop customers using the site.');
        }

        $this->newLine();

        if ($failed > 0) {
            return self::FAILURE;
        }

        return ($warned > 0 && $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }

    private function countOf(string $status): int
    {
        return count(array_filter($this->results, fn (array $r) => $r['status'] === $status));
    }

    private function humanAge(int $minutes): string
    {
        if ($minutes < 120) {
            return $minutes.' minutes';
        }

        $hours = intdiv($minutes, 60);

        return $hours < 48 ? $hours.' hours' : intdiv($hours, 24).' days';
    }

    private function shorten(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return strlen($message) > 200 ? substr($message, 0, 200).'…' : $message;
    }
}
