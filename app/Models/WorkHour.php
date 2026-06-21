<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkHour extends Model
{
    use BelongsToOrganization;

    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'started_at',
        'ended_at',
        'hours',
        'description',
        'work_type',
        'client_id',
        'tracker',
        'tracking_session_id',
        'source',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected $appends = ['time_range'];

    /**
     * A human "2:08 PM – 3:05 PM" label for ranged manual entries, formatted
     * server-side from the stored wall-clock so the browser timezone can't
     * shift it. Null for duration-only entries.
     */
    public function getTimeRangeAttribute(): ?string
    {
        if (! $this->started_at || ! $this->ended_at) {
            return null;
        }

        return $this->started_at->format('g:i A').' – '.$this->ended_at->format('g:i A');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
