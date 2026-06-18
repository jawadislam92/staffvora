<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrackingActivitySample extends Model
{
    use HasFactory;

    protected $fillable = [
        'tracking_session_id',
        'user_id',
        'captured_at',
        'keyboard_count',
        'mouse_count',
        'idle_seconds',
        'active_app',
        'active_window_title',
        'url_domain',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'keyboard_count' => 'integer',
        'mouse_count' => 'integer',
        'idle_seconds' => 'integer',
    ];

    public function trackingSession()
    {
        return $this->belongsTo(TrackingSession::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
