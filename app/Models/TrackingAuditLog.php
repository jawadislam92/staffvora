<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingAuditLog extends Model
{
    protected $fillable = [
        'tracking_session_id',
        'tracking_screenshot_id',
        'subject_user_id',
        'actor_user_id',
        'action',
        'event_date',
        'old_value',
        'new_value',
        'reason',
    ];

    protected $casts = [
        'event_date' => 'date',
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrackingSession::class, 'tracking_session_id');
    }

    public function screenshot(): BelongsTo
    {
        return $this->belongsTo(TrackingScreenshot::class, 'tracking_screenshot_id');
    }

    /**
     * Convenience helper to record an audit row.
     *
     * @param  array<string, mixed>  $data
     */
    public static function record(array $data): self
    {
        return static::create($data);
    }
}
