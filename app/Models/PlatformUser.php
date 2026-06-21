<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An operator/landlord staff account (Owner / Admin / Support). Separate from
 * the customer `User` model and the `web` guard — authenticated only on the
 * platform portal via the `platform` guard. No tenant; invite-only.
 */
class PlatformUser extends Authenticatable
{
    use Notifiable;

    const ROLES = ['owner', 'admin', 'support'];

    protected $fillable = ['name', 'email', 'password', 'role', 'invited_by'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'last_login_at' => 'datetime',
    ];

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /** Owner or Admin — full management rights short of owner-only staff control. */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    public function isSupport(): bool
    {
        return $this->role === 'support';
    }
}
