<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Desktop\ActivityBatchRequest;
use App\Models\TrackingActivitySample;
use App\Models\TrackingSession;
use App\Support\BusinessTime;
use App\Support\WebDomain;
use Illuminate\Http\JsonResponse;

class ActivityController extends Controller
{
    public function batch(ActivityBatchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $session = TrackingSession::findOrFail($data['tracking_session_id']);
        abort_unless($session->user_id === $user->id, 403);

        $now = now();
        $rows = collect($data['samples'])->map(fn (array $sample) => [
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => BusinessTime::fromClient($sample['captured_at']),
            'keyboard_count' => $sample['keyboard_count'] ?? 0,
            'mouse_count' => $sample['mouse_count'] ?? 0,
            'idle_seconds' => $sample['idle_seconds'] ?? 0,
            'active_app' => $sample['active_app'] ?? null,
            'active_window_title' => $sample['active_window_title'] ?? null,
            'url_domain' => WebDomain::normalize($sample['url_domain'] ?? null),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        TrackingActivitySample::insert($rows);

        return response()->json([
            'accepted' => count($rows),
        ], 201);
    }
}
