<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal Claude API client for AI summaries. Calls the Messages API over
 * plain HTTP (vendor/ is untracked on this hosting, so the official SDK
 * can't ship reliably through the git deploy — swap to anthropic-ai/sdk if
 * that ever changes). Dormant until ANTHROPIC_API_KEY is set on the
 * Developer page; every caller must tolerate null responses.
 */
class AnthropicService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    public function configured(): bool
    {
        return filled(config('services.anthropic.api_key'));
    }

    /**
     * One-shot completion. Returns the assistant text, or null when the key
     * is missing or the request fails — AI flavour must never break the
     * feature it decorates.
     */
    public function complete(string $system, string $userMessage, int $maxTokens = 600): ?string
    {
        return $this->chat([['role' => 'user', 'content' => $userMessage]], $system, $maxTokens);
    }

    /**
     * Multi-turn conversation. $messages alternate user/assistant roles,
     * most recent last. Returns the assistant text or null on failure.
     */
    public function chat(array $messages, string $system, int $maxTokens = 1000): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.anthropic.api_key'),
                'anthropic-version' => self::API_VERSION,
            ])->timeout(45)->post(self::API_URL, [
                'model' => config('services.anthropic.model', 'claude-opus-4-8'),
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => $messages,
            ]);

            if (! $response->successful()) {
                Log::warning('Anthropic API error', ['status' => $response->status(), 'body' => $response->body()]);

                return null;
            }

            $text = collect($response->json('content', []))
                ->where('type', 'text')
                ->pluck('text')
                ->implode("\n");

            return $text !== '' ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::warning('Anthropic API request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Manager-style narrative for the daily Slack digest. Null when AI is
     * not configured — the digest then sends exactly as before.
     */
    public function digestNarrative(array $payload): ?string
    {
        if (! $this->configured() || empty($payload['rows'])) {
            return null;
        }

        $lines = collect($payload['rows'])->map(fn (array $row) => sprintf(
            '%s: %s tracked, %d%% activity, top client: %s, top app: %s',
            $row['name'],
            $this->hoursLabel((int) $row['total_seconds']),
            $row['activity_percent'],
            $row['top_client'] ?: 'none',
            $row['top_app'] ?: 'none',
        ))->implode("\n");

        $system = 'You write a 3-4 sentence summary of a small agency team\'s tracked work day for a Slack digest. '
            .'Plain text only (no markdown headers or bullet lists). Mention standout contributors and the dominant '
            .'client work, and flag anything unusual (very low activity, very short days) in a neutral, factual tone. '
            .'Do not invent information.';

        $user = "Date: {$payload['range']['label']}\n"
            ."Team totals: {$payload['totals']['people']} people, "
            .$this->hoursLabel((int) $payload['totals']['total_seconds'])." tracked, "
            ."{$payload['totals']['avg_activity']}% average activity.\n\n"
            ."Per person:\n{$lines}";

        return $this->complete($system, $user, 400);
    }

    /**
     * Manager-style summary of one person's tracked day for the Timeline
     * "Summarize this day" button. Null when AI is unconfigured or the call
     * fails.
     */
    public function daySummary(string $userName, array $payload): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $sessions = collect($payload['sessions'] ?? [])->map(fn (array $s) => sprintf(
            '%s to %s: %s (%s, activity %d%%)',
            substr((string) $s['started_at'], 11, 5),
            $s['stopped_at'] ? substr((string) $s['stopped_at'], 11, 5) : 'ongoing',
            $s['client_name'] ?: ($s['task_note'] ?: 'untracked work'),
            $this->hoursLabel((int) ($s['day_seconds'] ?? $s['total_seconds'])),
            (int) ($s['activity_percent'] ?? 0),
        ))->implode("\n");

        $apps = collect($payload['day_apps'] ?? [])->take(6)
            ->map(fn (array $a) => $a['name'].' ('.$this->hoursLabel((int) $a['total_seconds']).')')
            ->implode(', ');
        $urls = collect($payload['day_urls'] ?? [])->take(6)
            ->map(fn (array $u) => $u['name'].' ('.$this->hoursLabel((int) $u['total_seconds']).')')
            ->implode(', ');

        $system = 'You summarize one employee\'s tracked work day for their manager in 3-4 plain sentences. '
            .'Factual and neutral: what they worked on, when, where the time went (apps/sites), and anything '
            .'unusual such as very low activity or long idle stretches. No markdown, no bullet lists, '
            .'never invent information that is not in the data.';

        $user = "Employee: {$userName}\nDay: {$payload['day_label']}\n"
            .'Total tracked: '.$this->hoursLabel((int) ($payload['totals']['day'] ?? 0))."\n"
            ."Sessions:\n".($sessions !== '' ? $sessions : 'none')."\n"
            .'Top apps: '.($apps !== '' ? $apps : 'none')."\n"
            .'Top sites: '.($urls !== '' ? $urls : 'none');

        return $this->complete($system, $user, 400);
    }

    /**
     * "109h 50m" — gmdate() wraps at 24h, which silently understated team
     * totals in the prompt (109h became 13h).
     */
    private function hoursLabel(int $seconds): string
    {
        return intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
    }
}
