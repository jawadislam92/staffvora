<?php

namespace App\Services;

use App\Models\MachineBlocklistRule;
use App\Models\MachineInventoryItem;
use Illuminate\Support\Collection;

/**
 * Decides whether an inventory item is a compliance finding, using the
 * data-driven blocklist (platform baseline + the tenant's own rules).
 *
 * TWO RULES THE SOURCE LEARNED THE HARD WAY, PRESERVED HERE:
 *
 *  1. Match on the tool's NAME (and a program's publisher) — never on the
 *     capabilities it requests. An earlier version folded each extension's
 *     manifest `permissions` into the searched text, so anything that merely
 *     asked for the "proxy" permission (Similarweb, IDM, ad blockers) was
 *     mislabelled a VPN. identityText() only ever sees identity fields.
 *
 *  2. Only the genuinely Upwork-banning categories ALERT. VPN/network findings
 *     and RPA/QA automation frameworks are recorded and shown on the dashboard
 *     but never ping Slack — VPNs are used for legitimate client work in the
 *     office. That decision lives in the rule row's `alert` column, so the
 *     operator can change the policy without a deploy.
 *
 * Matching is for REVIEW, not automatic punishment: a hit surfaces the machine
 * on the compliance dashboard so a human confirms and acts.
 */
class MachineComplianceEvaluator
{
    /**
     * Identity fields searched, per report kind. NEVER permissions, paths, ids
     * or URLs — see rule 1 above. These are the NORMALISED keys the ingest
     * produces, not raw wire keys.
     *
     * @var array<string, array<int, string>>
     */
    private const IDENTITY_FIELDS = [
        'extensions' => ['name'],
        'programs' => ['name', 'publisher'],
        'processes' => ['name'],
        'network' => ['name', 'detail'],
    ];

    private const IDENTITY_FALLBACK = ['name', 'publisher', 'detail'];

    /**
     * Rules memoised per organization for the lifetime of the request. A single
     * ingest scans up to a few thousand items against a few hundred rules; one
     * query is plenty and a longer-lived cache would keep a customer's freshly
     * added rule from taking effect (and would need invalidation the dashboard
     * agent has no reason to know about).
     *
     * @var array<string, Collection<int, MachineBlocklistRule>>
     */
    private array $memo = [];

    /** @return Collection<int, MachineBlocklistRule> */
    public function rulesFor(?int $organizationId): Collection
    {
        $key = (string) ($organizationId ?? 'null');

        return $this->memo[$key] ??= MachineBlocklistRule::effectiveFor($organizationId);
    }

    /** Drop the memo — only needed by tests that edit rules mid-request. */
    public function forget(): void
    {
        $this->memo = [];
    }

    /**
     * Evaluate ONE normalised item.
     *
     * @param  array<string, mixed>  $item  normalised item (name/publisher/detail/…)
     * @return array{rule:string, label:string, severity:string, alert:bool, matched:string}|null
     */
    public function evaluate(array $item, string $kind, ?int $organizationId): ?array
    {
        $text = mb_strtolower($this->identityText($item, $kind));

        if ($text === '') {
            return null;
        }

        foreach ($this->rulesFor($organizationId) as $rule) {
            // A rule may be scoped to one report kind; NULL means every kind.
            if ($rule->kind !== null && $rule->kind !== $kind) {
                continue;
            }

            if (! $rule->matches($text)) {
                continue;
            }

            // Allowlist rows sort first (see MachineBlocklistRule::effectiveFor),
            // so a suppression always wins over a later block pattern.
            if ($rule->is_allow) {
                return null;
            }

            return [
                'rule' => (string) $rule->rule_key,
                'label' => (string) $rule->label,
                'severity' => (string) $rule->severity,
                'alert' => (bool) $rule->alert,
                'matched' => (string) $rule->pattern,
            ];
        }

        return null;
    }

    /**
     * Evaluate a batch and return [index => verdict] for the items that hit.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function scan(array $items, string $kind, ?int $organizationId): array
    {
        $hits = [];

        // Verdicts are memoised per identity text: a team of 100 people running
        // the same 30 extensions would otherwise re-run every regex 3,000 times.
        $seen = [];

        foreach ($items as $index => $item) {
            $key = mb_strtolower($this->identityText($item, $kind));

            if ($key === '') {
                continue;
            }

            if (! array_key_exists($key, $seen)) {
                $seen[$key] = $this->evaluate($item, $kind, $organizationId);
            }

            if ($seen[$key] !== null) {
                $hits[$index] = $seen[$key];
            }
        }

        return $hits;
    }

    /**
     * Flatten an item into the identity text tested. Kind-aware, name-first,
     * and deliberately narrow.
     *
     * @param  array<string, mixed>  $item
     */
    public function identityText(array $item, ?string $kind): string
    {
        $fields = self::IDENTITY_FIELDS[$kind] ?? self::IDENTITY_FALLBACK;

        $parts = [];

        foreach ($fields as $field) {
            $value = $item[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        }

        // Bounded: the fields are already length-capped by the normaliser, but
        // the concatenation is capped too so a crafted item cannot make every
        // regex in the list run over a long subject.
        return mb_substr(trim(implode(' ', $parts)), 0, MachineInventoryItem::MAX_NAME + MachineInventoryItem::MAX_PUBLISHER);
    }
}
