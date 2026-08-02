<?php

namespace Tests\Feature;

use App\Http\Controllers\DeveloperController;
use App\Models\DesktopDiagnostic;
use App\Models\Organization;
use App\Models\PlatformUser;
use App\Models\TimeEntry;
use App\Models\TrackingActivitySample;
use App\Models\TrackingSession;
use App\Models\User;
use App\Services\SlackBotService;
use App\Support\BusinessTime;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Desktop diagnostics ingest + the BREAK-AWARE capture-health watchdog.
 *
 * The watchdog's whole value is that people trust it. A monitoring alert that
 * fires while someone is legitimately on a break gets muted within a week, and
 * then a genuinely broken tracker goes unnoticed until payroll is wrong — so the
 * "stays silent" cases below are as load-bearing as the "does alert" case.
 */
class TrackerHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** An active workspace, bound as the current tenant. */
    private function tenant(): Organization
    {
        $organization = Organization::factory()->timezone('Asia/Karachi')->create([
            'status' => Organization::STATUS_ACTIVE,
        ]);

        app(CurrentOrganization::class)->set($organization);
        BusinessTime::flushTzCache();

        return $organization;
    }

    /**
     * A workspace that may actually use the product — the desktop API sits behind
     * `tenant.usable`, so an unsubscribed workspace is answered with 402 before
     * the controller is ever reached.
     */
    private function usableTenant(): Organization
    {
        $organization = $this->tenant();

        \App\Models\Plan::ensureSeeded();
        $plan = \App\Models\Plan::where('key', 'pro')->first() ?? \App\Models\Plan::orderBy('sort')->first();

        if ($plan) {
            \App\Models\Subscription::create([
                'plan_id' => $plan->id,
                'status' => 'active',
                'current_period_end' => now()->addMonth(),
            ]);
        }

        return $organization;
    }

    private function punch(User $user, string $type, Carbon $at): TimeEntry
    {
        return TimeEntry::create([
            'user_id' => $user->id,
            'action_type' => $type,
            'action_timestamp' => $at,
            'action_date' => $at->toDateString(),
            'action_time' => $at->format('H:i:s'),
        ]);
    }

    /**
     * A live session (heartbeats arriving) that has captured nothing — the exact
     * shape the watchdog has to make a judgement call about.
     */
    private function silentSession(User $user, string $uuid, int $totalSeconds = 3600): TrackingSession
    {
        return TrackingSession::create([
            'user_id' => $user->id,
            'client_uuid' => $uuid,
            'started_at' => now()->subHours(2),
            'last_heartbeat_at' => now()->subMinute(),
            'total_seconds' => $totalSeconds,
            'status' => TrackingSession::STATUS_ACTIVE,
            'source' => 'desktop',
            'device_name' => 'DESKTOP-TEST',
        ]);
    }

    /** Advance the tracker's counter, as a live desktop agent's heartbeat would. */
    private function advanceTrackedSeconds(TrackingSession $session, int $by = 1200): void
    {
        TrackingSession::whereKey($session->id)->update([
            'total_seconds' => (int) $session->total_seconds + $by,
        ]);
    }

    // -----------------------------------------------------------------------
    // Watchdog — it must NOT cry wolf
    // -----------------------------------------------------------------------

    public function test_watchdog_stays_silent_while_the_person_is_on_a_break(): void
    {
        $this->tenant();
        $user = User::factory()->create(['name' => 'On Break']);

        $this->punch($user, 'clock_in', Carbon::now()->subHours(3));
        $this->punch($user, 'break_start', Carbon::now()->subMinutes(30));

        $session = $this->silentSession($user, 'uuid-break');

        // Two passes: a break is exactly the case where the desktop can keep the
        // seconds counter advancing while capturing nothing, which is what used
        // to be misread as an antivirus block.
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);
        $this->advanceTrackedSeconds($session);
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);

        $this->assertNull(
            $session->refresh()->health_alerted_at,
            'A tracker capturing nothing because the person is ON BREAK must never alert.'
        );
    }

    public function test_watchdog_stays_silent_when_the_person_is_clocked_out(): void
    {
        $this->tenant();
        $user = User::factory()->create(['name' => 'Clocked Out']);

        $this->punch($user, 'clock_in', Carbon::now()->subHours(9));
        $this->punch($user, 'clock_out', Carbon::now()->subHour());

        $session = $this->silentSession($user, 'uuid-clocked-out');

        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);
        $this->advanceTrackedSeconds($session);
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);

        $this->assertNull(
            $session->refresh()->health_alerted_at,
            'A tracker left running after clock-out legitimately captures nothing.'
        );
    }

    public function test_watchdog_stays_silent_when_there_are_no_punches_at_all(): void
    {
        $this->tenant();
        $user = User::factory()->create(['name' => 'Never Clocked In']);

        $session = $this->silentSession($user, 'uuid-no-punches');

        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);
        $this->advanceTrackedSeconds($session);
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);

        $this->assertNull($session->refresh()->health_alerted_at);
    }

    public function test_watchdog_stays_silent_outside_the_shift_window(): void
    {
        // Frozen clock so the shift maths is deterministic: 10:00 local, with a
        // shift that does not start until 20:00.
        Carbon::setTestNow(Carbon::parse('2026-08-03 10:00:00', 'Asia/Karachi'));

        $this->tenant();
        $user = User::factory()->create([
            'name' => 'Night Shift',
            'shift_start_time' => '20:00',
            'shift_hours' => 8,
        ]);

        $this->punch($user, 'clock_in', Carbon::now()->subHours(3));

        $session = $this->silentSession($user, 'uuid-off-shift');

        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);
        $this->advanceTrackedSeconds($session);
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);

        $this->assertNull(
            $session->refresh()->health_alerted_at,
            'Nobody should be alerted about a machine outside the person’s shift window.'
        );
    }

    public function test_watchdog_honours_a_one_day_shift_override_over_the_standing_shift(): void
    {
        // Standing shift says 20:00 (off-shift at 10:00), but today's override
        // moves them to 09:00 — so 10:00 IS inside their working window and the
        // alert must fire. This is the shift precedence, exercised.
        Carbon::setTestNow(Carbon::parse('2026-08-03 10:00:00', 'Asia/Karachi'));

        $this->tenant();
        $user = User::factory()->create([
            'name' => 'Swapped Shift',
            'shift_start_time' => '20:00',
            'shift_hours' => 8,
        ]);

        \App\Models\ShiftOverride::create([
            'user_id' => $user->id,
            'date' => '2026-08-03',
            'shift_start_time' => '09:00',
            'shift_hours' => 8,
        ]);

        $this->punch($user, 'clock_in', Carbon::now()->subHour());

        $session = $this->silentSession($user, 'uuid-override');

        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);
        $this->advanceTrackedSeconds($session);
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);

        $this->assertNotNull(
            $session->refresh()->health_alerted_at,
            'A one-day override must win over the standing shift when deciding the window.'
        );
    }

    public function test_watchdog_stays_silent_for_deactivated_users(): void
    {
        $this->tenant();
        $user = User::factory()->create(['name' => 'Archived Person', 'is_active' => false]);

        $this->punch($user, 'clock_in', Carbon::now()->subHours(3));

        $session = $this->silentSession($user, 'uuid-archived');

        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);
        $this->advanceTrackedSeconds($session);
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);

        $this->assertNull($session->refresh()->health_alerted_at);
    }

    public function test_watchdog_stays_silent_while_the_tracker_is_paused(): void
    {
        $this->tenant();
        $user = User::factory()->create(['name' => 'Idle Person']);

        $this->punch($user, 'clock_in', Carbon::now()->subHours(3));

        $session = $this->silentSession($user, 'uuid-paused');

        // Two passes with the counter FROZEN: paused/idle, not blocked.
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);

        $this->assertNull(
            $session->refresh()->health_alerted_at,
            'Frozen tracked seconds means the tracker is paused, not blocked.'
        );
    }

    public function test_watchdog_leaves_a_session_that_is_still_capturing_alone(): void
    {
        $this->tenant();
        $user = User::factory()->create(['name' => 'Healthy Person']);

        $this->punch($user, 'clock_in', Carbon::now()->subHours(3));

        $session = $this->silentSession($user, 'uuid-healthy');

        TrackingActivitySample::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => now()->subMinute(),
        ]);

        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);
        $this->advanceTrackedSeconds($session);
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);

        $this->assertNull($session->refresh()->health_alerted_at);
    }

    // -----------------------------------------------------------------------
    // Watchdog — it MUST fire for a genuinely broken tracker
    // -----------------------------------------------------------------------

    public function test_watchdog_alerts_when_someone_is_working_but_the_tracker_stopped_reporting(): void
    {
        $this->tenant();
        $user = User::factory()->create(['name' => 'Blocked Person']);

        $this->punch($user, 'clock_in', Carbon::now()->subHours(3));

        $session = $this->silentSession($user, 'uuid-blocked');

        // Pass 1 records the probe baseline only — one pass of latency is the
        // price of never crying wolf.
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);
        $this->assertNull($session->refresh()->health_alerted_at);
        $this->assertSame(3600, (int) $session->refresh()->health_probe_seconds);

        // The counter advances (working) while capture stays dead → antivirus block.
        $this->advanceTrackedSeconds($session);
        $this->artisan('monitoring:silent-tracker-check')->assertExitCode(0);

        $this->assertNotNull(
            $session->refresh()->health_alerted_at,
            'A tracker counting time while capturing nothing is exactly the case this exists for.'
        );

        // The decision is explained in the diagnostics store, not just in Slack.
        $diagnostic = DesktopDiagnostic::query()
            ->category(DesktopDiagnostic::CATEGORY_TRACKER_HEALTH)
            ->latest('id')
            ->first();

        $this->assertNotNull($diagnostic);
        $this->assertSame('error', $diagnostic->level);
        $this->assertStringContainsString('ALERTED', $diagnostic->summary);
        $this->assertSame($user->id, $diagnostic->user_id);
    }

    public function test_watchdog_alerts_only_once_per_session(): void
    {
        $this->tenant();
        $user = User::factory()->create(['name' => 'Blocked Once']);
        $this->punch($user, 'clock_in', Carbon::now()->subHours(3));

        $session = $this->silentSession($user, 'uuid-once');

        $this->artisan('monitoring:silent-tracker-check');
        $this->advanceTrackedSeconds($session);
        $this->artisan('monitoring:silent-tracker-check');

        $firstAlert = $session->refresh()->health_alerted_at;
        $this->assertNotNull($firstAlert);

        $this->advanceTrackedSeconds($session->refresh());
        $this->artisan('monitoring:silent-tracker-check');

        $this->assertEquals(
            $firstAlert->toDateTimeString(),
            $session->refresh()->health_alerted_at->toDateTimeString(),
            'An already-alerted session must not be re-announced every fifteen minutes.'
        );
    }

    public function test_watchdog_threshold_is_configurable(): void
    {
        $this->tenant();
        $user = User::factory()->create(['name' => 'Recent Capture']);
        $this->punch($user, 'clock_in', Carbon::now()->subHours(3));

        $session = $this->silentSession($user, 'uuid-threshold');

        TrackingActivitySample::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => now()->subMinutes(30),
        ]);

        // 45-minute threshold: a capture 30 minutes ago is still healthy.
        $this->artisan('monitoring:silent-tracker-check', ['--minutes' => 45]);
        $this->advanceTrackedSeconds($session);
        $this->artisan('monitoring:silent-tracker-check', ['--minutes' => 45]);
        $this->assertNull($session->refresh()->health_alerted_at);

        // 10-minute threshold: the same session is now overdue.
        $this->artisan('monitoring:silent-tracker-check', ['--minutes' => 10]);
        $this->advanceTrackedSeconds($session->refresh());
        $this->artisan('monitoring:silent-tracker-check', ['--minutes' => 10]);
        $this->assertNotNull($session->refresh()->health_alerted_at);
    }

    // -----------------------------------------------------------------------
    // Scale / isolation
    // -----------------------------------------------------------------------

    public function test_a_failure_in_one_tenant_does_not_stop_the_watchdog_for_the_others(): void
    {
        config(['services.attendance.clockin_channel' => '#attendance']);

        $current = app(CurrentOrganization::class);

        $organizationA = $this->tenant();
        $userA = User::factory()->create(['organization_id' => $organizationA->id, 'name' => 'Tenant A Person']);
        $this->punch($userA, 'clock_in', Carbon::now()->subHours(3));
        $sessionA = $this->silentSession($userA, 'uuid-tenant-a');

        $organizationB = $this->tenant();
        $userB = User::factory()->create(['organization_id' => $organizationB->id, 'name' => 'Tenant B Person']);
        $this->punch($userB, 'clock_in', Carbon::now()->subHours(3));
        $sessionB = $this->silentSession($userB, 'uuid-tenant-b');

        $current->forget();

        // Slack blows up for tenant A only — a real outage looks exactly like
        // this when one workspace's bot token has been revoked.
        $this->app->instance(SlackBotService::class, new class($organizationA->id) extends SlackBotService
        {
            public function __construct(private int $explodingOrganizationId) {}

            public function postToChannel(string $channel, string $text): bool
            {
                if (app(CurrentOrganization::class)->id() === $this->explodingOrganizationId) {
                    throw new \RuntimeException('slack is down for this workspace');
                }

                return true;
            }
        });

        // Pass 1: baselines for both tenants.
        $this->artisan('tenant:run', ['target' => 'monitoring:silent-tracker-check']);

        $this->advanceTrackedSeconds($sessionA->refresh());
        $this->advanceTrackedSeconds($sessionB->refresh());

        // Pass 2: tenant A throws, tenant B must still be processed.
        $this->artisan('tenant:run', ['target' => 'monitoring:silent-tracker-check'])->assertExitCode(0);

        $this->assertNull(
            $current->runUnscoped(fn () => TrackingSession::whereKey($sessionA->id)->value('health_alerted_at')),
            'Tenant A could not be announced, so it is left un-flagged for the next pass.'
        );
        $this->assertNotNull(
            $current->runUnscoped(fn () => TrackingSession::whereKey($sessionB->id)->value('health_alerted_at')),
            'Tenant B must still be swept after tenant A failed.'
        );
    }

    public function test_tenant_run_continues_past_a_tenant_that_throws(): void
    {
        $organizationA = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);
        $organizationB = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);
        app(CurrentOrganization::class)->forget();

        $visited = [];

        Artisan::command('tests:tenant-probe', function () use (&$visited, $organizationA) {
            $id = app(CurrentOrganization::class)->id();
            $visited[] = $id;

            if ($id === $organizationA->id) {
                throw new \RuntimeException('this tenant is broken');
            }
        });

        $this->artisan('tenant:run', ['target' => 'tests:tenant-probe']);

        $this->assertContains($organizationA->id, $visited);
        $this->assertContains($organizationB->id, $visited, 'A throwing tenant must not abort the loop.');
    }

    // -----------------------------------------------------------------------
    // Desktop diagnostics ingest
    // -----------------------------------------------------------------------

    public function test_desktop_agent_can_report_diagnostics(): void
    {
        $organization = $this->usableTenant();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        Sanctum::actingAs($user);

        $this->postJson(route('desktop.diagnostics.store'), [
            'device_name' => 'DESKTOP-A17',
            'events' => [
                [
                    'level' => 'warn',
                    'event' => 'screenshot_capture_failed',
                    'message' => 'Access is denied',
                    'context' => ['code' => 'EACCES'],
                    'at' => now()->subMinute()->toIso8601String(),
                    'app_version' => '0.4.5',
                    'platform' => 'win32',
                ],
                [
                    'level' => 'error',
                    'event' => 'agent_crashed',
                ],
            ],
        ])->assertOk()->assertJson(['stored' => 2]);

        $this->assertSame(2, DesktopDiagnostic::query()->count());

        $stored = DesktopDiagnostic::query()->where('event', 'screenshot_capture_failed')->first();
        $this->assertSame($organization->id, $stored->organization_id);
        $this->assertSame($user->id, $stored->user_id);
        $this->assertSame(DesktopDiagnostic::CATEGORY_DESKTOP, $stored->category);
        $this->assertSame(['code' => 'EACCES'], $stored->context);
        $this->assertNotNull($stored->captured_at);
    }

    public function test_diagnostics_ingest_ignores_client_supplied_tenant_and_user(): void
    {
        $victim = $this->usableTenant();
        $victimUser = User::factory()->create(['organization_id' => $victim->id]);

        $attackerOrganization = $this->usableTenant();
        $attacker = User::factory()->create(['organization_id' => $attackerOrganization->id]);

        Sanctum::actingAs($attacker);

        $this->postJson(route('desktop.diagnostics.store'), [
            'organization_id' => $victim->id,
            'user_id' => $victimUser->id,
            'events' => [['level' => 'info', 'event' => 'ping']],
        ])->assertOk();

        $row = app(CurrentOrganization::class)->runUnscoped(fn () => DesktopDiagnostic::query()->first());

        $this->assertSame($attackerOrganization->id, $row->organization_id, 'The tenant must come from the token, never the body.');
        $this->assertSame($attacker->id, $row->user_id);
    }

    public function test_diagnostics_ingest_rejects_an_oversized_batch(): void
    {
        $this->usableTenant();
        Sanctum::actingAs(User::factory()->create());

        $events = array_fill(0, 51, ['level' => 'info', 'event' => 'ping']);

        $this->postJson(route('desktop.diagnostics.store'), ['events' => $events])
            ->assertStatus(422);

        $this->assertSame(0, DesktopDiagnostic::query()->count());
    }

    public function test_diagnostics_ingest_caps_an_oversized_context(): void
    {
        $this->usableTenant();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('desktop.diagnostics.store'), [
            'events' => [[
                'level' => 'info',
                'event' => 'noisy',
                'context' => ['blob' => str_repeat('x', 20000)],
            ]],
        ])->assertOk();

        $stored = DesktopDiagnostic::query()->first();

        $this->assertArrayHasKey('_diagnostics', $stored->context);
        $this->assertLessThanOrEqual(
            DesktopDiagnostic::MAX_CONTEXT_BYTES,
            strlen(json_encode($stored->context))
        );
    }

    public function test_diagnostics_ingest_requires_authentication(): void
    {
        $this->postJson(route('desktop.diagnostics.store'), [
            'events' => [['level' => 'info', 'event' => 'ping']],
        ])->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // Retention
    // -----------------------------------------------------------------------

    public function test_diagnostics_are_pruned_by_the_retention_sweep(): void
    {
        $this->tenant();
        $user = User::factory()->create();

        $old = DesktopDiagnostic::record([
            'user_id' => $user->id,
            'level' => 'info',
            'event' => 'old_event',
            'captured_at' => now()->subDays(45),
        ]);
        DesktopDiagnostic::whereKey($old->id)->update(['created_at' => now()->subDays(45)]);

        $recent = DesktopDiagnostic::record([
            'user_id' => $user->id,
            'level' => 'info',
            'event' => 'recent_event',
            'captured_at' => now()->subDay(),
        ]);

        $this->artisan('monitoring:prune-screenshots')->assertExitCode(0);

        $this->assertNull(DesktopDiagnostic::find($old->id), 'Diagnostics past the 30-day platform ceiling must be deleted.');
        $this->assertNotNull(DesktopDiagnostic::find($recent->id));
    }

    // -----------------------------------------------------------------------
    // Operator panel
    // -----------------------------------------------------------------------

    public function test_developer_diagnostics_payload_is_aggregated_across_tenants(): void
    {
        $organization = $this->tenant();
        $user = User::factory()->create(['organization_id' => $organization->id, 'name' => 'Reporter']);

        DesktopDiagnostic::record([
            'user_id' => $user->id,
            'level' => 'error',
            'event' => 'agent_crashed',
            'summary' => 'agent crashed on resume',
        ]);
        DesktopDiagnostic::record([
            'user_id' => $user->id,
            'category' => DesktopDiagnostic::CATEGORY_TRACKER_HEALTH,
            'level' => 'info',
            'event' => 'tracker_health_decision',
            'summary' => 'skipped: on break',
        ]);

        app(CurrentOrganization::class)->forget();

        $operator = PlatformUser::create([
            'name' => 'Operator',
            'email' => 'op@platform.test',
            'password' => 'operator-secret-pw',
            'role' => 'owner',
        ]);
        $this->actingAs($operator, 'platform');

        // The route lives in routes/web.php, which this change does not own —
        // the controller action is exercised directly so the behaviour is still
        // covered the moment the route line is registered.
        $payload = app(DeveloperController::class)
            ->diagnostics(Request::create('/platform/developer/diagnostics', 'GET'))
            ->getData(true);

        $this->assertSame(1, $payload['counts'][DesktopDiagnostic::CATEGORY_DESKTOP]);
        $this->assertSame(1, $payload['counts'][DesktopDiagnostic::CATEGORY_TRACKER_HEALTH]);
        $this->assertSame(1, $payload['levels']['error']);
        $this->assertCount(2, $payload['recent']);
        $this->assertSame($organization->name, $payload['recent'][0]['workspace']);
        $this->assertSame('Reporter', $payload['recent'][0]['user']);
    }
}
