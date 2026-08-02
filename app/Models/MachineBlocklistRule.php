<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One blocklist (or allowlist) pattern.
 *
 * THE BLOCKLIST IS DATA. The source kept its rules in a PHP class constant, so
 * adding a newly-discovered scraper meant a code change and a deploy. Here:
 *
 *   organization_id = NULL  → the PLATFORM baseline, shared by every tenant and
 *                             maintained by the operator. is_system = true, so a
 *                             customer may disable one but never delete it.
 *   organization_id = <id>  → that organization's own rule, on top of the
 *                             baseline. Customers extend the list themselves.
 *
 * Tenancy: this model carries the normal OrganizationScope, so any ordinary
 * query is tenant-safe by default and a customer's rules are invisible to other
 * customers. Reading the baseline is a deliberate cross-tenant read and has
 * exactly ONE entry point — effectiveFor() — so there is no second place to get
 * it wrong.
 */
class MachineBlocklistRule extends Model
{
    use BelongsToOrganization;
    use HasFactory;

    public const MATCH_REGEX = 'regex';

    public const MATCH_CONTAINS = 'contains';

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /**
     * Ceiling on rules evaluated in one scan. Scanning is O(items × rules) and
     * both sides are attacker-influenced (a customer admin writes rules, an
     * employee's PC supplies items), so the rule side is bounded too.
     */
    public const MAX_ACTIVE_RULES = 500;

    protected $table = 'machine_blocklist_rules';

    protected $fillable = [
        'organization_id',
        'rule_key',
        'label',
        'severity',
        'alert',
        'kind',
        'match_type',
        'pattern',
        'is_allow',
        'is_system',
        'enabled',
        'sort',
        'notes',
    ];

    protected $casts = [
        'alert' => 'boolean',
        'is_allow' => 'boolean',
        'is_system' => 'boolean',
        'enabled' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * The rules in force for one organization: the platform baseline plus that
     * organization's own rows, allowlist entries first so a suppression is
     * evaluated before any block.
     *
     * THE ONLY place the organization scope is lifted. The replacement WHERE is
     * explicit and narrow — NULL (baseline) or exactly this tenant — so lifting
     * the scope cannot leak another customer's rules.
     */
    public static function effectiveFor(?int $organizationId): Collection
    {
        return static::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('enabled', true)
            ->where(function (Builder $q) use ($organizationId) {
                $q->whereNull('organization_id');

                if ($organizationId !== null) {
                    $q->orWhere('organization_id', $organizationId);
                }
            })
            ->orderByDesc('is_allow')
            ->orderBy('sort')
            ->orderBy('id')
            ->limit(self::MAX_ACTIVE_RULES)
            ->get();
    }

    /** Rows this tenant is allowed to edit or delete (never the baseline). */
    public function scopeOwned($query)
    {
        return $query->where('is_system', false)->whereNotNull('organization_id');
    }

    /**
     * Does this rule match the given identity text (already lowercased)?
     *
     * A tenant-authored regex is untrusted input: an invalid or pathological
     * pattern must never take the ingest down, so preg errors are swallowed and
     * treated as "no match". Patterns are length-capped by the column.
     */
    public function matches(string $text): bool
    {
        if ($text === '' || $this->pattern === null || $this->pattern === '') {
            return false;
        }

        if ($this->match_type === self::MATCH_CONTAINS) {
            return str_contains($text, mb_strtolower($this->pattern));
        }

        $result = @preg_match('/'.$this->pattern.'/iu', $text);

        // preg_match returns false on a bad pattern and on a backtrack-limit
        // blow-up. Both mean "we could not decide", which must not read as a hit.
        return $result === 1;
    }
}
