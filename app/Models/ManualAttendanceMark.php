<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ManualAttendanceMark extends Model
{
    use BelongsToOrganization;

    use HasFactory;

    protected $fillable = [
        'user_id',
        'marked_by_user_id',
        'attendance_date',
        'status_code',
        'note',
    ];

    protected $casts = [
        'attendance_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function markedBy()
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }
}
