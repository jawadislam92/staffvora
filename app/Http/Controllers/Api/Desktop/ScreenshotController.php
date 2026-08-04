<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Http\Requests\Desktop\UploadScreenshotRequest;
use App\Jobs\GenerateScreenshotThumbnail;
use App\Models\MonitoringSetting;
use App\Models\TrackingScreenshot;
use App\Models\TrackingSession;
use App\Services\TrackingSessionService;
use App\Support\ImageGuard;
use App\Support\WebDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ScreenshotController extends DesktopController
{
    /**
     * Total pixel budget for what we will STORE.
     *
     * Per-axis bounds cannot express this and are harmful in both directions:
     * 7999x7999 is 64 Mpx and passes any "max 8000 wide" rule, while an honest
     * triple-4K capture (11520x2160 = 24.9 Mpx) FAILS one at 62% of the budget —
     * which is how a monitoring product ends up silently losing evidence from
     * exactly the multi-monitor workstations it most needs to watch.
     *
     * Note this is not a memory bound and nothing may decode on the strength of
     * it — see ImageGuard, and GenerateScreenshotThumbnail for the decode side.
     */
    private const MAX_PIXELS = ImageGuard::MAX_PIXELS;

    /**
     * Formats we are willing to store. Mirrors the `mimes:` rule on
     * UploadScreenshotRequest, but decided from the DECODED HEADER rather than
     * from anything the client said.
     */
    private const ALLOWED_FORMATS = ['jpg', 'png', 'webp'];

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

        // Single header-only inspection that answers BOTH questions before
        // anything decodes or stores the upload:
        //
        //  - Is it a decompression bomb? getimagesize reads the header only, so
        //    it is safe on hostile input; GenerateScreenshotThumbnail (which
        //    runs inline while QUEUE_CONNECTION is sync) would otherwise
        //    allocate ~4 bytes per pixel and take the worker — 60 worker kills a
        //    minute is enough to empty a shared FPM pool for every tenant on the
        //    box.
        //  - What is it REALLY? The answer, not the client's filename, decides
        //    the stored extension below.
        $image = ImageGuard::inspect($file->getRealPath(), self::MAX_PIXELS, self::ALLOWED_FORMATS);

        if ($image === null) {
            $raw = @getimagesize($file->getRealPath());

            Log::warning('Rejected oversized, unreadable or non-raster screenshot upload.', [
                'user_id' => $user->id,
                'tracking_session_id' => $session->id,
                'width' => $raw[0] ?? null,
                'height' => $raw[1] ?? null,
                'declared_mime' => $raw['mime'] ?? null,
                'bytes' => $file->getSize(),
            ]);

            // Explicit and machine-readable: the agent buffers captures and
            // needs to know the difference between "retry later" and "this one
            // will never be accepted, stop holding it". A silent drop is what
            // makes "the screenshots just stopped" undiagnosable.
            $rawPixels = (float) ($raw[0] ?? 0) * (float) ($raw[1] ?? 0);

            return response()->json([
                'message' => $rawPixels > self::MAX_PIXELS
                    ? 'The screenshot is '.($raw[0] ?? '?').'x'.($raw[1] ?? '?').', which is over the '
                        .round(self::MAX_PIXELS / 1_000_000).' megapixel limit.'
                    : 'The screenshot is not a readable '.implode('/', self::ALLOWED_FORMATS).' image.',
                'code' => 'screenshot_too_large',
                'max_pixels' => self::MAX_PIXELS,
                'width' => $raw[0] ?? null,
                'height' => $raw[1] ?? null,
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

        // The extension comes from the VALIDATED CONTENT, never from the client.
        // The desktop agent is attacker-controlled (every employee holds a
        // token), and the stored key's extension is what the S3 adapter turns
        // into the object's ContentType: Flysystem writes the upload as a
        // stream, so its mime detector cannot sniff the buffer and falls back to
        // the extension. Letting the client send `payload.html` for a genuine
        // PNG that carries <script> in a tEXt chunk therefore had the app serve
        // it back to the manager as text/html from our own origin — stored XSS
        // inside the manager's logged-in session.
        //
        // ContentType is pinned explicitly for the same reason: it removes the
        // extension→mime inference entirely instead of relying on it landing on
        // the right answer.
        $filename = $capturedAt->format('His').'_'.uniqid().'.'.$image['extension'];
        $path = $file->storeAs($dir, $filename, [
            'disk' => 'screenshots',
            'ContentType' => $image['mime'],
        ]);

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
