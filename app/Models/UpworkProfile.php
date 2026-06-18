<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpworkProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function workHours()
    {
        return $this->hasMany(WorkHour::class, 'tracker', 'name');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function clients()
    {
        return $this->belongsToMany(Client::class);
    }
}
