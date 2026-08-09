<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Models\DesktopConsent;
use App\Services\TrackingSessionService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The monitoring-consent gate for the desktop agent.
 *
 * WHY THIS EXISTS
 * The agent can record time, activity, screenshots and app/website names. In
 * most jurisdictions monitoring an employee is lawful only where the person was
 * told what is captured and actively agreed. Installing that silently — which
 * is what the previous product did — is the single most expensive mistake this
 * software can make for a customer. So the agent asks, per user, on first
 * launch, with an UNTICKED box, and the answer is recorded here.
 *
 * WHY THE SERVER OWNS THE DOCUMENT
 * `GET` advertises the version the workspace must currently have accepted. When
 * the terms change the constant moves, and every installed agent re-asks on its
 * next launch — no new build, no migration of old consents onto new terms.
 *
 * SECURITY — the agent runs on the employee's own machine and is
 * attacker-controllable, so:
 *   - the user and the tenant come from the Sanctum token, never the body. A
 *     client cannot record a consent "for" anyone else, and a supplied
 *     user_id / organization_id is not merely ignored, it is not read at all;
 *   - the accepted version must EQUAL the required version. A client claiming
 *     to have accepted some other string gets 409 and the required version
 *     back, rather than a row that looks like compliance and is not;
 *   - `accepted` must be literally true — there is no code path that writes a
 *     row for a declined or absent answer;
 *   - the client clock is a claim: it is clamped and stored in `client_at`,
 *     separately from `accepted_at`, which is the server clock and the only
 *     timestamp anyone should be shown in a dispute;
 *   - writes de-duplicate on (org, user, version, device) and the route is
 *     throttled, so a tampered agent cannot flood an append-only legal table.
 *
 * NOT entitlement-gated, deliberately: consent is a legal precondition of using
 * the product at all, not a paid feature. A workspace on the cheapest plan must
 * still be able to obtain and prove it.
 */
class ConsentController extends DesktopController
{
    /**
     * How far back a claimed acceptance time may reach. Wider than the tracker's
     * 24h backdate window because an agent can legitimately sit offline for days
     * with a locally recorded consent waiting to sync — and the recorded value
     * is only ever the employee's claim; `accepted_at` is the server's.
     */
    private const MAX_BACKDATE_DAYS = 30;

    /**
     * Rows one user may accumulate. De-duplication already bounds this to
     * roughly (devices x versions); the cap is the backstop for an agent that
     * invents a new device_name on every request.
     */
    private const MAX_ROWS_PER_USER = 200;

    /**
     * What the agent must show, and whether this user has already agreed to it.
     *
     * The agent calls this on every launch. It is intentionally cheap and
     * intentionally NOT cached across users — "has this person consented" on a
     * shared PC must never be answered from another person's result.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $accepted = DesktopConsent::query()
            ->where('user_id', $user->id)
            ->where('document_version', DesktopConsent::CURRENT_VERSION)
            ->orderByDesc('accepted_at')
            ->first();

        return response()->json([
            'document' => DesktopConsent::documentPayload(),
            'accepted' => $accepted !== null,
            'accepted_at' => $accepted?->accepted_at?->toIso8601String(),
            'accepted_version' => $accepted?->document_version,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Record an acceptance. Idempotent per (user, version, device).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // `accepted` must be literally true. 'accepted' is Laravel's rule
            // for exactly this: a tick box that was really ticked.
            'accepted' => ['required', 'accepted'],
            'version' => ['required', 'string', 'max:40'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:32'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'at' => ['nullable', 'string', 'max:40'],
        ]);

        // Exact match, never version_compare(). "Close enough" is how an old
        // consent silently comes to stand for new terms.
        if ($data['version'] !== DesktopConsent::CURRENT_VERSION) {
            return response()->json([
                'message' => 'These terms are out of date. Please review and accept the current version.',
                'code' => 'consent_version_mismatch',
                'document' => DesktopConsent::documentPayload(),
            ], 409);
        }

        $user = $request->user();

        // Server-derived. EnsureTenantBound:sanctum has already proven the
        // token's user belongs to the bound tenant.
        $organizationId = $user->organization_id ?? app(CurrentOrganization::class)->id();

        $device = $data['device_name'] ?? null;

        $existing = DesktopConsent::query()
            ->where('user_id', $user->id)
            ->where('document_version', DesktopConsent::CURRENT_VERSION)
            ->where('device_name', $device)
            ->first();

        if ($existing) {
            return response()->json([
                'recorded' => true,
                'duplicate' => true,
                'id' => $existing->id,
                'version' => $existing->document_version,
                'accepted_at' => $existing->accepted_at?->toIso8601String(),
            ]);
        }

        // Backstop against an agent inventing device names forever. Rejecting
        // would strand a real employee behind the consent gate, so the ceiling
        // reports success against their most recent row instead — the consent
        // is already on file, which is all this endpoint is for.
        $rows = DesktopConsent::query()->where('user_id', $user->id)->count();

        if ($rows >= self::MAX_ROWS_PER_USER) {
            $latest = DesktopConsent::query()
                ->where('user_id', $user->id)
                ->where('document_version', DesktopConsent::CURRENT_VERSION)
                ->orderByDesc('accepted_at')
                ->first();

            if ($latest) {
                return response()->json([
                    'recorded' => true,
                    'duplicate' => true,
                    'id' => $latest->id,
                    'version' => $latest->document_version,
                    'accepted_at' => $latest->accepted_at?->toIso8601String(),
                ]);
            }
        }

        $now = now();

        $consent = DesktopConsent::create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'document_version' => DesktopConsent::CURRENT_VERSION,
            'disclosure_hash' => DesktopConsent::disclosureHash(),
            'terms_url' => DesktopConsent::TERMS_URL,
            'privacy_url' => DesktopConsent::PRIVACY_URL,
            'source' => DesktopConsent::SOURCE_DESKTOP_FIRST_RUN,
            'device_name' => $device,
            'platform' => $data['platform'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'client_at' => isset($data['at'])
                ? TrackingSessionService::clampClientTime(
                    $data['at'],
                    $now->copy()->subDays(self::MAX_BACKDATE_DAYS),
                    $now->copy()->addSeconds(TrackingSessionService::CLOCK_SKEW_SECONDS),
                )
                : null,
            'accepted_at' => $now,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'recorded' => true,
            'duplicate' => false,
            'id' => $consent->id,
            'version' => $consent->document_version,
            'accepted_at' => $consent->accepted_at?->toIso8601String(),
        ], 201);
    }
}
