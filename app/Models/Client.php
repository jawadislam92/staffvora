<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'tags',
        'work_type',
        'upwork_profile_id', // Keep for backward compatibility during transition
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function workHours()
    {
        return $this->hasMany(WorkHour::class);
    }

    public function upworkProfile()
    {
        return $this->belongsTo(UpworkProfile::class);
    }

    public function upworkProfiles()
    {
        return $this->belongsToMany(UpworkProfile::class);
    }

    // Define available work types
    public static function getWorkTypes()
    {
        return [
            'tracker_manual' => 'Tracker/Manual Time',
            'fixed' => 'Fixed Client',
            'outside_of_upwork' => 'Outside of Upwork',
        ];
    }

    // Check if upwork profile is required for given work type
    public static function isProfileRequired($workType)
    {
        return in_array($workType, ['tracker_manual', 'fixed']);
    }
}
