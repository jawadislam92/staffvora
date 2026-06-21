<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A pending invite to the operator portal. The Owner creates one (email + role);
 * accepting it (set a name + password) provisions a PlatformUser with that role.
 * Single-use and time-boxed.
 */
class PlatformInvitation extends Model
{
    protected $fillable = ['email', 'role', 'token', 'invited_by', 'accepted_at', 'expires_at'];

    protected $casts = [
        'accepted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public static function mint(string $email, string $role, ?int $invitedBy): self
    {
        return self::create([
            'email' => $email,
            'role' => $role,
            'token' => Str::random(64),
            'invited_by' => $invitedBy,
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function inviter()
    {
        return $this->belongsTo(PlatformUser::class, 'invited_by');
    }

    public function acceptUrl(): string
    {
        return 'https://'.config('platform.host').'/invite/'.$this->token;
    }
}
