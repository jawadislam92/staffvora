<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class TrackingScreenshot extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tracking_session_id',
        'user_id',
        'captured_at',
        'image_path',
        'thumbnail_path',
        'file_size',
        'width',
        'height',
        'activity_percent',
        'keyboard_count',
        'mouse_count',
        'active_app',
        'active_window_title',
        'url_domain',
        'is_flagged',
        'flag_reason',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'is_flagged' => 'boolean',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'activity_percent' => 'integer',
        'keyboard_count' => 'integer',
        'mouse_count' => 'integer',
    ];

    public function trackingSession()
    {
        return $this->belongsTo(TrackingSession::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    private function screenshotsOnS3(): bool
    {
        return config('filesystems.disks.screenshots.driver') === 's3';
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        // On S3, hand the browser a short-lived presigned URL so it fetches
        // straight from S3 — no PHP proxy and no per-image exists() round trip
        // (which would be a network call each). On local disk, keep the
        // streamed route (cheap exists() check on local files).
        if ($this->screenshotsOnS3()) {
            return Storage::disk('screenshots')->temporaryUrl($this->image_path, now()->addMinutes(20));
        }

        return Storage::disk('screenshots')->exists($this->image_path)
            ? route('monitoring.screenshots.image', ['screenshot' => $this->id])
            : null;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_path) {
            return $this->image_url;
        }

        if ($this->screenshotsOnS3()) {
            return Storage::disk('screenshots')->temporaryUrl($this->thumbnail_path, now()->addMinutes(20));
        }

        return route('monitoring.screenshots.thumbnail', ['screenshot' => $this->id]);
    }
}
