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

    /** Higher rank = more authority. Support reads + helps; Admin manages; Owner runs staff. */
    const ROLE_RANK = ['support' => 1, 'admin' => 2, 'owner' => 3];

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

    public function rank(): int
    {
        return self::ROLE_RANK[$this->role] ?? 0;
    }

    /** Admin+ — write tenants (suspend/billing/entitlements), plans, website, developer. */
    public function canManagePlatform(): bool
    {
        return $this->rank() >= self::ROLE_RANK['admin'];
    }

    public function invitationsSent()
    {
        return $this->hasMany(PlatformInvitation::class, 'invited_by');
    }
}
