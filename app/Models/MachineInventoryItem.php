<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * CURRENT state of one inventory item on one machine — an installed extension
 * or program, or (for the findings-only kinds) a flagged process / network
 * adapter. Upserted on every report rather than re-inserted as a snapshot; see
 * the migration for the scale reasoning.
 *
 * The change history lives on the row: first_seen_at, last_seen_at, and
 * present/removed_at for something that was uninstalled.
 *
 * EVERY STRING HERE IS ATTACKER-SUPPLIED. The desktop agent runs on the
 * employee's own machine, these names are typed by a person into Chrome or come
 * from an installer's metadata, and they are rendered in a manager's dashboard.
 * Nothing is written without passing through clean().
 */
class MachineInventoryItem extends Model
{
    use BelongsToOrganization;
    use HasFactory;

    /** Per-field length ceilings, matching the column widths. */
    public const MAX_NAME = 200;

    public const MAX_BROWSER = 40;

    public const MAX_PROFILE = 60;

    public const MAX_PUBLISHER = 150;

    public const MAX_VERSION = 40;

    public const MAX_DETAIL = 200;

    protected $table = 'machine_inventory_items';

    protected $fillable = [
        'organization_id',
        'machine_id',
        'user_id',
        'kind',
        'signature',
        'name',
        'browser',
        'browser_profile',
        'publisher',
        'version',
        'detail',
        'enabled',
        'flagged',
        'rule',
        'severity',
        'alert',
        'present',
        'first_seen_at',
        'last_seen_at',
        'removed_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'flagged' => 'boolean',
        'alert' => 'boolean',
        'present' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Normalise one attacker-supplied string for storage and for display.
     *
     * - control characters (including the ones that let a name spoof a newline
     *   in a Slack alert) are stripped;
     * - angle brackets become SPACES rather than vanishing, so stripping them
     *   can never run two words together into something misleading;
     * - whitespace is collapsed and the value is hard-truncated.
     *
     * Returns null for anything that normalises to empty, so a blank item is
     * skipped rather than stored as a ghost row.
     */
    public static function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = (string) $value;

        // Strip C0/C1 control characters and the Unicode line/paragraph
        // separators; keep everything else so non-Latin names survive intact.
        $value = preg_replace('/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}]/u', ' ', $value) ?? '';
        $value = str_replace(['<', '>'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * Stable per-item identity, so the same install maps to the same row on
     * every report.
     *
     * For extensions the identity includes the BROWSER PROFILE deliberately: the
     * same scraper in "Upwork — Faryal" and in someone's personal profile carry
     * completely different ban risk and each has to be cleared on its own.
     * Agents too old to report a profile collapse to one row, as before.
     */
    public static function signatureFor(string $kind, array $item): string
    {
        $identity = match ($kind) {
            'extensions' => ($item['browser'] ?? '').'|'.($item['browser_profile'] ?? '').'|'.($item['id'] ?? $item['name'] ?? ''),
            'programs' => ($item['name'] ?? '').'|'.($item['publisher'] ?? ''),
            'processes' => $item['process'] ?? $item['detail'] ?? $item['name'] ?? '',
            'network' => ($item['adapter'] ?? $item['name'] ?? '').'|'.($item['detail'] ?? $item['description'] ?? ''),
            default => $item['name'] ?? '',
        };

        return md5($kind.'|'.mb_strtolower(trim((string) $identity)));
    }
}
