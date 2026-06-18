<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrackingSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'client_id',
        'upwork_profile_id',
        'work_type',
        'task_note',
        'started_at',
        'stopped_at',
        'last_heartbeat_at',
        'total_seconds',
        'activity_percent',
        'status',
        'source',
        'device_name',
        'platform',
        'app_version',
        'client_uuid',
        'health_alerted_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'health_alerted_at' => 'datetime',
        'total_seconds' => 'integer',
        'activity_percent' => 'integer',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_ABANDONED = 'abandoned';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function upworkProfile()
    {
        return $this->belongsTo(UpworkProfile::class);
    }

    public function screenshots()
    {
        return $this->hasMany(TrackingScreenshot::class);
    }

    public function activitySamples()
    {
        return $this->hasMany(TrackingActivitySample::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
