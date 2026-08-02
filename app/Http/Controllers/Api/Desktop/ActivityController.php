<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Http\Requests\Desktop\ActivityBatchRequest;
use App\Models\TrackingActivitySample;
use App\Models\TrackingSession;
use App\Services\TrackingSessionService;
use App\Support\WebDomain;
use Illuminate\Http\JsonResponse;

class ActivityController extends DesktopController
{
    public function batch(ActivityBatchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $session = TrackingSession::findOrFail($data['tracking_session_id']);
        abort_unless($session->user_id === $user->id, 403);

        $now = now();
        $floor = now()->subSeconds(TrackingSessionService::MAX_BACKDATE_SECONDS);
        $ceiling = now()->addSeconds(TrackingSessionService::CLOCK_SKEW_SECONDS);

        $rows = collect($data['samples'])->map(fn (array $sample) => [
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            // Same unclamped-client-timestamp hole as screenshots: activity
            // samples are the corroborating evidence for tracked time, so an
            // agent that can date them freely can manufacture a workday.
            'captured_at' => TrackingSessionService::clampClientTime($sample['captured_at'], $floor, $ceiling),
            'keyboard_count' => $sample['keyboard_count'] ?? 0,
            'mouse_count' => $sample['mouse_count'] ?? 0,
            'idle_seconds' => $sample['idle_seconds'] ?? 0,
            'active_app' => $sample['active_app'] ?? null,
            'active_window_title' => $sample['active_window_title'] ?? null,
            'url_domain' => WebDomain::normalize($sample['url_domain'] ?? null),
            // insert() bypasses the BelongsToOrganization creating hook, so the
            // tenant has to be stamped by hand or these rows are orphaned.
            'organization_id' => $session->organization_id ?? $user->organization_id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // Bounded to 200 rows by the form request; chunked anyway so the
        // placeholder count can never approach the driver's parameter limit.
        foreach (array_chunk($rows, 100) as $chunk) {
            TrackingActivitySample::insert($chunk);
        }

        return response()->json([
            'accepted' => count($rows),
        ], 201);
    }
}
