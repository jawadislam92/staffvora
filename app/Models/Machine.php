<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One PC the desktop agent reports compliance inventory from, identified by
 * (organization_id, user_id, device_name).
 *
 * Tenant-owned: the OrganizationScope restricts reads to the active workspace
 * and organization_id is stamped on create. The ingest derives the tenant and
 * the user from the Sanctum token — device_name is the ONLY part of a machine's
 * identity the client supplies, and the number of distinct devices one user may
 * register is capped so a tampered agent cannot mint rows forever.
 */
class Machine extends Model
{
    use BelongsToOrganization;
    use HasFactory;

    /** Report kinds the ingest accepts. */
    public const KINDS = ['extensions', 'programs', 'processes', 'network'];

    /**
     * Kinds stored in FULL (every item), because the dashboard browses them:
     * "what is Ali running?" and "who has this extension?".
     */
    public const FULL_INVENTORY_KINDS = ['extensions', 'programs'];

    /**
     * Kinds where only blocklist HITS are stored. A running-process list is
     * enormous and changes every minute; the source's dashboard already
     * excluded processes and network from the browsable inventory because they
     * are "huge and transient… already covered by the flagged-tools list", so
     * persisting them in full is cost with no read path.
     */
    public const FINDINGS_ONLY_KINDS = ['processes', 'network'];

    /**
     * Agents older than this could not report browser extensions at all.
     *
     * This is the STALE-AGENT WARNING the source had to add: without it a
     * machine running an ancient agent reports an empty extension list and the
     * dashboard shows it as CLEAN, which is the opposite of the truth. Such a
     * machine must read as "unknown".
     */
    public const MIN_AGENT_VERSION = '0.4.6';

    /** How many distinct devices one user may register (anti row-flood). */
    public const MAX_DEVICES_PER_USER = 25;

    protected $table = 'machines';

    protected $fillable = [
        'organization_id',
        'user_id',
        'device_name',
        'platform',
        'app_version',
        'first_seen_at',
        'last_seen_at',
        'collected_at',
        'inventory_counts',
        'inventory_collected_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'collected_at' => 'datetime',
        'inventory_counts' => 'array',
        'inventory_collected_at' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(MachineInventoryItem::class);
    }

    public function flags(): HasMany
    {
        return $this->hasMany(MachineFlag::class);
    }

    /** The minimum agent version, overridable per install without a code edit. */
    public static function minAgentVersion(): string
    {
        $configured = config('desktop.min_agent_version');

        return is_string($configured) && $configured !== '' ? $configured : self::MIN_AGENT_VERSION;
    }

    /**
     * True when this machine's agent is too old to report extensions — its
     * empty inventory means "we cannot see", never "nothing installed".
     */
    public function isAgentStale(): bool
    {
        if (! $this->app_version) {
            return true;
        }

        return version_compare((string) $this->app_version, self::minAgentVersion(), '<');
    }

    /** Stable display label; device_name is nullable for very old agents. */
    public function getLabelAttribute(): string
    {
        return $this->device_name ?: 'unknown PC';
    }
}
