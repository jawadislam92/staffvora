<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Slack Web API client using a bot token (xoxb-...). One credential can post
 * to any channel the bot is a member of and DM any workspace member —
 * unlike incoming webhooks, which are locked to a single channel each.
 */
class SlackBotService
{
    public function configured(): bool
    {
        return filled(config('services.slack_bot.token'));
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
        $token = config('services.slack_bot.token');

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
