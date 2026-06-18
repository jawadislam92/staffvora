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
        'hours',
        'description',
        'work_type',
        'client_id',
        'tracker',
        'tracking_session_id',
        'source',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
