<?php

namespace App\Services;

use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Slack Web API client using a bot token (xoxb-...). One credential can post
 * to any channel the bot is a member of and DM any workspace member —
 * unlike incoming webhooks, which are locked to a single channel each.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE PLATFORM TOKEN IS OPT-IN, NOT A FALLBACK
 * ─────────────────────────────────────────────────────────────────────────────
 * The operator's own bot token (services.slack_bot.token) posts into the
 * OPERATOR's Slack workspace. Every cross-tenant leak this class has produced
 * had the same shape: some path ran without a tenant bound — a console command
 * started without `tenant:run`, a terminating callback that fired after the
 * tenant was restored to null, an anonymous endpoint — and "no tenant bound"
 * was read as "this must be a platform message".
 *
 * It is not. An unbound context is an UNKNOWN context, and the safe reading of
 * unknown is "send nothing". The platform credential is now reachable only when
 * a caller explicitly says so:
 *
 *     app(SlackBotService::class)->forPlatform()->postToChannel(...)
 *
 * so a path that merely forgets to bind a tenant cannot silently borrow it. No
 * amount of new commands, jobs or callbacks can re-open that door by omission —
 * only by writing forPlatform() on purpose.
 */
class SlackBotService
{
    /**
     * Set only by forPlatform(): this instance is sending a genuinely
     * platform-level message and may use the operator's own bot token.
     */
    private bool $platform = false;

    /** How often the same "cannot send" condition is logged, per org. */
    private const WARN_TTL_MINUTES = 60;

    /**
     * An instance permitted to use the OPERATOR's platform bot token — for
     * genuinely platform-level messages only (operator alerting about the
     * platform itself). Never use this for anything derived from tenant data.
     */
    public function forPlatform(): self
    {
        $clone = clone $this;
        $clone->platform = true;

        return $clone;
    }

    public function configured(): bool
    {
        return filled($this->token());
    }

    /**
     * Resolve the bot token: the BOUND organization's own token when connected,
     * the platform token only for an explicitly platform-level instance.
     */
    private function token(): ?string
    {
        $current = app(CurrentOrganization::class);

        // check() answers "is a tenant bound?", NOT "is query scoping on?".
        // Using the scoping answer here meant a runFor(...) { runUnscoped(...) }
        // block reported "unbound" and fell through to the operator's token
        // while a tenant was very much bound. See CurrentOrganization.
        if ($current->check()) {
            try {
                $orgToken = \App\Models\MonitoringSetting::current()->slack_bot_token;
            } catch (\Throwable $e) {
                $orgToken = null;
            }

            if (filled($orgToken)) {
                return $orgToken;
            }

            // FAIL CLOSED — but NOT SILENTLY. A workspace that has not connected
            // its own Slack gets NOTHING; it must never borrow the operator's
            // platform bot, because that delivered one customer's password-reset
            // code straight into the operator's own Slack workspace where every
            // operator staffer could read it. Same rule as SlackReportDestination.
            //
            // The previous version stopped there and said nothing anywhere, so an
            // admin whose daily digest / attendance alerts / "still working?"
            // nudges had stopped firing had no way at all to find out. One
            // throttled line per workspace per hour is enough to answer "why did
            // Slack go quiet?" without turning a 500-tenant schedule into a log
            // flood.
            $this->warnOnce(
                'slack.unconfigured.'.$current->boundId(),
                'Slack is not connected for this workspace — the message was dropped. Add a bot token in Settings > Slack.',
                ['organization_id' => $current->boundId()]
            );

            return null;
        }

        if (! $this->platform) {
            // No tenant bound and nobody asked for a platform message: refuse.
            // This is the door every "forgot tenant:run" bug walked through.
            $this->warnOnce(
                'slack.unbound',
                'Slack send attempted with NO organization bound; refused rather than falling back to the platform operator token. The caller must run under tenant:run (or bind a tenant) — or call forPlatform() if it really is a platform message.',
                []
            );

            return null;
        }

        return config('services.slack_bot.token');
    }

    /**
     * Log at most one line per key per hour. Cache::add is atomic, so concurrent
     * workers cannot each emit the same warning.
     */
    private function warnOnce(string $key, string $message, array $context): void
    {
        try {
            $first = Cache::add('slack-warn:'.$key, true, now()->addMinutes(self::WARN_TTL_MINUTES));
        } catch (\Throwable $e) {
            $first = true; // a broken cache must never suppress the warning
        }

        if ($first) {
            Log::warning($message, $context);
        }
    }

    /**
     * Post a message to a channel (by #name or ID). Returns true on success.
     */
    public function postToChannel(string $channel, string $text): bool
    {
        $response = $this->api('chat.postMessage', [
            'channel' => $channel,
            'text' => $text,
        ]);

        return (bool) ($response['ok'] ?? false);
    }

    /**
     * Post to a channel, optionally as a reply inside an existing thread.
     * Returns the new message's `ts` — the anchor later replies thread onto —
     * or null on failure. Lets a person's whole day (clock-in, breaks, clock-out)
     * collapse into ONE thread instead of spamming the channel.
     */
    public function postToThread(string $channel, string $text, ?string $threadTs = null): ?string
    {
        $params = ['channel' => $channel, 'text' => $text];
        if ($threadTs) {
            $params['thread_ts'] = $threadTs;
        }

        $response = $this->api('chat.postMessage', $params);

        return ($response['ok'] ?? false) ? ($response['ts'] ?? null) : null;
    }

    /**
     * DM a workspace member found by their email. Returns true when the
     * message was delivered; false when the user isn't on Slack with that
     * email or the API call failed.
     */
    public function dmByEmail(string $email, string $text): bool
    {
        $lookup = $this->api('users.lookupByEmail', ['email' => $email], get: true);

        $userId = $lookup['user']['id'] ?? null;
        if (! $userId) {
            return false;
        }

        $open = $this->api('conversations.open', ['users' => $userId]);
        $channelId = $open['channel']['id'] ?? null;
        if (! $channelId) {
            return false;
        }

        return $this->postToChannel($channelId, $text);
    }

    /**
     * DM a workspace member (found by email) an interactive message with blocks
     * (e.g. buttons). `text` is the notification/fallback. Returns true when
     * delivered.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function dmBlocksByEmail(string $email, string $text, array $blocks): bool
    {
        $lookup = $this->api('users.lookupByEmail', ['email' => $email], get: true);
        $userId = $lookup['user']['id'] ?? null;
        if (! $userId) {
            return false;
        }

        $open = $this->api('conversations.open', ['users' => $userId]);
        $channelId = $open['channel']['id'] ?? null;
        if (! $channelId) {
            return false;
        }

        $response = $this->api('chat.postMessage', [
            'channel' => $channelId,
            'text' => $text,
            'blocks' => $blocks,
        ]);

        return (bool) ($response['ok'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function api(string $method, array $params, bool $get = false): array
    {
        // MUST go through token(), which prefers the workspace's OWN connected
        // Slack. Reading the config directly here meant a tenant that connected
        // their own Slack looked connected (configured() consults token()) while
        // every call silently failed with not_configured.
        //
        // token() is also what emits the throttled "why is Slack quiet?" warning,
        // so an unconfigured workspace is no longer a completely silent drop.
        $token = $this->token();

        if (! $token) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        try {
            $request = Http::withToken($token)->timeout(10);

            $response = $get
                ? $request->get("https://slack.com/api/{$method}", $params)
                : $request->asJson()->post("https://slack.com/api/{$method}", $params);

            $body = $response->json() ?? [];

            if (! ($body['ok'] ?? false)) {
                Log::warning("Slack {$method} failed", ['error' => $body['error'] ?? 'unknown']);
            }

            return $body;
        } catch (\Throwable $e) {
            Log::warning("Slack {$method} exception", ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'exception'];
        }
    }
}
