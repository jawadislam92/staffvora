<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Models\DesktopDiagnostic;
use App\Services\TrackingSessionService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Receives the error / telemetry events the desktop tracker uploads, so
 * client-side problems (screenshot capture blocked by antivirus, uploads
 * failing, a crash on resume, a token that stopped working) are visible on the
 * server without anyone touching the employee's machine. Without this the first
 * symptom of a broken agent is an empty report at payroll time.
 *
 * SECURITY NOTES — the agent runs on the employee's own machine and is
 * attacker-controllable, so:
 *   - the tenant and the user come from the Sanctum token via
 *     EnsureTenantBound:sanctum + $request->user(); a client-supplied
 *     organization_id / user_id is ignored, never merged;
 *   - the batch size, every string length and the serialized context size are
 *     capped, and the whole request body is size-checked before validation;
 *   - `at` is a client clock reading, so it is clamped to the same backdate /
 *     skew window as screenshots and activity samples and stored separately from
 *     the server-set captured_at that retention runs against;
 *   - the route carries its own throttle (see routes/api.php) so one tampered
 *     agent cannot spend the whole tenant's ingest budget.
 *
 * Rows land in `desktop_diagnostics` under the 'desktop' category and are shown
 * on the operator Developer panel. Retention is enforced by
 * `monitoring:prune-screenshots`.
 */
class DiagnosticsController extends DesktopController
{
    /** Events accepted per request. */
    private const MAX_EVENTS = 50;

    /** Hard ceiling on the raw request body, checked before validation. */
    private const MAX_BODY_BYTES = 262144; // 256 KB

    public function store(Request $request): JsonResponse
    {
        if (strlen((string) $request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json([
                'message' => 'Diagnostics payload is too large.',
                'code' => 'payload_too_large',
            ], 413);
        }

        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_EVENTS],
            'events.*.level' => ['required', Rule::in(DesktopDiagnostic::LEVELS)],
            'events.*.event' => ['required', 'string', 'max:80'],
            'events.*.message' => ['nullable', 'string', 'max:2000'],
            'events.*.context' => ['nullable', 'array'],
            'events.*.at' => ['nullable', 'string', 'max:40'],
            'events.*.app_version' => ['nullable', 'string', 'max:32'],
            'events.*.platform' => ['nullable', 'string', 'max:32'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();

        // Server-derived, never from the body. EnsureTenantBound:sanctum has
        // already proven the token's user belongs to the bound tenant.
        $organizationId = $user->organization_id ?? app(CurrentOrganization::class)->id();

        $device = $data['device_name'] ?? null;
        $now = now();
        $floor = $now->copy()->subSeconds(TrackingSessionService::MAX_BACKDATE_SECONDS);
        $ceiling = $now->copy()->addSeconds(TrackingSessionService::CLOCK_SKEW_SECONDS);

        $rows = [];

        foreach ($data['events'] as $event) {
            $context = DesktopDiagnostic::boundContext($event['context'] ?? null);

            $rows[] = [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'category' => DesktopDiagnostic::CATEGORY_DESKTOP,
                'level' => $event['level'],
                'event' => $event['event'],
                'summary' => Str::limit(sprintf(
                    '[%s] %s%s — %s (%s)',
                    strtoupper($event['level']),
                    $event['event'],
                    $device ? ' @'.$device : '',
                    Str::limit((string) ($event['message'] ?? ''), 120),
                    $user->name,
                ), 250, ''),
                'message' => $event['message'] ?? null,
                'context' => $context === null ? null : json_encode($context),
                'device_name' => $device,
                'platform' => $event['platform'] ?? null,
                'app_version' => $event['app_version'] ?? null,
                'client_at' => isset($event['at'])
                    ? TrackingSessionService::clampClientTime($event['at'], $floor, $ceiling)
                    : null,
                'captured_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // One insert for the batch. insert() bypasses the BelongsToOrganization
        // creating hook, which is exactly why organization_id is stamped by hand
        // above — an unstamped row is invisible to its own tenant AND to the
        // retention sweep.
        DesktopDiagnostic::insert($rows);

        return response()->json(['stored' => count($rows)]);
    }
}
