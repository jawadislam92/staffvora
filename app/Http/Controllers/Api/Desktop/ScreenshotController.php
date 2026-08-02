<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Http\Requests\Desktop\UploadScreenshotRequest;
use App\Jobs\GenerateScreenshotThumbnail;
use App\Models\MonitoringSetting;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Services\TrackingSessionService;
use App\Support\WebDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ScreenshotController extends DesktopController
{
    /**
     * Total pixel budget. Per-axis bounds cannot express this — 7999x7999 is
     * 64 Mpx (~256 MB under GD) and passes any "max 8000 wide" rule. 40 Mpx
     * comfortably covers a dual-4K capture.
     */
    private const MAX_PIXELS = 40_000_000;

    /**
     * How stale a capture may claim to be. The desktop app buffers while
     * offline, but a screenshot arriving hours late is already implausible and
     * a wide window is what makes evidence plantable.
     */
    private const MAX_CAPTURE_AGE_SECONDS = 7200;

    public function store(UploadScreenshotRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $session = TrackingSession::findOrFail($data['tracking_session_id']);
        abort_unless($session->user_id === $user->id, 403);

        // captured_at is chosen by the client. Unclamped it lets an employee
        // back-date or forward-date the monitoring record — the evidentiary
        // artifact an enterprise customer relies on in a dispute — and fan the
        // storage path out across fabricated dates.
        $capturedAt = TrackingSessionService::clampClientTime(
            $data['captured_at'],
            now()->subSeconds(self::MAX_CAPTURE_AGE_SECONDS),
            now()->addSeconds(TrackingSessionService::CLOCK_SKEW_SECONDS),
        );

        $file = $request->file('image');

        // Refuse decompression bombs BEFORE anything decodes the image.
        // getimagesize reads the header only, so it is safe on hostile input;
        // GenerateScreenshotThumbnail (which runs inline while QUEUE_CONNECTION
        // is sync) would otherwise allocate ~4 bytes per pixel and take the
        // worker — 60 worker kills a minute is enough to empty a shared FPM pool
        // for every tenant on the box.
        $dimensions = @getimagesize($file->getRealPath());

        if ($dimensions === false || ($dimensions[0] * $dimensions[1]) > self::MAX_PIXELS) {
            Log::warning('Rejected oversized or unreadable screenshot upload.', [
                'user_id' => $user->id,
                'tracking_session_id' => $session->id,
                'width' => $dimensions[0] ?? null,
                'height' => $dimensions[1] ?? null,
                'bytes' => $file->getSize(),
            ]);

            return response()->json([
                'message' => 'The screenshot is too large to process.',
                'code' => 'screenshot_too_large',
            ], 422);
        }

        $organization = $user->organization;
        $effectiveSettings = MonitoringSetting::current()->effectiveForUser($user);
        $configuredRate = (int) ($effectiveSettings['screenshots_per_hour'] ?? 0);
        $planCap = $organization?->entitlement('max_screenshots_per_hour');
        $hourlyLimit = $planCap === null
            ? $configuredRate
            : min($configuredRate, (int) $planCap);

        if (! ($effectiveSettings['capture_enabled'] ?? false) || $hourlyLimit < 1) {
            return response()->json([
                'message' => 'Screenshot capture is disabled for this workspace.',
                'code' => 'monitoring_disabled',
            ], 403);
        }

        // Count against SERVER-observed receipt time (created_at), never the
        // client's captured_at: incrementing captured_at by an hour per upload
        // made this counter read 0 forever, so both the plan entitlement and the
        // workspace setting were unenforceable and one agent could push tens of
        // thousands of 8 MB images a day into the tenant's bucket.
        // The captured_at floor is only there so the (user_id, captured_at)
        // index can serve the query — captured_at is clamped above, so any row
        // created in the last hour is well inside a one-day window.
        $screenshotsThisHour = TrackingScreenshot::query()
            ->where('user_id', $user->id)
            ->where('captured_at', '>=', now()->subDay())
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($screenshotsThisHour >= $hourlyLimit) {
            return response()->json([
                'message' => "The {$hourlyLimit} screenshots-per-hour limit has been reached.",
                'code' => 'screenshot_limit_reached',
                'limit' => $hourlyLimit,
            ], 429);
        }

        $activityAllowed = ! $organization || $organization->can('activity_tracking');
        $appMetadataAllowed = ! $organization || $organization->can('app_url_tracking');

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
            'activity_percent' => $activityAllowed ? ($data['activity_percent'] ?? 0) : 0,
            'keyboard_count' => $activityAllowed ? ($data['keyboard_count'] ?? 0) : 0,
            'mouse_count' => $activityAllowed ? ($data['mouse_count'] ?? 0) : 0,
            'active_app' => $appMetadataAllowed ? ($data['active_app'] ?? null) : null,
            'active_window_title' => $appMetadataAllowed ? ($data['active_window_title'] ?? null) : null,
            'url_domain' => $appMetadataAllowed ? WebDomain::normalize($data['url_domain'] ?? null) : null,
        ]);

        GenerateScreenshotThumbnail::dispatch($screenshot->id);

        return response()->json([
            'id' => $screenshot->id,
            'captured_at' => $screenshot->captured_at->toIso8601String(),
        ], 201);
    }
}
