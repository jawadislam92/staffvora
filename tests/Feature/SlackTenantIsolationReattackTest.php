<?php

namespace Tests\Feature;

use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\SlackBotService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADVERSARIAL re-attack on the "tenant Slack isolation" fix.
 *
 * The property under attack is NOT "SlackBotService::token() returns null when a
 * tenant is bound and has no token". It is: "no tenant's data can reach the
 * OPERATOR's Slack workspace". Each test here tries to reach the operator token
 * (xoxb-PLATFORM-OPERATOR) by a route the fixer did not patch.
 */
class SlackTenantIsolationReattackTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM = 'xoxb-PLATFORM-OPERATOR';

    protected function setUp(): void
    {
        parent::setUp();

        // The operator HAS a platform Slack bot configured (this is the live
        // production shape — the platform token exists in .env).
        config([
            'services.slack_bot.token' => self::PLATFORM,
            'services.slack_bot.resets_channel' => '#operator-password-resets',
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

    /** Every outbound Slack call that carried the OPERATOR's bot token. */
    private function platformCalls(): array
    {
        $hits = [];

        foreach (Http::recorded() as [$request]) {
            if (! str_contains($request->url(), 'slack.com')) {
                continue;
            }
            $auth = $request->header('Authorization')[0] ?? '';
            if (str_contains($auth, self::PLATFORM)) {
                $hits[] = $request->url();
            }
        }

        return $hits;
    }

    private function tenantWithEmployee(?string $token = null): array
    {
        $org = Organization::factory()->create();

        $user = app(CurrentOrganization::class)->runFor($org, function () use ($token) {
            MonitoringSetting::current()->update([
                'slack_bot_token' => $token,
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

    private function openClockIn(Organization $org, User $user, int $hoursAgo = 20): void
    {
        app(CurrentOrganization::class)->runFor($org, function () use ($user, $hoursAgo) {
            $at = now()->subHours($hoursAgo);
            TimeEntry::create([
                'user_id' => $user->id,
                'action_type' => 'clock_in',
                'action_timestamp' => $at,
                'action_date' => $at->toDateString(),
                'action_time' => $at->format('H:i:s'),
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // ATTACK 1 — the scheduled Slack commands, run WITHOUT tenant:run.
    // Nothing inside the command requires a bound tenant. Unbound, the global
    // scope is a no-op, so it sweeps EVERY tenant's employees, and
    // SlackBotService::token() sees check() === false and hands back the
    // OPERATOR's platform token.
    // ─────────────────────────────────────────────────────────────────────
    public function test_attack_still_working_check_without_tenant_binding(): void
    {
        [$orgA, $userA] = $this->tenantWithEmployee(null);
        [$orgB, $userB] = $this->tenantWithEmployee(null);
        $this->openClockIn($orgA, $userA);
        $this->openClockIn($orgB, $userB);

        // No tenant bound — exactly what `php artisan attendance:still-working-check`
        // does on a console, and what any caller that forgets tenant:run does.
        $this->assertFalse(app(CurrentOrganization::class)->check());

        Artisan::call('attendance:still-working-check', ['--force' => true]);

        $hits = $this->platformCalls();

        $this->assertSame(
            [],
            $hits,
            "HOLE OPEN: attendance:still-working-check run unbound pushed tenant employees through the OPERATOR's Slack token: ".json_encode($hits)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // ATTACK 2 — runUnscoped() defeats the fail-closed guard.
    // token() gates on CurrentOrganization::check(), and check() returns FALSE
    // inside runUnscoped() even though a tenant id is still set. So any Slack
    // send that happens inside a runUnscoped() block resolves the OPERATOR's
    // token while the caller believes a tenant is bound.
    // ─────────────────────────────────────────────────────────────────────
    public function test_attack_run_unscoped_reopens_the_platform_token(): void
    {
        [$orgA] = $this->tenantWithEmployee(null);
        $current = app(CurrentOrganization::class);

        $configured = $current->runFor($orgA, fn () => $current->runUnscoped(
            fn () => app(SlackBotService::class)->configured()
        ));

        $this->assertFalse(
            $configured,
            'HOLE OPEN: inside runUnscoped() the fail-closed guard evaporates and SlackBotService falls back to the operator platform token.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // ATTACK 3 — an unbound Slack probe writes a stray organization_id = NULL
    // settings row. SlackReportDestination explicitly refuses to do this;
    // SlackBotService::token() calls MonitoringSetting::current() with no
    // guard, so it firstOrCreate()s the landlord row.
    // ─────────────────────────────────────────────────────────────────────
    public function test_attack_unbound_probe_creates_a_null_org_settings_row(): void
    {
        Organization::factory()->create();

        // NOTE: every install already carries ONE organization_id = NULL row —
        // the create-table migration inserts it (this app grew out of a
        // single-tenant product). So the property is "the probe adds no NEW
        // ownerless row", measured as a delta, not an absolute count.
        $before = DB::table('monitoring_settings')->whereNull('organization_id')->count();

        app(SlackBotService::class)->configured();

        $after = DB::table('monitoring_settings')->whereNull('organization_id')->count();

        $this->assertSame(
            $before,
            $after,
            'HOLE OPEN: an unbound SlackBotService probe created a monitoring_settings row with organization_id = NULL.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // ATTACK 4 — password reset. Re-attacked as the GOAL (can a customer's
    // reset code reach the operator workspace?), including the operator-owned
    // fallback channel, not just the DM the fixer patched.
    // ─────────────────────────────────────────────────────────────────────
    public function test_attack_password_reset_code_cannot_reach_operator_slack(): void
    {
        [, $user] = $this->tenantWithEmployee(null); // tenant has NOT connected Slack

        $this->post('/forgot-password/code', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [],
            $this->platformCalls(),
            "HOLE OPEN: the reset code was delivered with the OPERATOR's platform bot token."
        );
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    /** Same flow, but the tenant HAS its own Slack: must still work (no over-blocking). */
    public function test_legitimate_tenant_slack_still_delivers_the_reset_code(): void
    {
        [, $user] = $this->tenantWithEmployee('xoxb-TENANT-OWN');

        $this->post('/forgot-password/code', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->platformCalls(), 'operator token must never be used');

        $usedTenantToken = collect(Http::recorded())->contains(
            fn ($pair) => str_contains($pair[0]->url(), 'slack.com')
                && str_contains($pair[0]->header('Authorization')[0] ?? '', 'xoxb-TENANT-OWN')
        );

        $this->assertTrue($usedTenantToken, 'REGRESSION: a tenant with its own Slack no longer receives reset codes.');
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    // ─────────────────────────────────────────────────────────────────────
    // COLLATERAL — a tenant that has NOT connected Slack. The fix makes every
    // alert vanish. Is that visible ANYWHERE (log, exception, admin surface)?
    // ─────────────────────────────────────────────────────────────────────
    public function test_collateral_silent_failure_for_a_tenant_without_a_token(): void
    {
        [$org, $user] = $this->tenantWithEmployee(null);
        $this->openClockIn($org, $user);

        $logged = [];
        \Illuminate\Support\Facades\Log::listen(function ($e) use (&$logged) {
            $logged[] = $e->level.': '.$e->message;
        });

        app(CurrentOrganization::class)->runFor($org, function () use ($user) {
            $slack = app(SlackBotService::class);
            $this->assertFalse($slack->configured(), 'bound tenant with no token must be unconfigured');
            $slack->postToChannel('#attendance', 'someone clocked in');
            $slack->dmByEmail($user->email, 'still working?');
        });

        $slackCalls = collect(Http::recorded())
            ->filter(fn ($p) => str_contains($p[0]->url(), 'slack.com'))->count();

        $this->assertSame(0, $slackCalls, 'nothing may leave the box');

        // THE COLLATERAL IS NOW FIXED. Failing closed is correct; failing
        // closed in TOTAL SILENCE is not — an admin whose attendance alerts
        // stopped had no way whatsoever to find out. The drop must leave a
        // trace somebody can act on.
        $this->assertNotEmpty(
            $logged,
            'COLLATERAL: the fail-closed path is still totally silent — nothing is logged, so a workspace whose Slack alerts stopped can never find out why.'
        );

        $this->assertTrue(
            collect($logged)->contains(fn ($line) => str_contains($line, 'warning:')
                && str_contains($line, 'Slack is not connected for this workspace')),
            'The log line must name the actual cause (workspace has not connected Slack), not just any warning. Got: '.json_encode($logged)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // ATTACK 5 — the DEFERRED attendance announcement. TimeEntry::booted()
    // registers app()->terminating(), which fires AFTER the controller /
    // console command has returned and runFor() has already restored the tenant
    // to null. So the notifier resolves MonitoringSetting::current() and the bot
    // token with NO tenant bound. Two possible outcomes, both interesting:
    // it posts with the OPERATOR's token (leak), or it silently drops the
    // tenant's own announcement (collateral).
    // ─────────────────────────────────────────────────────────────────────
    public function test_attack_deferred_attendance_announcement_runs_unbound(): void
    {
        [$org, $user] = $this->tenantWithEmployee('xoxb-TENANT-OWN');

        app(CurrentOrganization::class)->runFor($org, function () use ($user) {
            MonitoringSetting::current()->update(['slack_attendance_channel' => '#tenant-attendance']);
            $at = now();
            TimeEntry::create([
                'user_id' => $user->id,
                'action_type' => 'clock_in',
                'action_timestamp' => $at,
                'action_date' => $at->toDateString(),
                'action_time' => $at->format('H:i:s'),
            ]);
        });

        $this->assertFalse(app(CurrentOrganization::class)->check(), 'tenant is unbound by the time terminating() runs');

        $this->app->terminate();

        $this->assertSame(
            [],
            $this->platformCalls(),
            'HOLE OPEN: the deferred attendance announcement posted with the OPERATOR platform token.'
        );

        $tenantCalls = collect(Http::recorded())
            ->filter(fn ($p) => str_contains($p[0]->url(), 'slack.com')
                && str_contains($p[0]->header('Authorization')[0] ?? '', 'xoxb-TENANT-OWN'))
            ->count();

        $this->assertGreaterThan(
            0,
            $tenantCalls,
            'COLLATERAL: the tenant HAS Slack connected and clock-in posts enabled, but the deferred announcement was silently dropped because no tenant was bound when it ran.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // ATTACK 6 — the same deferred path, but with a LEGACY organization_id =
    // NULL settings row that has attendance posts switched on. Live prod grew
    // out of a single-tenant app, and ATTACK 3 shows the app happily creates
    // such rows itself. Nothing in the fix looks at organization_id when it
    // reads the toggles, so the only thing standing between this path and the
    // operator's workspace is the DEFAULT value of a column.
    // ─────────────────────────────────────────────────────────────────────
    public function test_attack_legacy_null_org_settings_row_reopens_the_leak(): void
    {
        [$org, $user] = $this->tenantWithEmployee('xoxb-TENANT-OWN');

        app(CurrentOrganization::class)->runFor($org, function () use ($user) {
            $at = now();
            TimeEntry::create([
                'user_id' => $user->id,
                'action_type' => 'clock_in',
                'action_timestamp' => $at,
                'action_date' => $at->toDateString(),
                'action_time' => $at->format('H:i:s'),
            ]);
        });

        // The stray landlord row. Every install has one (the create-table
        // migration inserts it — this app grew out of a single-tenant product),
        // and here it carries attendance posts switched ON pointing at the
        // OPERATOR's own channel, which is exactly how the single-tenant
        // original shipped. Written straight through the query builder so it is
        // a genuine legacy row and not something the model layer produced.
        DB::table('monitoring_settings')->whereNull('organization_id')->update([
            'slack_clockin_enabled' => true,
            'slack_clockout_enabled' => true,
            'slack_attendance_channel' => '#operator-attendance',
        ]);

        $this->assertSame(
            1,
            DB::table('monitoring_settings')->whereNull('organization_id')
                ->where('slack_clockin_enabled', true)->count(),
            'the legacy ownerless settings row must actually exist for this attack to be meaningful'
        );

        $this->app->terminate();

        $this->assertSame(
            [],
            $this->platformCalls(),
            "HOLE OPEN: with an organization_id = NULL settings row that has attendance posts on, a tenant's clock-in (employee name + time) was announced into the OPERATOR's env channel using the OPERATOR's bot token."
        );
    }

    /** The org's own token must still be preferred and actually used. */
    public function test_legitimate_bound_tenant_uses_its_own_token(): void
    {
        [$org] = $this->tenantWithEmployee('xoxb-TENANT-OWN');

        app(CurrentOrganization::class)->runFor($org, fn () => app(SlackBotService::class)
            ->postToChannel('#team', 'hello'));

        $used = collect(Http::recorded())
            ->filter(fn ($p) => str_contains($p[0]->url(), 'slack.com'))
            ->map(fn ($p) => $p[0]->header('Authorization')[0] ?? '')
            ->all();

        $this->assertNotEmpty($used);
        foreach ($used as $auth) {
            $this->assertStringContainsString('xoxb-TENANT-OWN', $auth);
        }
    }
}
