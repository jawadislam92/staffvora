<?php

namespace App\Console\Commands;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Organization;
use App\Models\User;
use App\Support\BusinessTime;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE BENCHMARK HARNESS — "how expensive is each screen, really".
 *
 * Every surface is exercised as a REAL HTTP request through the real kernel, so
 * the number includes the global middleware, the tenancy binding, the
 * permission/entitlement gates, the controller, AND the Inertia shared props
 * (which are themselves a meaningful slice of the cost on some pages). Anything
 * that only calls a controller method misses most of that.
 *
 * Two numbers per surface, because they fail differently:
 *   QUERIES — the shape problem. An N+1 that is invisible on a 3-person demo is
 *             a 400-query page on a 120-person workspace.
 *   MS      — the volume problem. Six well-indexed queries can still be slow if
 *             one of them scans a year of screenshots.
 *
 * HONESTY NOTES, because a benchmark that flatters itself is worse than none:
 *  - This runs IN-PROCESS. Framework boot (~30–60 ms under PHP-FPM) is NOT
 *    included, so real TTFB is higher than the ms shown here.
 *  - Per-request container memoisation (MonitoringSetting::current(), the
 *    business-timezone cache) is reset between iterations, and the Inertia
 *    shared-settings cache entry is dropped, so a warm in-process run cannot
 *    silently skip work a real request would do.
 *  - `--repeat` reports the MEDIAN of N measured runs after one un-counted
 *    warm-up, and also prints the worst run, so a single lucky pass cannot be
 *    mistaken for a result.
 *  - SQLite is the local dev store. MySQL will have different constants; the
 *    QUERY COUNT is the portable number, the ms figure is directional.
 */
class Benchmark extends Command
{
    protected $signature = 'staffvora:benchmark
        {--org= : Organization id or slug (default: the workspace with the most users)}
        {--user= : Actor user id or email (default: the workspace owner / a super admin)}
        {--repeat=3 : Measured runs per surface (median is reported)}
        {--warmup=1 : Un-counted runs before measuring}
        {--only= : Only run surfaces whose name contains this string}
        {--query-threshold=50 : Flag a surface above this many queries}
        {--ms-threshold=1000 : Flag a surface above this many milliseconds}
        {--slow-queries=0 : Also print the N slowest individual queries per flagged surface}
        {--keep-cache : Do not flush the cache store first (by default local/testing runs flush it, which also resets the rate limiters the throttled endpoints use)}
        {--markdown : Emit a copy-pasteable markdown table instead of a console table}
        {--json : Emit raw JSON}';

    protected $description = 'Measure query count + wall-clock ms for every heavy surface of one workspace, as a repeatable table.';

    public function handle(CurrentOrganization $current): int
    {
        $organization = $this->resolveOrganization();

        if (! $organization) {
            $this->error('No organization found. Seed one first: php artisan staffvora:seed-big-org');

            return self::FAILURE;
        }

        $actor = $this->resolveActor($organization);

        if (! $actor) {
            $this->error("Organization #{$organization->id} has no usable actor (need a user, ideally a super_admin).");

            return self::FAILURE;
        }

        $repeat = max(1, (int) $this->option('repeat'));
        $warmup = max(0, (int) $this->option('warmup'));
        $queryThreshold = (int) $this->option('query-threshold');
        $msThreshold = (float) $this->option('ms-threshold');
        $only = (string) $this->option('only');

        // Bind the tenant for the surface list itself (it needs the org's own
        // "today"/month/ids to build realistic URLs).
        $surfaces = $current->runFor($organization, fn () => $this->surfaces($organization));

        if ($only !== '') {
            $surfaces = array_values(array_filter(
                $surfaces,
                fn (array $s) => str_contains(strtolower($s['name']), strtolower($only))
            ));
        }

        if ($surfaces === []) {
            $this->error('No surfaces matched --only='.$only);

            return self::FAILURE;
        }

        // Several benchmarked endpoints sit behind `throttle:30,1` / `throttle:60,1`,
        // and the file cache store REMEMBERS those counters between runs — so the
        // eighth consecutive benchmark would start reporting 429s that have
        // nothing to do with performance. Flushing once (before the warm-up, so
        // the measured runs still see a warm app cache) resets them.
        if (! $this->option('keep-cache') && app()->environment(['local', 'testing'])) {
            try {
                Cache::flush();
            } catch (\Throwable $e) {
                $this->warn('Could not flush the cache store: '.$e->getMessage());
            }
        }

        $scale = $this->scale($current, $organization);

        if (! $this->option('json')) {
            $this->info(sprintf(
                'Workspace #%d "%s" (%s) — %s users, %s work entries, %s punches, %s sessions, %s screenshots.',
                $organization->id, $organization->name, $organization->slug,
                number_format($scale['users']), number_format($scale['work_hours']),
                number_format($scale['time_entries']), number_format($scale['tracking_sessions']),
                number_format($scale['tracking_screenshots'])
            ));
            $this->line(sprintf(
                'Actor: %s (%s) · driver: %s · repeat: %d (median) · warmup: %d',
                $actor->email, $actor->role, DB::connection()->getDriverName(), $repeat, $warmup
            ));
            $this->newLine();
        }

        $results = [];

        foreach ($surfaces as $surface) {
            for ($i = 0; $i < $warmup; $i++) {
                $this->hit($surface, $actor, $organization);
            }

            $runs = [];
            for ($i = 0; $i < $repeat; $i++) {
                $runs[] = $this->hit($surface, $actor, $organization);
            }

            $ms = array_column($runs, 'ms');
            $queries = array_column($runs, 'queries');
            sort($ms);

            $result = [
                'name' => $surface['name'],
                'method' => $surface['method'] ?? 'GET',
                'uri' => $surface['uri'],
                'status' => $runs[0]['status'],
                'queries' => (int) max($queries),
                'ms' => round($this->median($ms), 1),
                'ms_worst' => round(max($ms), 1),
                'rows' => $runs[0]['rows'],
                'bytes' => $runs[0]['bytes'],
                'slowest' => $runs[0]['slowest'],
            ];

            $result['flags'] = array_values(array_filter([
                $result['status'] >= 400 ? 'HTTP '.$result['status'] : null,
                $result['queries'] > $queryThreshold ? 'QUERIES' : null,
                $result['ms'] > $msThreshold ? 'SLOW' : null,
            ]));

            $results[] = $result;

            if (! $this->option('json') && ! $this->option('markdown')) {
                $this->line(sprintf(
                    '  %-34s %6d q  %8.1f ms  %s',
                    $surface['name'], $result['queries'], $result['ms'],
                    $result['flags'] ? '<fg=red>'.implode(' ', $result['flags']).'</>' : '<fg=green>ok</>'
                ));
            }
        }

        return $this->report($results, $organization, $actor, $scale, $queryThreshold, $msThreshold);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The surfaces
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every heavy read surface, with the query string a real page actually
     * sends. Inertia page visits are hit with the X-Inertia header so the
     * measurement is the JSON prop payload rather than the Blade shell — the
     * same request the SPA makes on every navigation.
     *
     * @return array<int,array{name:string,uri:string,method?:string,inertia?:bool}>
     */
    private function surfaces(Organization $organization): array
    {
        $tz = BusinessTime::tz();
        $today = Carbon::today($tz);
        $month = $today->format('Y-m');
        $todayStr = $today->toDateString();

        $sessionId = DB::table('tracking_sessions')
            ->where('organization_id', $organization->id)
            ->orderByDesc('id')->value('id');

        $userId = DB::table('users')
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('id')->value('id');

        $surfaces = [
            // Dashboard + the two polls it fires immediately on load.
            ['name' => 'Dashboard (page)', 'uri' => '/dashboard', 'inertia' => true],
            ['name' => 'Dashboard: today-summary', 'uri' => '/time-entries/today-summary'],
            ['name' => 'Dashboard: trend', 'uri' => '/time-entries/dashboard-trend'],
            ['name' => 'Dashboard: team-activity', 'uri' => '/time-entries/team-activity?range=today'],

            // Attendance — the monthly grid is the classic 120-users × 31-days page.
            ['name' => 'Attendance (page)', 'uri' => '/employee-attendance', 'inertia' => true],
            ['name' => 'Attendance: monthly grid', 'uri' => '/employee-attendance/monthly?month='.$month],
            ['name' => 'Attendance: day summary', 'uri' => '/employee-attendance/summary?date='.$todayStr],
            ['name' => 'Attendance: day timeline', 'uri' => '/employee-attendance/timeline?date='.$todayStr],

            // Payroll / approvals.
            ['name' => 'Timesheets queue', 'uri' => '/timesheets', 'inertia' => true],

            // Analytics.
            ['name' => 'Insights (30d)', 'uri' => '/insights?range=30d', 'inertia' => true],
            ['name' => 'Insights (90d)', 'uri' => '/insights?range=90d', 'inertia' => true],
            ['name' => 'Reports (work hours)', 'uri' => '/report', 'inertia' => true],

            // Team / timeline.
            ['name' => 'Team (today)', 'uri' => '/team?range=today', 'inertia' => true],
            ['name' => 'Team (7d)', 'uri' => '/team?range=7d', 'inertia' => true],
            ['name' => 'Timeline (page)', 'uri' => '/timeline', 'inertia' => true],
            ['name' => 'Timeline: data', 'uri' => '/timeline/data?date='.$todayStr.($userId ? '&user_id='.$userId : '')],

            // Directories.
            ['name' => 'Users list', 'uri' => '/users', 'inertia' => true],
            ['name' => 'Users list (search)', 'uri' => '/users?search=a&perPage=25', 'inertia' => true],
            ['name' => 'Clients list', 'uri' => '/clients', 'inertia' => true],
            ['name' => 'Teams list', 'uri' => '/teams', 'inertia' => true],

            // Work diary.
            ['name' => 'Work diary list', 'uri' => '/work-hours', 'inertia' => true],
            ['name' => 'Work diary (create form)', 'uri' => '/work-hours/create', 'inertia' => true],

            // Monitoring.
            ['name' => 'Monitoring sessions', 'uri' => '/monitoring/sessions', 'inertia' => true],
        ];

        if ($sessionId) {
            $surfaces[] = ['name' => 'Monitoring session detail', 'uri' => '/monitoring/sessions/'.$sessionId, 'inertia' => true];
        }

        $surfaces[] = ['name' => 'Compliance dashboard', 'uri' => '/compliance', 'inertia' => true];
        $surfaces[] = ['name' => 'Compliance (extensions)', 'uri' => '/compliance?view=extensions', 'inertia' => true];
        $surfaces[] = ['name' => 'Billing hub', 'uri' => '/billing', 'inertia' => true];
        $surfaces[] = ['name' => 'Settings', 'uri' => '/settings', 'inertia' => true];
        $surfaces[] = ['name' => 'Feedback inbox', 'uri' => '/feedback', 'inertia' => true];
        $surfaces[] = ['name' => 'My schedule', 'uri' => '/my-schedule', 'inertia' => true];

        return $surfaces;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // One measured request
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $surface
     * @return array{ms:float,queries:int,status:int,bytes:int,rows:?int,slowest:array}
     */
    private function hit(array $surface, User $actor, Organization $organization): array
    {
        /** @var HttpKernel $kernel */
        $kernel = app(HttpKernel::class);

        // Reset everything a fresh PHP-FPM worker would not have inherited, so
        // an in-process repeat cannot skip work a real request pays for.
        $this->resetPerRequestState($organization);

        // Authenticate the way a feature test does: set the user on the guard
        // directly. The session guard short-circuits on a set user, so the whole
        // web middleware stack (SetCurrentOrganization, EnsureTenantBound,
        // EnsureOrganizationIsUsable, permission + entitlement gates) runs for
        // real without needing a login round-trip.
        Auth::shouldUse('web');
        Auth::guard('web')->setUser($actor);

        $request = Request::create($surface['uri'], $surface['method'] ?? 'GET');
        $request->headers->set('Accept', 'text/html, application/xhtml+xml');
        $request->headers->set('User-Agent', 'staffvora-benchmark');

        if ($surface['inertia'] ?? false) {
            $request->headers->set('X-Inertia', 'true');
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
            $request->headers->set('Accept', 'text/html, application/xhtml+xml');
            $request->headers->set('X-Inertia-Version', $this->inertiaVersion($request));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $startedAt = hrtime(true);

        try {
            /** @var Response $response */
            $response = $kernel->handle($request);
            $status = $response->getStatusCode();
            $content = $response->getContent();
        } catch (\Throwable $e) {
            $status = 500;
            $content = get_class($e).': '.$e->getMessage();
            $response = null;
        }

        $ms = (hrtime(true) - $startedAt) / 1e6;

        $log = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        if ($response) {
            // Outside the clock: terminate() is real work, but it happens after
            // the bytes are on the wire, so it is not what the user waits for.
            try {
                $kernel->terminate($request, $response);
            } catch (\Throwable $e) {
                // A terminating callback failing must not void the measurement.
            }
        }

        usort($log, fn ($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));

        return [
            'ms' => $ms,
            'queries' => count($log),
            'status' => $status,
            'bytes' => strlen((string) $content),
            'rows' => $this->countedRows($content),
            'slowest' => array_map(
                fn ($q) => ['ms' => $q['time'] ?? 0, 'sql' => $this->shorten((string) $q['query'])],
                array_slice($log, 0, 5)
            ),
        ];
    }

    /**
     * Everything a brand-new request/worker would start clean. Without this the
     * second iteration reads memoised settings and a warm shared-props cache and
     * reports a cost no real first-visit user ever sees.
     */
    private function resetPerRequestState(Organization $organization): void
    {
        BusinessTime::flushTzCache();

        foreach ([$organization->id, 0] as $key) {
            app()->forgetInstance('monitoring_settings.current.'.$key);
            Cache::forget('monitoring_settings.shared.'.$key);
            Cache::forget('branding.shared.'.$key);
        }
    }

    private function inertiaVersion(Request $request): string
    {
        static $version = null;

        if ($version === null) {
            try {
                $version = (string) (app(HandleInertiaRequests::class)->version($request) ?? '');
            } catch (\Throwable $e) {
                $version = '';
            }
        }

        return $version;
    }

    /** Rough payload size signal: how many objects the page shipped, if countable. */
    private function countedRows(?string $content): ?int
    {
        if (! $content || $content === '' || $content[0] !== '{') {
            return null;
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return null;
        }

        $props = $decoded['props'] ?? $decoded;
        $best = null;

        foreach ($props as $value) {
            if (is_array($value)) {
                $count = array_is_list($value) ? count($value) : count($value['data'] ?? []);
                $best = max($best ?? 0, $count);
            }
        }

        return $best;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reporting
    // ─────────────────────────────────────────────────────────────────────────

    private function report(array $results, Organization $organization, User $actor, array $scale, int $queryThreshold, float $msThreshold): int
    {
        $totalQueries = array_sum(array_column($results, 'queries'));
        $totalMs = array_sum(array_column($results, 'ms'));
        $flagged = array_values(array_filter($results, fn ($r) => $r['flags'] !== []));

        if ($this->option('json')) {
            $this->line(json_encode([
                'organization' => ['id' => $organization->id, 'slug' => $organization->slug, 'name' => $organization->name],
                'actor' => $actor->email,
                'scale' => $scale,
                'thresholds' => ['queries' => $queryThreshold, 'ms' => $msThreshold],
                'totals' => ['queries' => $totalQueries, 'ms' => round($totalMs, 1)],
                'surfaces' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $flagged === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();

        if ($this->option('markdown')) {
            $this->line('| Surface | URI | Status | Queries | ms (median) | ms (worst) | Flags |');
            $this->line('|---|---|---:|---:|---:|---:|---|');
            foreach ($results as $r) {
                $this->line(sprintf(
                    '| %s | `%s` | %d | %d | %.1f | %.1f | %s |',
                    $r['name'], $r['uri'], $r['status'], $r['queries'], $r['ms'], $r['ms_worst'],
                    $r['flags'] ? implode(', ', $r['flags']) : ''
                ));
            }
            $this->line(sprintf('| **TOTAL (%d surfaces)** | | | **%d** | **%.1f** | | |', count($results), $totalQueries, $totalMs));
        } else {
            $this->table(
                ['Surface', 'Status', 'Queries', 'ms (median)', 'ms (worst)', 'Payload', 'Flags'],
                array_map(fn ($r) => [
                    $r['name'],
                    $r['status'],
                    $r['queries'],
                    number_format($r['ms'], 1),
                    number_format($r['ms_worst'], 1),
                    $this->humanBytes($r['bytes']).($r['rows'] !== null ? ' / '.$r['rows'].' rows' : ''),
                    $r['flags'] ? implode(' ', $r['flags']) : '',
                ], $results)
                + [count($results) => ['TOTAL ('.count($results).' surfaces)', '', $totalQueries, number_format($totalMs, 1), '', '', '']]
            );
        }

        $this->newLine();
        $this->line(sprintf(
            'Thresholds: >%d queries or >%dms. <options=bold>%d of %d surfaces flagged.</>',
            $queryThreshold, (int) $msThreshold, count($flagged), count($results)
        ));

        // Even when nothing trips a threshold, the NEXT agent needs to know where
        // the cost actually sits — a ranking is the whole point of a baseline.
        $bySlow = $results;
        usort($bySlow, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $byQueries = $results;
        usort($byQueries, fn ($a, $b) => $b['queries'] <=> $a['queries']);
        $byBytes = $results;
        usort($byBytes, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

        $this->newLine();
        $this->line('<options=bold>Worst 5 by wall clock</>   '.implode('  ·  ', array_map(
            fn ($r) => $r['name'].' '.number_format($r['ms'], 1).'ms', array_slice($bySlow, 0, 5))));
        $this->line('<options=bold>Worst 5 by query count</> '.implode('  ·  ', array_map(
            fn ($r) => $r['name'].' '.$r['queries'].'q', array_slice($byQueries, 0, 5))));
        $this->line('<options=bold>Worst 5 by payload</>     '.implode('  ·  ', array_map(
            fn ($r) => $r['name'].' '.$this->humanBytes($r['bytes']), array_slice($byBytes, 0, 5))));

        if ($flagged !== [] && (int) $this->option('slow-queries') > 0) {
            $this->newLine();
            $this->line('<options=bold>Slowest statements on flagged surfaces</>');
            foreach ($flagged as $r) {
                $this->line('  <comment>'.$r['name'].'</comment>');
                foreach (array_slice($r['slowest'], 0, (int) $this->option('slow-queries')) as $q) {
                    $this->line(sprintf('    %7.2f ms  %s', $q['ms'], $q['sql']));
                }
            }
        }

        // A flagged surface is a FAILURE exit code on purpose: this command is
        // meant to be usable as a regression gate, not just a pretty table.
        return $flagged === [] ? self::SUCCESS : self::FAILURE;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Resolution helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveOrganization(): ?Organization
    {
        $raw = (string) $this->option('org');

        if ($raw !== '') {
            return Organization::query()
                ->when(ctype_digit($raw), fn ($q) => $q->whereKey((int) $raw), fn ($q) => $q->where('slug', $raw))
                ->first();
        }

        $id = DB::table('users')
            ->selectRaw('organization_id, count(*) as c')
            ->whereNotNull('organization_id')
            ->groupBy('organization_id')
            ->orderByDesc('c')
            ->value('organization_id');

        return $id ? Organization::find($id) : Organization::query()->orderBy('id')->first();
    }

    private function resolveActor(Organization $organization): ?User
    {
        $raw = (string) $this->option('user');

        $query = User::withoutOrganizationScope()->where('organization_id', $organization->id);

        if ($raw !== '') {
            return (clone $query)
                ->when(ctype_digit($raw), fn ($q) => $q->whereKey((int) $raw), fn ($q) => $q->where('email', $raw))
                ->first();
        }

        return (clone $query)->where('id', $organization->owner_user_id)->first()
            ?? (clone $query)->where('role', 'super_admin')->orderBy('id')->first()
            ?? (clone $query)->orderBy('id')->first();
    }

    /** @return array<string,int> */
    private function scale(CurrentOrganization $current, Organization $organization): array
    {
        $out = [];

        foreach (['users', 'work_hours', 'time_entries', 'tracking_sessions', 'tracking_screenshots',
            'tracking_activity_samples', 'machine_inventory_items', 'timesheets', 'clients'] as $table) {
            try {
                $out[$table] = (int) DB::table($table)->where('organization_id', $organization->id)->count();
            } catch (\Throwable $e) {
                $out[$table] = 0;
            }
        }

        return $out;
    }

    private function median(array $values): float
    {
        $n = count($values);

        if ($n === 0) {
            return 0.0;
        }

        sort($values);

        return $n % 2 === 1
            ? (float) $values[intdiv($n, 2)]
            : ((float) $values[intdiv($n, 2) - 1] + (float) $values[intdiv($n, 2)]) / 2;
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1).' MB'
            : ($bytes >= 1024 ? round($bytes / 1024).' KB' : $bytes.' B');
    }

    private function shorten(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return strlen($sql) > 160 ? substr($sql, 0, 157).'…' : $sql;
    }
}
