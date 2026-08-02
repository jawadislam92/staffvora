<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * ONE gated capability in the product — a row in the editable feature
 * CATALOGUE that replaces the old PHP constant `Entitlements::REGISTRY`.
 *
 * LANDLORD-SIDE. This table is not tenant-owned: it carries no
 * `organization_id`, must never use BelongsToOrganization, and tenants must
 * never be able to read or write it. It is the catalogue of what CAN be sold —
 * it grants nothing by itself.
 *
 * A feature has a `kind`, and the kind decides how a value is compared:
 *   flag — boolean. On or off.
 *   max  — integer CEILING. A tenant setting may not exceed it.
 *          `null` means UNLIMITED (not "zero", not "deny").
 *   min  — integer FLOOR. A tenant setting may not go below it
 *          (e.g. auto-pause minutes: cheaper plans are forced to a gentler
 *          floor). A blank/absent value must mean "inherit", never 0 —
 *          0 is the most permissive floor there is.
 *
 * `default_value` is the DEFAULT-DENY value used when nothing grants the
 * feature: `false` for a flag, `0` for a max, a high number for a min. It is
 * stored as a JSON scalar so `false`, `0` and `null` stay distinguishable.
 *
 * READ PATH / SCALE
 * -----------------
 * The catalogue is read on essentially every request (the Inertia
 * `entitlements` prop, the HTTP entitlement gate, the plan editor), so
 * `catalogue()` is cached forever and busted automatically whenever any
 * Feature row is written or deleted. Never query this table directly in a
 * request path — call `Feature::catalogue()`.
 *
 * @property string $key
 * @property string $label
 * @property string|null $help
 * @property string $kind      flag|max|min
 * @property string $group
 * @property int|bool|null $default_value
 * @property string|null $unit
 * @property int $sort
 * @property bool $is_active
 */
class Feature extends Model
{
    /** The kinds a feature may have. */
    public const KINDS = ['flag', 'max', 'min'];

    /** Cache key for the whole active catalogue. Bump the suffix if the shape changes. */
    public const CACHE_KEY = 'platform.features.catalogue.v1';

    protected $fillable = [
        'key', 'label', 'help', 'kind', 'group', 'default_value', 'unit', 'sort', 'is_active',
    ];

    protected $casts = [
        // 'json' (not 'array') because the stored value is a SCALAR:
        // false / 0 / 10 / null. An 'array' cast would mangle it.
        'default_value' => 'json',
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Any write to the catalogue invalidates the cached read model. This is the
     * only cache-busting path, so callers never have to remember to flush.
     */
    protected static function booted(): void
    {
        static::saved(fn () => static::flushCatalogue());
        static::deleted(fn () => static::flushCatalogue());
    }

    // --- Scopes -----------------------------------------------------------

    /** Features that are currently part of the product. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    // --- The cached read model -------------------------------------------

    /**
     * The whole ACTIVE catalogue, keyed by feature key, in display order.
     *
     * Shape (deliberately identical to the old `Entitlements::REGISTRY` entries
     * plus `key` and `sort`, so the existing operator UI keeps working):
     *
     *   'ai' => [
     *       'key'     => 'ai',
     *       'kind'    => 'flag',        // flag|max|min
     *       'default' => false,         // the default-DENY value
     *       'group'   => 'Features',
     *       'label'   => 'AI assistant',
     *       'help'    => '…',
     *       'unit'    => null,
     *       'sort'    => 110,
     *   ]
     *
     * Cached forever; busted on any Feature write. Returns an empty array if the
     * table does not exist yet (fresh checkout, mid-migration) so booting can
     * never fail on it — and an empty catalogue grants nothing, which is the
     * safe direction.
     *
     * @return array<string, array{key:string, kind:string, default:int|bool|null, group:string, label:string, help:string|null, unit:string|null, sort:int}>
     */
    public static function catalogue(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            if (! Schema::hasTable('features')) {
                return [];
            }

            return static::query()->active()->ordered()->get()
                ->mapWithKeys(fn (self $feature) => [$feature->key => [
                    'key' => $feature->key,
                    'kind' => $feature->kind,
                    'default' => $feature->defaultValue(),
                    'group' => $feature->group,
                    'label' => $feature->label,
                    'help' => $feature->help,
                    'unit' => $feature->unit,
                    'sort' => (int) $feature->sort,
                ]])
                ->all();
        });
    }

    /** Drop the cached catalogue. Called automatically on every write. */
    public static function flushCatalogue(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<int, string> Every active feature key, in display order. */
    public static function keys(): array
    {
        return array_keys(static::catalogue());
    }

    /** The catalogue entry for a key, or null if it is unknown/inactive. */
    public static function entry(string $key): ?array
    {
        return static::catalogue()[$key] ?? null;
    }

    /** flag|max|min, or null when the key is unknown. */
    public static function kindOf(string $key): ?string
    {
        return static::catalogue()[$key]['kind'] ?? null;
    }

    /**
     * The default-DENY value for a key. An UNKNOWN key returns `false` — deny —
     * never null, because on a max-kind feature null means unlimited and an
     * unknown key must never resolve to unlimited.
     */
    public static function defaultFor(string $key): int|bool|null
    {
        $entry = static::catalogue()[$key] ?? null;

        return $entry === null ? false : $entry['default'];
    }

    /** Grouped for the plan / override editors: ['Limits' => [entry, …], …]. */
    public static function grouped(): array
    {
        $out = [];
        foreach (static::catalogue() as $entry) {
            $out[$entry['group']][] = $entry;
        }

        return $out;
    }

    // --- Instance helpers -------------------------------------------------

    /** This feature's default-deny value, cast to its kind. */
    public function defaultValue(): int|bool|null
    {
        return $this->castValue($this->default_value);
    }

    public function isFlag(): bool
    {
        return $this->kind === 'flag';
    }

    /**
     * Normalise a raw value to this feature's kind: flags → bool, caps → int,
     * with null preserved on numeric kinds because null means "unlimited".
     */
    public function castValue(mixed $value): int|bool|null
    {
        if ($this->kind === 'flag') {
            return (bool) $value;
        }

        return $value === null ? null : (int) $value;
    }
}
