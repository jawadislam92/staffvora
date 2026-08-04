<?php

namespace Tests\Feature;

use App\Console\Commands\Benchmark;
use App\Console\Commands\Concerns\RequiresBoundTenant;
use App\Console\Commands\CreateAdminUser;
use App\Console\Commands\CreateTenant;
use App\Console\Commands\DeleteTenant;
use App\Console\Commands\DemoData;
use App\Console\Commands\DeployPreflight;
use App\Console\Commands\DesktopSetActiveVersion;
use App\Console\Commands\GrantPlatformAdmin;
use App\Console\Commands\PlatformOwner;
use App\Console\Commands\PlatformPrune;
use App\Console\Commands\RecordPayment;
use App\Console\Commands\SeedBigOrg;
use App\Console\Commands\SyncSiteContent;
use App\Console\Commands\TenancyAudit;
use App\Console\Commands\TenantImport;
use App\Console\Commands\TenantRun;
use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\SlackBotService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * CLOSURE of the five remaining "operator's Slack receives tenant data" doors,
 * plus the collateral the previous round left behind.
 *
 * Each test below states the PROPERTY, not the payload:
 *
 *  1. A per-tenant console command cannot execute with no organization bound —
 *     and the requirement is declared on the command itself, so the next command
 *     someone writes has to be classified (see the guard test at the bottom).
 *  2. runUnscoped() suspends QUERY SCOPING only. It never turns a bound tenant's
 *     work into platform work, so it can never reach the operator's bot token.
 *  3. A deferred (terminating) announcement runs under the tenant that OWNS the
 *     row, not under whatever happens to be bound when the process winds down.
 *  4. No code path creates or reads an ownerless `organization_id = NULL`
 *     settings row.
 *  5. Failing closed is VISIBLE: the drop is logged (throttled), and tenant:run
 *     stops reporting success for a tenant whose command failed.
 */
class SlackOperatorLeakClosureTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM = 'xoxb-PLATFORM-OPERATOR';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.slack_bot.token' => self::PLATFORM,
            'services.attendance.clockin_channel' => '#operator-attendance',
            'services.attendance.lockout_channel' => '#operator-lockouts',
            'services.attendance.tracker_health_channel' => '#operator-health',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'U1']]),
            'slack.com/api/conversations.open*' => Http::response(['ok' => true, 'channel' => ['id' => 'D1']]),
            'slack.com/api/*' => Http::response(['ok' => true, 'ts' => '1.1']),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    /** Slack calls made with a given bot token. */
    private function callsWithToken(string $token): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'slack.com')
                && str_contains($pair[0]->header('Authorization')[0] ?? '', $token))
            ->map(fn ($pair) => $pair[0]->url())
            ->values()
            ->all();
    }

    private function slackCallCount(): int
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'slack.com'))
            ->count();
    }

    /** A workspace with one employee, optionally with its own Slack connected. */
    private function tenant(?string $token, string $channel = '#tenant-attendance'): array
    {
        $org = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);

        $user = app(CurrentOrganization::class)->runFor($org, function () use ($token, $channel) {
            MonitoringSetting::current()->update([
                'slack_bot_token' => $token,
                'slack_attendance_channel' => $channel,
                'slack_clockin_enabled' => true,
                'slack_clockout_enabled' => true,
            ]);

            return User::factory()->create([
                'is_active' => true,
                'email' => 'employee-'.uniqid().'@tenant.test',
            ]);
        });

        return [$org, $user];
    }

    private function punch(Organization $org, User $user, string $type = 'clock_in', int $hoursAgo = 0): TimeEntry
    {
        return app(CurrentOrganization::class)->runFor($org, function () use ($user, $type, $hoursAgo) {
            $at = now()->subHours($hoursAgo);

            return TimeEntry::create([
                'user_id' => $user->id,
                'action_type' => $type,
                'action_timestamp' => $at,
                'action_date' => $at->toDateString(),
                'action_time' => $at->format('H:i:s'),
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // (1) Unbound per-tenant commands
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The command must refuse BEFORE it touches anything: no cross-tenant sweep,
     * no writes, no Slack — and a non-zero exit so a scheduler/operator notices.
     */
    public function test_per_tenant_slack_command_refuses_to_run_with_no_tenant_bound(): void
    {
        [$orgA, $userA] = $this->tenant(null);
        [$orgB, $userB] = $this->tenant(null);
        $this->punch($orgA, $userA, 'clock_in', 20);
        $this->punch($orgB, $userB, 'clock_in', 20);

        app(CurrentOrganization::class)->forget();
        $this->assertFalse(app(CurrentOrganization::class)->check());

        $exit = Artisan::call('attendance:still-working-check', ['--force' => true]);

        $this->assertSame(1, $exit, 'an unbound per-tenant command must exit non-zero, not quietly succeed');
        $this->assertSame(0, $this->slackCallCount(), 'nothing may leave the box from an unbound per-tenant command');
        $this->assertSame(
            0,
            DB::table('attendance_clock_checks')->count(),
            'the command must refuse BEFORE it walks (and writes against) every tenant'
        );
    }

    /** The same refusal for the tracker-health watchdog. */
    public function test_silent_tracker_check_refuses_to_run_with_no_tenant_bound(): void
    {
        $this->tenant(null);
        app(CurrentOrganization::class)->forget();

        $this->assertSame(1, Artisan::call('monitoring:silent-tracker-check'));
        $this->assertSame(0, $this->slackCallCount());
    }

    /** …and for the two scheduled report commands. */
    public function test_digest_and_weekly_report_refuse_to_run_with_no_tenant_bound(): void
    {
        $this->tenant('xoxb-TENANT-OWN');
        app(CurrentOrganization::class)->forget();

        $this->assertSame(1, Artisan::call('activity:digest-slack'));
        $this->assertSame(1, Artisan::call('reports:send-weekly-slack'));
        $this->assertSame(0, $this->slackCallCount());
    }

    /** NO OVER-BLOCKING: the same command run properly under tenant:run works. */
    public function test_the_same_command_runs_normally_under_tenant_run(): void
    {
        [$org, $user] = $this->tenant('xoxb-TENANT-OWN');
        $this->punch($org, $user, 'clock_in', 20);

        app(CurrentOrganization::class)->forget();

        $exit = Artisan::call('tenant:run', [
            'target' => 'attendance:still-working-check',
            '--only' => (string) $org->id,
        ]);

        $this->assertSame(0, $exit, 'tenant:run binds the tenant, so the command must run');
        $this->assertSame([], $this->callsWithToken(self::PLATFORM), 'and never on the operator token');
        $this->assertNotEmpty(
            $this->callsWithToken('xoxb-TENANT-OWN'),
            'REGRESSION: the nudge no longer reaches a workspace that HAS connected Slack'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // (2) runUnscoped()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * runUnscoped() suspends query scoping. It must NOT make a bound tenant look
     * unbound to anything resolving a credential.
     */
    public function test_run_unscoped_keeps_the_tenant_bound_for_credential_decisions(): void
    {
        [$org] = $this->tenant(null);
        $current = app(CurrentOrganization::class);

        $current->runFor($org, function () use ($current) {
            $current->runUnscoped(function () use ($current) {
                $this->assertTrue($current->check(), 'a tenant is still bound inside runUnscoped()');
                $this->assertFalse($current->scoped(), 'but query scoping is off');
                $this->assertNull($current->id(), 'so the scoping id is null');
                $this->assertNotNull($current->boundId(), 'while the bound id survives');

                $this->assertFalse(
                    app(SlackBotService::class)->configured(),
                    'a workspace with no Slack must stay unconfigured inside runUnscoped(), not inherit the platform token'
                );
            });
        });

        $this->assertSame([], $this->callsWithToken(self::PLATFORM));
    }

    /** NO OVER-BLOCKING: a connected workspace still resolves ITS OWN token there. */
    public function test_run_unscoped_still_resolves_the_bound_tenants_own_token(): void
    {
        [$org] = $this->tenant('xoxb-TENANT-OWN');
        $current = app(CurrentOrganization::class);

        $current->runFor($org, fn () => $current->runUnscoped(function () {
            $this->assertTrue(app(SlackBotService::class)->configured());
            app(SlackBotService::class)->postToChannel('#team', 'hello');
        }));

        $this->assertNotEmpty($this->callsWithToken('xoxb-TENANT-OWN'));
        $this->assertSame([], $this->callsWithToken(self::PLATFORM));
    }

    // ─────────────────────────────────────────────────────────────────────
    // (3) The deferred (terminating) attendance announcement
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Two workspaces punch in during the same process. When the deferred
     * callbacks finally run — long after runFor() restored the tenant to null —
     * each announcement must go out on ITS OWN workspace's token and channel.
     */
    public function test_deferred_announcement_rebinds_the_tenant_that_owns_the_entry(): void
    {
        [$orgA, $userA] = $this->tenant('xoxb-TENANT-A', '#a-attendance');
        [$orgB, $userB] = $this->tenant('xoxb-TENANT-B', '#b-attendance');

        $this->punch($orgA, $userA);
        $this->punch($orgB, $userB);

        app(CurrentOrganization::class)->forget();
        $this->assertFalse(app(CurrentOrganization::class)->check());

        $this->app->terminate();

        $this->assertSame([], $this->callsWithToken(self::PLATFORM), 'never the operator token');
        $this->assertNotEmpty($this->callsWithToken('xoxb-TENANT-A'), 'workspace A must get its own announcement');
        $this->assertNotEmpty($this->callsWithToken('xoxb-TENANT-B'), 'workspace B must get its own announcement');

        // And each message went to that workspace's own channel — a re-bind that
        // picked the wrong tenant would still "work" without this assertion.
        $byChannel = collect(Http::recorded())
            ->filter(fn ($p) => str_contains($p[0]->url(), 'chat.postMessage'))
            ->map(fn ($p) => [
                'auth' => $p[0]->header('Authorization')[0] ?? '',
                'channel' => $p[0]->data()['channel'] ?? null,
            ]);

        foreach ($byChannel as $call) {
            $expected = str_contains($call['auth'], 'xoxb-TENANT-A') ? '#a-attendance' : '#b-attendance';
            $this->assertSame($expected, $call['channel'], 'a workspace token posted into another workspace channel');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // (4) The ownerless organization_id = NULL settings row
    // ─────────────────────────────────────────────────────────────────────

    public function test_unbound_settings_lookup_creates_no_row_and_reads_no_legacy_row(): void
    {
        Organization::factory()->create();

        // The legacy landlord row every install carries, loaded with the exact
        // values that made it dangerous.
        DB::table('monitoring_settings')->whereNull('organization_id')->update([
            'slack_attendance_channel' => '#operator-attendance',
            'slack_clockin_enabled' => true,
        ]);

        $before = DB::table('monitoring_settings')->count();

        $settings = MonitoringSetting::current(); // unbound

        $this->assertSame($before, DB::table('monitoring_settings')->count(), 'nothing may be created unbound');
        $this->assertFalse($settings->exists, 'an unbound lookup must return a throw-away instance, not a persisted row');
        $this->assertNull(
            $settings->slack_attendance_channel,
            'HOLE: the unbound lookup read the ownerless legacy row, which is how the operator channel got used'
        );
        $this->assertFalse((bool) $settings->slack_clockin_enabled);

        // A write against it is a no-op rather than a mutation of a row nobody owns.
        $settings->update(['slack_clockin_enabled' => true]);
        $this->assertSame(
            1,
            DB::table('monitoring_settings')->whereNull('organization_id')->count(),
            'an unbound write must not multiply ownerless rows'
        );
    }

    /** NO OVER-BLOCKING: a bound workspace still gets a real, persisted row. */
    public function test_a_bound_workspace_still_gets_a_real_settings_row(): void
    {
        $org = Organization::factory()->create();

        $settings = app(CurrentOrganization::class)->runFor($org, fn () => MonitoringSetting::current());

        $this->assertTrue($settings->exists);
        $this->assertSame($org->id, $settings->organization_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // (5) Making the silence visible
    // ─────────────────────────────────────────────────────────────────────

    /** An unbound send is refused AND logged, so "forgot tenant:run" is findable. */
    public function test_an_unbound_slack_send_is_refused_and_logged(): void
    {
        Cache::flush();

        $logged = [];
        Log::listen(function ($e) use (&$logged) {
            $logged[] = $e->message;
        });

        app(CurrentOrganization::class)->forget();
        $this->assertFalse(app(SlackBotService::class)->configured());

        $this->assertSame(0, $this->slackCallCount());
        $this->assertTrue(
            collect($logged)->contains(fn ($m) => str_contains($m, 'NO organization bound')),
            'an unbound Slack send must say so in the log. Got: '.json_encode($logged)
        );
    }

    /** …but ONE line per hour, not one per person per pass across 500 tenants. */
    public function test_the_fail_closed_warning_is_throttled(): void
    {
        Cache::flush();
        [$org] = $this->tenant(null);

        $logged = [];
        Log::listen(function ($e) use (&$logged) {
            if (str_contains($e->message, 'Slack is not connected for this workspace')) {
                $logged[] = $e->message;
            }
        });

        app(CurrentOrganization::class)->runFor($org, function () {
            $slack = app(SlackBotService::class);
            for ($i = 0; $i < 25; $i++) {
                $slack->postToChannel('#attendance', 'drop '.$i);
            }
        });

        $this->assertCount(1, $logged, 'the fail-closed warning must be throttled, not emitted per message');
    }

    /** Each workspace gets its own warning — one quiet tenant must not mask another. */
    public function test_the_fail_closed_warning_is_per_workspace(): void
    {
        Cache::flush();
        [$orgA] = $this->tenant(null);
        [$orgB] = $this->tenant(null);

        $logged = [];
        Log::listen(function ($e) use (&$logged) {
            if (str_contains($e->message, 'Slack is not connected for this workspace')) {
                $logged[] = $e->context['organization_id'] ?? null;
            }
        });

        $current = app(CurrentOrganization::class);
        $current->runFor($orgA, fn () => app(SlackBotService::class)->postToChannel('#x', 'a'));
        $current->runFor($orgB, fn () => app(SlackBotService::class)->postToChannel('#x', 'b'));

        $this->assertEqualsCanonicalizing([$orgA->id, $orgB->id], $logged);
    }

    /**
     * tenant:run must NOT report "0 failure(s)" for a workspace whose command
     * aborted. That summary is the only thing an operator watches; a digest that
     * has silently failed every night for months has to show up in it.
     */
    public function test_tenant_run_counts_a_failing_sub_command_as_a_failure(): void
    {
        // A workspace with Slack NOT connected: the digest legitimately aborts.
        [$org] = $this->tenant(null);

        app(CurrentOrganization::class)->forget();

        // "1 failure(s)" is the operator-visible half; a non-zero exit is the
        // scheduler-visible half. Both used to be wrong.
        $this->artisan('tenant:run', [
            'target' => 'activity:digest-slack',
            '--only' => (string) $org->id,
        ])
            ->expectsOutputToContain('1 failure(s)')
            ->assertExitCode(1);
    }

    /** NO OVER-BLOCKING: a tenant whose command succeeds is still a success. */
    public function test_tenant_run_still_reports_success_for_a_healthy_tenant(): void
    {
        [$org] = $this->tenant('xoxb-TENANT-OWN');

        app(CurrentOrganization::class)->forget();

        $this->artisan('tenant:run', [
            'target' => 'activity:digest-slack',
            '--only' => (string) $org->id,
        ])
            ->expectsOutputToContain('0 failure(s)')
            ->assertExitCode(0);
    }

    // ─────────────────────────────────────────────────────────────────────
    // THE GUARD — so the NEXT command cannot re-open door (1) by omission
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Every console command must be CLASSIFIED: either it declares
     * `use RequiresBoundTenant;` (per-tenant — refuses to run unbound), or it is
     * listed here as deliberately platform-level.
     *
     * This is the mechanism that "cannot be forgotten": a new command is neither,
     * so the suite fails and its author has to make the choice explicitly instead
     * of inheriting the unsafe default (a scope that silently no-ops into every
     * tenant at once).
     */
    public function test_every_console_command_is_classified_as_tenant_bound_or_platform_level(): void
    {
        /**
         * Deliberately PLATFORM-LEVEL: these operate on the platform itself, or
         * bind their own tenant from an argument. Adding a name here is a
         * conscious statement that the command is safe with no tenant bound.
         */
        $platformLevel = [
            Benchmark::class,          // --org: binds the workspace itself
            CreateAdminUser::class,    // --org: binds the workspace itself
            CreateTenant::class,       // creates the tenant
            DeleteTenant::class,       // landlord erasure, argument-scoped
            DemoData::class,           // --tenant: binds the workspace itself
            DeployPreflight::class,    // server readiness, no tenant data
            DesktopSetActiveVersion::class, // release metadata
            GrantPlatformAdmin::class, // landlord access control
            PlatformOwner::class,      // landlord access control
            PlatformPrune::class,      // platform-wide tables only
            RecordPayment::class,      // billing, argument-scoped
            SeedBigOrg::class,         // local seeding
            SyncSiteContent::class,    // marketing site + plan catalogue
            TenancyAudit::class,       // must cross tenants to audit them
            TenantImport::class,       // argument-scoped
            TenantRun::class,          // it IS the binder
        ];

        $unclassified = [];

        foreach (glob(app_path('Console/Commands/*.php')) as $file) {
            $class = 'App\\Console\\Commands\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $tenantBound = in_array(RequiresBoundTenant::class, class_uses_recursive($class), true);

            if (! $tenantBound && ! in_array($class, $platformLevel, true)) {
                $unclassified[] = $class;
            }
        }

        $this->assertSame(
            [],
            $unclassified,
            "These console commands are neither tenant-bound nor declared platform-level:\n  - "
            .implode("\n  - ", $unclassified)
            ."\n\nAdd `use RequiresBoundTenant;` if the command reads or writes ONE workspace's data "
            .'(it will then refuse to run outside tenant:run), or list it in $platformLevel above if it '
            .'genuinely operates on the platform. Leaving it unclassified means the tenant scope silently '
            ."no-ops and the command sweeps EVERY tenant on the operator's credentials."
        );
    }

    /** The classification is not decorative: the trait really does refuse. */
    public function test_the_tenant_bound_trait_refuses_before_handle_runs(): void
    {
        $commands = [
            'attendance:still-working-check',
            'monitoring:silent-tracker-check',
            'attendance:auto-clock-out',
            'monitoring:close-stale-sessions',
            'monitoring:prune-screenshots',
            'tracker:resync-work-hours',
            'activity:digest-slack',
            'reports:send-weekly-slack',
        ];

        app(CurrentOrganization::class)->forget();

        foreach ($commands as $command) {
            $this->assertSame(
                1,
                Artisan::call($command),
                "{$command} must refuse to run with no organization bound"
            );
            $this->assertStringContainsString('tenant:run', Artisan::output());
        }
    }
}
