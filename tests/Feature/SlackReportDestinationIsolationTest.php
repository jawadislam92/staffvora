<?php

namespace Tests\Feature;

use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\ActivityDigestService;
use App\Services\AttendanceSlackReportService;
use App\Services\SlackReportService;
use App\Services\TenantProvisioner;
use App\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression cover for [medium-7]: all three report services posted to ONE
 * env-level incoming webhook, so at 100-500 customers every tenant's staffing
 * and activity data pooled into a single Slack channel none of them owned — the
 * scheduled weekly report and daily digest doing it unattended, for every org.
 */
class SlackReportDestinationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_WEBHOOK = 'https://hooks.slack.test/services/shared-operator-channel';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.slack_reports.webhook_url', self::SHARED_WEBHOOK);

        // Only the shared webhook host is stubbed here; per-test stubs must be
        // registered first, because Http::fake() appends rather than replaces.
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);
    }

    private function organization(string $name): Organization
    {
        app(TenantProvisioner::class)->provision($name, 'Owner', 'owner@'.$name.'.test', 'super-secret-pw');

        return Organization::where('name', $name)->firstOrFail();
    }

    public function test_a_workspace_without_its_own_slack_is_not_configured_and_never_falls_back_to_the_shared_webhook(): void
    {
        $org = $this->organization('Acme');

        app(CurrentOrganization::class)->runFor($org, function () {
            $this->assertFalse(app(SlackReportService::class)->configured());
            $this->assertFalse(app(AttendanceSlackReportService::class)->configured());
            $this->assertFalse(app(ActivityDigestService::class)->configured());
        });

        Http::assertNothingSent();
    }

    public function test_the_scheduled_weekly_report_does_not_leak_to_the_shared_webhook(): void
    {
        $org = $this->organization('Acme');

        app(CurrentOrganization::class)->runFor($org, function () {
            User::factory()->create(['name' => 'Acme Staff']);

            // The command tolerates an unconfigured workspace; what matters is
            // that nothing reaches the platform-wide channel.
            $this->artisan('reports:send-weekly-slack');
        });

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'hooks.slack.test'));
    }

    public function test_each_workspace_posts_to_its_own_channel_with_its_own_token(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');
        $current = app(CurrentOrganization::class);

        $current->runFor($acme, fn () => MonitoringSetting::current()->update([
            'slack_bot_token' => 'xoxb-acme',
            'slack_attendance_channel' => '#acme-reports',
        ]));
        $current->runFor($globex, fn () => MonitoringSetting::current()->update([
            'slack_bot_token' => 'xoxb-globex',
            'slack_attendance_channel' => '#globex-reports',
        ]));

        Http::fake(['slack.com/api/*' => Http::response(['ok' => true])]);

        $current->runFor($acme, function () {
            User::factory()->create(['name' => 'Acme Staff']);
            app(AttendanceSlackReportService::class)->sendMonthly('2026-06', [], ['present']);
        });

        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['channel'] === '#acme-reports'
            && $request->hasHeader('Authorization', 'Bearer xoxb-acme'));

        Http::assertNotSent(fn ($request) => ($request['channel'] ?? null) === '#globex-reports');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'hooks.slack.test'));
    }

    public function test_an_unbound_context_has_no_destination_at_all(): void
    {
        $this->organization('Acme');
        app(CurrentOrganization::class)->forget();

        $strayBefore = MonitoringSetting::withoutOrganizationScope()->whereNull('organization_id')->count();

        // No tenant bound (a console/queue path that forgot to bind). It must
        // resolve to "nowhere", not to somebody else's channel — and it must not
        // firstOrCreate a stray organization_id = NULL settings row on the way.
        $this->assertFalse(app(SlackReportService::class)->configured());

        $this->assertSame(
            $strayBefore,
            MonitoringSetting::withoutOrganizationScope()->whereNull('organization_id')->count(),
            'resolving the destination while unbound must not create an orphaned settings row'
        );
    }
}
