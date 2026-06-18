<?php

namespace App\Http\Controllers;

use App\Models\AttendanceClockCheck;
use App\Services\AttendanceCloser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Receives Slack interactive button clicks (the "still working?" check).
 * Verifies the request really came from Slack via the signing secret, then
 * either snoozes the check ("yes, still working") or clocks the person out
 * ("no, clock me out"), and rewrites the original Slack message to confirm.
 */
class SlackInteractionController extends Controller
{
    /** How long a "yes, still working" keeps them clocked in before we ask again. */
    private const SNOOZE_HOURS = 2;

    public function handle(Request $request, AttendanceCloser $closer)
    {
        if (! $this->verify($request)) {
            return response('invalid signature', 403);
        }

        $payload = json_decode((string) $request->input('payload'), true);
        $action = $payload['actions'][0] ?? null;
        $responseUrl = $payload['response_url'] ?? null;
        if (! $action) {
            return response('', 200);
        }

        $check = AttendanceClockCheck::find($action['value'] ?? null);
        $now = Carbon::now('Asia/Karachi');

        if (! $check) {
            $this->reply($responseUrl, ':information_source: This check is no longer active.');

            return response('', 200);
        }

        if ($action['action_id'] === 'attendance_still_working') {
            if ($check->resolved_at) {
                $this->reply($responseUrl, ':information_source: You\'re already clocked out for this session.');

                return response('', 200);
            }
            $check->update([
                'confirmed_until' => $now->copy()->addHours(self::SNOOZE_HOURS),
                'last_response_at' => $now,
            ]);
            $this->reply($responseUrl, sprintf(
                ':white_check_mark: Thanks — keeping you clocked in. I\'ll check again in about %dh.',
                self::SNOOZE_HOURS
            ));

            return response('', 200);
        }

        if ($action['action_id'] === 'attendance_clock_out') {
            $entry = $closer->close(
                $check->user_id,
                $now,
                'Clocked out via Slack — confirmed finished working.'
            );
            $check->update([
                'resolved_at' => $now,
                'resolution' => 'clocked_out_via_slack',
                'last_response_at' => $now,
            ]);
            $this->reply($responseUrl, $entry
                ? ':stop_button: Done — clocked you out at '.$now->format('g:i A').'. Have a good one!'
                : ':information_source: You were already clocked out.');

            return response('', 200);
        }

        return response('', 200);
    }

    /** Replace the original message (removes the buttons) via Slack's response_url. */
    private function reply(?string $responseUrl, string $text): void
    {
        if (! $responseUrl) {
            return;
        }

        Http::timeout(8)->post($responseUrl, [
            'replace_original' => true,
            'text' => $text,
            'blocks' => [[
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $text],
            ]],
        ]);
    }

    /** HMAC verification per Slack's signing-secret scheme (with replay guard). */
    private function verify(Request $request): bool
    {
        $secret = config('services.slack_bot.signing_secret');
        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');

        if (! $secret || ! $timestamp || ! $signature) {
            return false;
        }

        // Reject anything older than 5 minutes (replay protection).
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $base = 'v0:'.$timestamp.':'.$request->getContent();
        $computed = 'v0='.hash_hmac('sha256', $base, $secret);

        return hash_equals($computed, $signature);
    }
}
