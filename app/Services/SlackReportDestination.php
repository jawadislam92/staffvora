<?php

namespace App\Services;

use App\Models\MonitoringSetting;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Resolves WHERE a workspace's reports are allowed to be posted.
 *
 * Every report service used to POST to one env-level incoming webhook
 * (services.slack_reports.webhook_url). That single destination is shared by the
 * whole platform, so at 100-500 customers every tenant's staffing table — names,
 * present/absent counts, late-coming counts, hours — pooled into one Slack
 * channel that none of them own. Both the manual "Send to Slack" buttons and the
 * unattended scheduled reports (run per tenant by tenant:run) did it.
 *
 * The rule now is absolute: a report goes to the workspace's OWN Slack
 * connection or it does not go at all. There is deliberately NO cross-tenant
 * fallback — any non-null global default recreates this exact bug the first time
 * an operator sets it, which is precisely how it arose. The env webhook is
 * honoured only in the `local` environment, as a single-tenant dev convenience.
 *
 * The destination reuses the per-org Slack connection that already exists
 * (monitoring_settings.slack_bot_token, encrypted, + slack_attendance_channel)
 * rather than introducing a second credential type per workspace.
 */
class SlackReportDestination
{
    /** Can the ACTIVE tenant receive reports? */
    public function configured(): bool
    {
        return $this->channel() !== null || $this->devWebhook() !== null;
    }

    /**
     * Post a Slack message payload (`text` and optionally `blocks`) to the
     * active tenant's destination.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException when unconfigured or when Slack rejects it
     */
    public function send(array $payload): void
    {
        $channel = $this->channel();

        if ($channel !== null) {
            $this->postToChannel($channel, $payload);

            return;
        }

        $webhook = $this->devWebhook();

        if ($webhook !== null) {
            $this->postToWebhook($webhook, $payload);

            return;
        }

        throw new RuntimeException($this->unconfiguredMessage());
    }

    /** Message shown to a workspace that has not connected its own Slack. */
    public function unconfiguredMessage(): string
    {
        return 'Slack reporting is not connected for this workspace. Add your Slack bot token and report channel in Settings > Slack.';
    }

    /**
     * The active tenant's report channel, or null when the workspace has not
     * connected Slack. Fail-closed on purpose: an unbound tenant (console,
     * guest, a queue path that forgot to bind) has no destination at all rather
     * than inheriting somebody else's.
     */
    public function channel(): ?string
    {
        $settings = $this->settings();

        if (! $settings || ! filled($settings->slack_bot_token)) {
            return null;
        }

        // A dedicated reports channel wins when the column exists; otherwise the
        // workspace's attendance channel is the destination, so a customer keeps
        // ONE Slack connection instead of two.
        $channel = $settings->slack_reports_channel ?? null;

        if (! filled($channel)) {
            $channel = $settings->slack_attendance_channel;
        }

        return filled($channel) ? (string) $channel : null;
    }

    private function token(): ?string
    {
        $settings = $this->settings();

        // Deliberately NOT falling back to config('services.slack_bot.token'):
        // that platform token posts into the operator's workspace, which is the
        // same cross-tenant leak by another route.
        return $settings && filled($settings->slack_bot_token)
            ? (string) $settings->slack_bot_token
            : null;
    }

    private function settings(): ?MonitoringSetting
    {
        if (app(CurrentOrganization::class)->id() === null) {
            // Never call MonitoringSetting::current() unbound — it would
            // firstOrCreate a stray organization_id = NULL settings row.
            return null;
        }

        try {
            return MonitoringSetting::current();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $payload */
    private function postToChannel(string $channel, array $payload): void
    {
        $token = $this->token();

        if ($token === null) {
            throw new RuntimeException($this->unconfiguredMessage());
        }

        try {
            $response = Http::withToken($token)
                ->asJson()
                ->timeout(10)
                ->retry(2, 250)
                ->post('https://slack.com/api/chat.postMessage', array_merge($payload, [
                    'channel' => $channel,
                ]));
        } catch (Throwable) {
            throw new RuntimeException('The app could not connect to Slack.');
        }

        $body = $response->json() ?? [];

        if ($response->failed() || ($body['ok'] ?? false) !== true) {
            throw new RuntimeException('Slack did not accept the report'
                .(isset($body['error']) ? ' ('.$body['error'].').' : '.'));
        }
    }

    /** @param array<string, mixed> $payload */
    private function postToWebhook(string $webhook, array $payload): void
    {
        try {
            $response = Http::asJson()->timeout(10)->retry(2, 250)->post($webhook, $payload);
        } catch (Throwable) {
            throw new RuntimeException('The app could not connect to Slack.');
        }

        if ($response->failed()) {
            throw new RuntimeException('Slack did not accept the report.');
        }
    }

    /**
     * The legacy env webhook, honoured ONLY in local development. In any other
     * environment it is ignored entirely, so setting it on a shared host cannot
     * silently re-point every tenant's reports at one channel.
     */
    private function devWebhook(): ?string
    {
        if (! app()->environment('local')) {
            return null;
        }

        $webhook = config('services.slack_reports.webhook_url');

        return filled($webhook) ? (string) $webhook : null;
    }
}
