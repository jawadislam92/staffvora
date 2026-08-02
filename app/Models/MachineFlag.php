<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One durable finding: a blocklisted tool seen on a machine.
 *
 * This ledger is what makes alerting sane. A tool announces EXACTLY ONCE, when
 * it first appears (a real install), stays quiet while it is present,
 * auto-resolves when it disappears, and re-announces only if it comes back. The
 * source added it after a cache-based dedup re-announced the whole fleet after
 * every deploy.
 *
 *  open         → newly found, needs a human
 *  acknowledged → a manager has seen it; stays visible, stays quiet
 *  ignored      → a per-machine allowlist; never alerts again, even on reinstall
 *  resolved     → the tool is gone (set automatically, pruned later)
 *
 * `alert` is the Slack gate and comes from the blocklist rule: true only for the
 * Upwork-banning categories. VPN and network findings are watch-only.
 */
class MachineFlag extends Model
{
    use BelongsToOrganization;
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_ACKNOWLEDGED, self::STATUS_IGNORED, self::STATUS_RESOLVED];

    /** Severity ranking for the dashboard's "worst first" ordering. */
    public const SEVERITY_WEIGHT = ['critical' => 3, 'high' => 2, 'medium' => 1, 'low' => 0];

    /**
     * Ceiling on findings reconciled from a single upload. A machine with more
     * blocklist hits than this is either genuinely catastrophic or sending a
     * crafted payload; either way the dashboard is already screaming, and the
     * ledger must not be floodable.
     */
    public const MAX_PER_REPORT = 200;

    protected $table = 'machine_flags';

    protected $fillable = [
        'organization_id',
        'machine_id',
        'user_id',
        'device_name',
        'kind',
        'rule',
        'severity',
        'alert',
        'label',
        'browser_profile',
        'signature',
        'status',
        'first_seen_at',
        'last_seen_at',
        'alerted_at',
        'resolved_at',
        'acknowledged_by',
        'acknowledged_at',
        'note',
        'app_version',
        'platform',
    ];

    protected $casts = [
        'alert' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'alerted_at' => 'datetime',
        'resolved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** Findings a manager still has to look at. */
    public function scopeLive($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_ACKNOWLEDGED]);
    }

    /**
     * Stable per-finding identity. Includes the rule, so the same item matching
     * two categories after a blocklist edit does not silently overwrite the
     * older finding's history.
     */
    public static function signatureFor(string $kind, string $rule, string $identity): string
    {
        return md5($kind.'|'.$rule.'|'.mb_strtolower(trim($identity)));
    }
}
