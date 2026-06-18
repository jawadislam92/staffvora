<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceClockCheck extends Model
{
    protected $fillable = [
        'user_id',
        'clock_in_id',
        'prompts_sent',
        'last_prompted_at',
        'confirmed_until',
        'last_response_at',
        'resolved_at',
        'resolution',
    ];

    protected $casts = [
        'last_prompted_at' => 'datetime',
        'confirmed_until' => 'datetime',
        'last_response_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
