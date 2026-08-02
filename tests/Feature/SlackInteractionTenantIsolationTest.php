<?php

namespace Tests\Feature;

use App\Models\AttendanceClockCheck;
use App\Models\Organization;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression cover for [medium-20] / [medium-21] / [medium-22]: the
 * unauthenticated Slack interaction endpoint ran with NO tenant bound, resolved
 * an AttendanceClockCheck by a client-supplied id across every tenant, clocked
 * that user out with no ownership check, and wrote the resulting TimeEntry rows
 * with organization_id = NULL (invisible to the owning workspace forever, so the
 * employee stayed "clocked in" and could never clock in again).
 */
class SlackInteractionTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-slack-signing-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.slack_bot.signing_secret', self::SECRET);

        // Only the response_url host is stubbed here; per-test stubs are
        // registered first and win, since Http::fake() appends rather than
        // replaces.
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    }

    /** POST a correctly-signed Slack interaction payload. */
    private function interact(array $payload)
    {
        $json = json_encode($payload);
        $body = 'payload='.urlencode($json);
        $timestamp = (string) time();

        return $this->call(
            'POST',
            '/api/slack/interact',
            ['payload' => $json],
            [],
            [],
            [
                'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
                'HTTP_X_SLACK_SIGNATURE' => 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, self::SECRET),
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
            $body
        );
    }

    private function payload(int $checkId, string $actionId, string $slackUserId = 'U-VICTIM'): array
    {
        return [
            'user' => ['id' => $slackUserId],
            'response_url' => 'https://hooks.slack.com/actions/T/1/2',
            'actions' => [[
                'action_id' => $actionId,
                'value' => (string) $checkId,
            ]],
        ];
    }

    /**
     * Seed a workspace with one clocked-in employee and an open check.
     *
     * @return array{0: Organization, 1: User, 2: AttendanceClockCheck}
     */
    private function seedClockedInTenant(string $name): array
    {
        app(TenantProvisioner::class)->provision($name, 'Owner', 'owner@'.$name.'.test', 'super-secret-pw');
        $org = Organization::where('name', $name)->firstOrFail();

        return app(CurrentOrganization::class)->runFor($org, function () use ($org) {
            $user = User::factory()->create([
                'organization_id' => $org->id,
                'email' => 'staff@'.$org->slug.'.test',
            ]);

            $clockIn = TimeEntry::create([
                'user_id' => $user->id,
                'action_type' => 'clock_in',
                'action_timestamp' => Carbon::parse('2026-06-01 09:00:00'),
                'action_date' => '2026-06-01',
                'action_time' => '09:00:00',
            ]);

            $check = AttendanceClockCheck::create([
                'user_id' => $user->id,
                'clock_in_id' => $clockIn->id,
            ]);

            return [$org, $user, $check];
        });
    }

    public function test_clock_out_via_slack_stamps_the_owning_organization_on_every_written_row(): void
    {
        [$org, $user, $check] = $this->seedClockedInTenant('Acme');

        $this->interact($this->payload($check->id, 'attendance_clock_out'))->assertOk();

        $clockOut = TimeEntry::withoutOrganizationScope()
            ->where('user_id', $user->id)
            ->where('action_type', 'clock_out')
            ->first();

        $this->assertNotNull($clockOut, 'the Slack button should have closed the session');
        $this->assertSame(
            $org->id,
            $clockOut->organization_id,
            'a NULL organization_id here is invisible to the tenant and blocks the employee from ever clocking in again'
        );
        $this->assertSame(0, TimeEntry::withoutOrganizationScope()->whereNull('organization_id')->count());

        $this->assertNotNull($check->fresh()->resolved_at);
    }

    public function test_a_check_id_belonging_to_another_tenant_cannot_be_used_to_reach_into_that_tenant(): void
    {
        [$victimOrg, , $victimCheck] = $this->seedClockedInTenant('Victim');

        // The victim workspace has connected its own Slack, so the clicker's
        // identity is resolvable and the ownership check is load-bearing.
        app(CurrentOrganization::class)->runFor($victimOrg, fn () => \App\Models\MonitoringSetting::current()
            ->update(['slack_bot_token' => 'xoxb-victim']));

        // The attacker replays a valid Slack signature but swaps in the victim's
        // check id. The tenant is resolved from the STORED row, and the clicking
        // Slack account is then matched against that row's employee.
        Http::fake([
            'slack.com/api/users.info*' => Http::response([
                'ok' => true,
                'user' => ['profile' => ['email' => 'attacker@evil.test']],
            ]),
        ]);

        $this->interact($this->payload($victimCheck->id, 'attendance_clock_out', 'U-ATTACKER'))->assertOk();

        $this->assertNull($victimCheck->fresh()->resolved_at, 'the foreign check must not be resolved');
        $this->assertSame(
            0,
            TimeEntry::withoutOrganizationScope()->where('action_type', 'clock_out')->count(),
            'nobody in another workspace may be clocked out by a swapped id'
        );
    }

    public function test_a_check_with_no_organization_is_refused_instead_of_written_unscoped(): void
    {
        [, $user, $check] = $this->seedClockedInTenant('Legacy');

        // Simulate a pre-tenancy row: organization_id is nullable.
        AttendanceClockCheck::withoutOrganizationScope()
            ->whereKey($check->id)
            ->update(['organization_id' => null]);

        $this->interact($this->payload($check->id, 'attendance_clock_out'))->assertOk();

        $this->assertSame(
            0,
            TimeEntry::withoutOrganizationScope()
                ->where('user_id', $user->id)
                ->where('action_type', 'clock_out')
                ->count()
        );
    }

    public function test_an_unknown_check_id_is_not_an_existence_oracle_and_writes_nothing(): void
    {
        $this->interact($this->payload(999999, 'attendance_clock_out'))->assertOk();

        $this->assertSame(0, TimeEntry::withoutOrganizationScope()->count());
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $this->postJson('/api/slack/interact', ['payload' => json_encode($this->payload(1, 'attendance_clock_out'))])
            ->assertStatus(403);
    }

    public function test_the_response_url_host_is_not_attacker_controlled(): void
    {
        [, , $check] = $this->seedClockedInTenant('Acme');

        $payload = $this->payload($check->id, 'attendance_still_working');
        $payload['response_url'] = 'https://evil.test/collect';

        $this->interact($payload)->assertOk();

        // The action still runs; the confirmation is simply never relayed to a
        // non-Slack host (an unvalidated response_url is an SSRF relay).
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.test'));
        $this->assertNotNull($check->fresh()->confirmed_until);
    }

    public function test_double_clicking_clock_out_does_not_close_the_session_twice(): void
    {
        [, $user, $check] = $this->seedClockedInTenant('Acme');

        $this->interact($this->payload($check->id, 'attendance_clock_out'))->assertOk();
        $this->interact($this->payload($check->id, 'attendance_clock_out'))->assertOk();

        $this->assertSame(
            1,
            TimeEntry::withoutOrganizationScope()
                ->where('user_id', $user->id)
                ->where('action_type', 'clock_out')
                ->count()
        );
    }
}
