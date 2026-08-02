<?php

namespace App\Http\Controllers;

use App\Models\AttendanceClockCheck;
use App\Models\MonitoringSetting;
use App\Models\User;
use App\Services\AttendanceCloser;
use App\Support\BusinessTime;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Receives Slack interactive button clicks (the "still working?" check).
 *
 * SECURITY MODEL — this route is deliberately unauthenticated (Slack posts it
 * server-to-server), so every trust boundary has to be re-established here:
 *
 *  1. AUTHENTICITY — the Slack signing-secret HMAC, with a replay window.
 *  2. TENANT — resolved from the STORED AttendanceClockCheck row, never from the
 *     request body. The lookup is explicitly unscoped (the scope would no-op
 *     anyway while unbound) and a row with no organization_id is refused rather
 *     than acted on. Everything after that runs inside runFor(), which (a)
 *     scopes every read, (b) stamps organization_id on the TimeEntry rows the
 *     closer writes — previously they landed NULL and were invisible to the
 *     owning workspace forever, leaving the person permanently "clocked in" —
 *     and (c) makes BusinessTime::tz() return the tenant's real timezone, so
 *     $now MUST be computed inside the closure, not before it.
 *  3. OWNERSHIP — the subject is re-resolved through the tenant-scoped User
 *     query, so a check pointing at a user outside the bound organization is
 *     refused; and the clicking Slack account is matched back to that user, so
 *     a replayed/edited payload carrying someone else's check id cannot clock a
 *     third party out.
 */
class SlackInteractionController extends Controller
{
    /** How long a "yes, still working" keeps them clocked in before we ask again. */
    private const SNOOZE_HOURS = 2;

    /** The only host we will ever post a response back to. */
    private const RESPONSE_HOST = 'hooks.slack.com';

    public function handle(Request $request, AttendanceCloser $closer)
    {
        if (! $this->verify($request)) {
            return response('invalid signature', 403);
        }

        $payload = json_decode((string) $request->input('payload'), true);
        if (! is_array($payload)) {
            return response('', 200);
        }

        $action = $payload['actions'][0] ?? null;
        $responseUrl = $this->safeResponseUrl($payload['response_url'] ?? null);

        if (! is_array($action) || ! isset($action['action_id'])) {
            return response('', 200);
        }

        // The button value is attacker-controllable. It is only ever used to
        // LOOK UP a row; the tenant comes from that row, and the row is then
        // re-validated inside the tenant below.
        $checkId = filter_var($action['value'] ?? null, FILTER_VALIDATE_INT);

        $check = $checkId
            ? AttendanceClockCheck::withoutOrganizationScope()->find($checkId)
            : null;

        // A NULL organization_id is not "unknown tenant, proceed anyway" — it is
        // a legacy/broken row. runFor() is not nullable either, so acting on it
        // would be a TypeError at best and an unscoped write at worst.
        if (! $check || ! $check->organization_id) {
            $this->reply($responseUrl, ':information_source: This check is no longer active.');

            return response('', 200);
        }

        return app(CurrentOrganization::class)->runFor(
            $check->organization_id,
            fn () => $this->handleForTenant($check, $payload, $action, $responseUrl, $closer)
        );
    }

    /**
     * Everything below runs with the tenant bound: reads are scoped and writes
     * are stamped with the owning organization_id.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $action
     */
    private function handleForTenant(
        AttendanceClockCheck $check,
        array $payload,
        array $action,
        ?string $responseUrl,
        AttendanceCloser $closer
    ) {
        // Scoped resolve: refuses to act on a user that does not belong to the
        // organization the check was filed under.
        $subject = User::find($check->user_id);

        if (! $subject) {
            Log::warning('Slack interaction refused: check subject is not in the check organization', [
                'check_id' => $check->id,
                'organization_id' => $check->organization_id,
                'user_id' => $check->user_id,
            ]);
            $this->reply($responseUrl, ':information_source: This check is no longer active.');

            return response('', 200);
        }

        if (! $this->clickerOwnsCheck($payload, $subject)) {
            Log::warning('Slack interaction refused: clicking Slack account does not own the check', [
                'check_id' => $check->id,
                'organization_id' => $check->organization_id,
                'slack_user_id' => $payload['user']['id'] ?? null,
            ]);
            $this->reply($responseUrl, ':no_entry: This attendance check belongs to someone else.');

            return response('', 200);
        }

        // MUST be inside the tenant binding: BusinessTime::tz() reads the bound
        // organization, so resolving $now earlier stores UTC times and tells the
        // employee the wrong clock-out time.
        $now = Carbon::now(BusinessTime::tz());

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
            // Idempotency: a double-click (or a Slack retry) must not run the
            // closer twice. The still-working branch already guarded this.
            if ($check->resolved_at) {
                $this->reply($responseUrl, ':information_source: You were already clocked out.');

                return response('', 200);
            }

            $entry = $closer->close(
                $subject->id,
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

    /**
     * Does the Slack account that clicked the button belong to the employee the
     * check is about?
     *
     * The check was delivered as a DM addressed by email (SlackBotService
     * ::dmBlocksByEmail), so the authoritative comparison is the clicker's Slack
     * email against the employee's account email. We resolve it with the
     * TENANT'S OWN bot token — never the platform token.
     *
     * Degradation is deliberate and asymmetric: an identified mismatch is always
     * a refusal, but an *unavailable* identity (workspace has not connected its
     * own bot, or the app lacks the users:read.email scope) falls through to
     * allow, because at that point the tenant binding above already confines any
     * damage to the workspace that owns the check and blocking would break the
     * feature for every workspace that has not granted the scope. Storing the
     * Slack user id on the check at send time would remove the call entirely —
     * see the deferred note.
     *
     * @param  array<string, mixed>  $payload
     */
    private function clickerOwnsCheck(array $payload, User $subject): bool
    {
        $slackUserId = $payload['user']['id'] ?? null;

        if (! is_string($slackUserId) || $slackUserId === '') {
            return false; // Slack always sends this; its absence is a forged payload.
        }

        $token = rescue(fn () => MonitoringSetting::current()->slack_bot_token, null, false);

        if (! filled($token)) {
            return true; // Cannot identify the clicker; see the docblock.
        }

        $body = rescue(fn () => Http::withToken($token)
            ->timeout(8)
            ->get('https://slack.com/api/users.info', ['user' => $slackUserId])
            ->json(), null, false);

        if (! is_array($body)) {
            return true; // Slack unreachable.
        }

        if (($body['ok'] ?? false) !== true) {
            // An id Slack does not know is a fabricated payload, not an outage.
            return ($body['error'] ?? null) !== 'user_not_found';
        }

        $email = $body['user']['profile']['email'] ?? null;

        if (! is_string($email) || $email === '') {
            return true; // users:read.email not granted.
        }

        return mb_strtolower(trim($email)) === mb_strtolower(trim((string) $subject->email));
    }

    /**
     * Slack's response_url comes from the request body, so it is attacker
     * controlled: an unvalidated one turns this endpoint into an SSRF relay that
     * will POST to any host with the app's own outbound credentials/network
     * position. Only Slack's documented hook host is accepted.
     */
    private function safeResponseUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? null) !== 'https' || $host !== self::RESPONSE_HOST) {
            Log::warning('Slack interaction: refusing to post to a non-Slack response_url', ['host' => $host]);

            return null;
        }

        return $url;
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
