<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Desktop\UploadScreenshotRequest;
use App\Jobs\GenerateScreenshotThumbnail;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Support\BusinessTime;
use App\Support\WebDomain;
use Illuminate\Http\JsonResponse;

class ScreenshotController extends Controller
{
    public function store(UploadScreenshotRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $session = TrackingSession::findOrFail($data['tracking_session_id']);
        abort_unless($session->user_id === $user->id, 403);

        $capturedAt = BusinessTime::fromClient($data['captured_at']) ?? now();
        $file = $request->file('image');

        $dir = sprintf(
            'screenshots/%d/%s/%d',
            $user->id,
            $capturedAt->format('Y-m-d'),
            $session->id,
        );

        $filename = $capturedAt->format('His').'_'.uniqid().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs($dir, $filename, 'screenshots');

        $screenshot = TrackingScreenshot::create([
            'tracking_session_id' => $session->id,
            'user_id' => $user->id,
            'captured_at' => $capturedAt,
            'image_path' => $path,
            'file_size' => $file->getSize(),
            'activity_percent' => $data['activity_percent'] ?? 0,
            'keyboard_count' => $data['keyboard_count'] ?? 0,
            'mouse_count' => $data['mouse_count'] ?? 0,
            'active_app' => $data['active_app'] ?? null,
            'active_window_title' => $data['active_window_title'] ?? null,
            'url_domain' => WebDomain::normalize($data['url_domain'] ?? null),
        ]);

        GenerateScreenshotThumbnail::dispatch($screenshot->id);

        return response()->json([
            'id' => $screenshot->id,
            'captured_at' => $screenshot->captured_at->toIso8601String(),
        ], 201);
    }
}
