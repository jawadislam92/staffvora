<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Models\DesktopDiagnostic;
use App\Models\Machine;
use App\Models\MachineFlag;
use App\Models\MachineInventoryItem;
use App\Models\MonitoringSetting;
use App\Services\MachineComplianceEvaluator;
use App\Services\SlackBotService;
use App\Services\TrackingSessionService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * MACHINE COMPLIANCE INGEST.
 *
 * Receives the endpoint inventory the desktop agent collects — installed browser
 * extensions, installed programs, running processes and network adapters —
 * matches it against the data-driven blocklist, keeps the CURRENT state per
 * machine, and maintains the durable finding ledger (machine_flags).
 *
 * A finding announces to Slack EXACTLY ONCE, when it first appears (a real
 * install), and only for the Upwork-banning categories. It stays quiet while
 * present, auto-resolves when it disappears, and re-announces only if it comes
 * back. VPN/network findings are WATCH-ONLY by owner policy.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SECURITY — this endpoint is the most attacker-exposed surface in the product
 * ─────────────────────────────────────────────────────────────────────────────
 * The agent runs on the EMPLOYEE'S OWN machine. Every byte of this payload is
 * attacker-controlled, and the people being monitored are precisely the people
 * with an incentive to tamper with it. Therefore:
 *
 *  • organization_id and user_id come from the Sanctum token ONLY
 *    (EnsureTenantBound:sanctum has already proven the token's user belongs to
 *    the bound tenant). A body-supplied organization_id / user_id is never read,
 *    never merged, never defaulted from. A tampered agent cannot file evidence
 *    against a colleague or reach another customer's data.
 *  • The raw body is size-checked BEFORE validation, and every category is
 *    item-capped. Over-cap items are DROPPED, not 422'd, so one oversized item
 *    never loses the whole batch — the response reports what was truncated.
 *  • Every string is length-capped and normalised (control characters stripped,
 *    angle brackets neutralised, whitespace collapsed) before storage: these are
 *    attacker-authored names that will be rendered in a manager's dashboard and
 *    interpolated into Slack messages.
 *  • collected_at is a client clock reading and is clamped to the same
 *    backdate/skew window as screenshots; the server clock (`last_seen_at`) is
 *    what retention and the "is this machine alive" badge run against, because
 *    retention must never be defeatable by the thing being retained.
 *  • The route carries its own dedicated throttle (see routes/api.php) and the
 *    number of distinct devices one user may register is capped, so a tampered
 *    agent cannot flood the tables or spend the tenant's whole ingest budget.
 *  • Writes use upsert()/bulk update, which BYPASS the BelongsToOrganization
 *    creating hook — organization_id is therefore stamped by hand on every row.
 *    An unstamped row is invisible to its own tenant AND to the retention sweep.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠ THE `profile` FIELD — DO NOT REINTRODUCE IT
 * ─────────────────────────────────────────────────────────────────────────────
 * The wire field naming an extension's Chrome profile is `browser_profile`, and
 * a field named `profile` must NEVER appear in this payload. In the source app a
 * repeated `profile` key (a standard ModSecurity rule target) made the host
 * firewall return a plain-text 403 for the ENTIRE upload — before it ever
 * reached PHP. Because all four inventories ship in one request, that silently
 * lost extensions, programs, processes AND network together for over half the
 * fleet, including the owner's own PC, while their tokens were plainly in use.
 * It was bisected field by field: {name,id} → 200, {name,profile} → 403.
 * This controller reads `browser_profile` and never `profile`.
 */
class MachineReportController extends DesktopController
{
    /** Hard ceiling on the raw request body, checked before validation. */
    private const MAX_BODY_BYTES = 1048576; // 1 MB

    /** Report blocks accepted per request (there are only four kinds). */
    private const MAX_REPORTS = 6;

    /**
     * Items STORED per category. A real machine sits far below these: ~300
     * extensions across 30 Chrome profiles, ~150 programs. Anything beyond the
     * cap is dropped and counted, never rejected.
     */
    private const ITEM_CAPS = [
        'extensions' => 800,
        'programs' => 500,
        'processes' => 400,
        'network' => 60,
    ];

    /** Rows written per upsert statement. */
    private const UPSERT_CHUNK = 200;

    public function store(Request $request, MachineComplianceEvaluator $evaluator): JsonResponse
    {
        if (strlen((string) $request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json([
                'message' => 'Machine report payload is too large.',
                'code' => 'payload_too_large',
            ], 413);
        }

        // Structural validation only. Item-level rules are deliberately NOT
        // expressed here: a single bad item would fail the whole request, and
        // losing an entire fleet upload over one weird extension name is exactly
        // the failure mode this feature exists to avoid. Items are normalised,
        // truncated or skipped individually below.
        $data = $request->validate([
            'device_name' => ['nullable', 'string', 'max:200'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'platform' => ['nullable', 'string', 'max:32'],
            'reports' => ['required', 'array', 'min:1', 'max:'.self::MAX_REPORTS],
            'reports.*.kind' => ['required', Rule::in(Machine::KINDS)],
            'reports.*.collected_at' => ['nullable', 'string', 'max:40'],
            'reports.*.items' => ['present', 'array'],
        ]);

        $user = $request->user();

        // Server-derived, never from the body.
        $organizationId = $user->organization_id ?? app(CurrentOrganization::class)->id();

        $device = MachineInventoryItem::clean($data['device_name'] ?? null, 120);
        $appVersion = MachineInventoryItem::clean($data['app_version'] ?? null, 32);
        $platform = MachineInventoryItem::clean($data['platform'] ?? null, 32);

        $now = now();
        $floor = $now->copy()->subSeconds(TrackingSessionService::MAX_BACKDATE_SECONDS);
        $ceiling = $now->copy()->addSeconds(TrackingSessionService::CLOCK_SKEW_SECONDS);

        $machine = $this->resolveMachine($organizationId, (int) $user->id, $device, $platform, $appVersion, $now);

        if ($machine === null) {
            return response()->json([
                'message' => 'This account has registered too many devices. Contact your administrator.',
                'code' => 'device_limit_reached',
            ], 422);
        }

        $stored = [];
        $truncated = [];
        $collectedAt = [];
        $newFindings = [];
        $flaggedTotal = 0;

        // De-duplicate report blocks by kind: a crafted payload could otherwise
        // send `extensions` six times to multiply the write cost.
        $byKind = [];
        foreach ($data['reports'] as $report) {
            $byKind[$report['kind']] = $report;
        }

        foreach ($byKind as $kind => $report) {
            $cap = self::ITEM_CAPS[$kind] ?? 200;
            $rawItems = array_values(array_filter($report['items'], 'is_array'));

            if (count($rawItems) > $cap) {
                $truncated[$kind] = count($rawItems) - $cap;
                $rawItems = array_slice($rawItems, 0, $cap);
            }

            $items = $this->normaliseItems($rawItems, $kind);

            // The agent already sends only ENABLED extensions (owner's policy),
            // so everything received is scanned. Filtering on an `enabled` flag
            // here silently dropped 100% of extensions from older agents, which
            // reported enabled=false for every one — do not add that filter back.
            $hits = $evaluator->scan($items, $kind, $organizationId);

            $reportedAt = isset($report['collected_at'])
                ? TrackingSessionService::clampClientTime($report['collected_at'], $floor, $ceiling)
                : $now;

            $collectedAt[$kind] = ($reportedAt instanceof Carbon ? $reportedAt : $now)->toDateTimeString();

            // An EMPTY inventory for a browsable kind means "the collector could
            // not see" (Chrome's profile directory locked, an agent too old to
            // enumerate extensions), NOT "nothing is installed". Treating it as
            // the truth would wipe a machine's whole list and paint it CLEAN —
            // the exact failure the stale-agent warning exists to prevent — and
            // would let a tampered agent erase its own evidence by sending
            // `{"kind":"extensions","items":[]}`. So neither the inventory nor
            // the finding ledger is swept on a blind report; retention ages
            // those rows out instead.
            $blind = $items === [] && in_array($kind, Machine::FULL_INVENTORY_KINDS, true);

            $stored[$kind] = $this->persistInventory($machine, $organizationId, $kind, $items, $hits, $now, $blind);
            $flaggedTotal += count($hits);

            $newFindings = array_merge(
                $newFindings,
                $this->reconcileFlags($machine, $organizationId, $kind, $items, $hits, $appVersion, $platform, $now, $blind),
            );
        }

        $machine->forceFill([
            'app_version' => $appVersion ?: $machine->app_version,
            'platform' => $platform ?: $machine->platform,
            'last_seen_at' => $now,
            'collected_at' => $now,
            'inventory_counts' => array_merge($machine->inventory_counts ?? [], $stored),
            'inventory_collected_at' => array_merge($machine->inventory_collected_at ?? [], $collectedAt),
        ])->save();

        if ($newFindings) {
            $this->announce($user->name, $machine, $newFindings);
        }

        return response()->json([
            'machine_id' => $machine->id,
            'stored' => $stored,
            'truncated' => $truncated,
            'flagged' => $flaggedTotal,
            'alerted' => count($newFindings),
        ]);
    }

    /**
     * Find or create this user's machine row.
     *
     * device_name is the only client-controlled part of a machine's identity, so
     * the number of distinct devices per user is capped: without it a tampered
     * agent could register a new "PC" on every request and grow the table
     * without bound. Returns null when the cap is reached.
     */
    private function resolveMachine(
        ?int $organizationId,
        int $userId,
        ?string $device,
        ?string $platform,
        ?string $appVersion,
        Carbon $now,
    ): ?Machine {
        // The organization predicate is explicit as well as scoped: the ingest
        // must not depend on the global scope having been applied.
        //
        // device_name is nullable (very old agents send none), and `where(col,
        // null)` compiles to `col = NULL`, which matches NOTHING in SQL. Without
        // the whereNull branch every report from such an agent would look like a
        // brand-new PC and mint a row until the device cap slammed shut.
        $machine = Machine::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->when($device === null, fn ($q) => $q->whereNull('device_name'), fn ($q) => $q->where('device_name', $device))
            ->first();

        if ($machine) {
            return $machine;
        }

        $existing = Machine::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->count();

        if ($existing >= Machine::MAX_DEVICES_PER_USER) {
            DesktopDiagnostic::record([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'category' => DesktopDiagnostic::CATEGORY_DESKTOP,
                'level' => 'warn',
                'event' => 'compliance_device_limit',
                'summary' => 'Machine report rejected: device limit reached for this account.',
                'context' => ['device_name' => $device, 'devices' => $existing],
                'device_name' => $device,
            ]);

            return null;
        }

        return Machine::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'device_name' => $device,
            'platform' => $platform,
            'app_version' => $appVersion,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * Normalise raw wire items into the storage shape.
     *
     * Only identity fields survive. Permissions, manifest URLs, full command
     * lines and extension ids beyond the signature are deliberately not stored:
     * they are not used for matching (see MachineComplianceEvaluator) and are
     * pure liability on a page a manager reads.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normaliseItems(array $items, string $kind): array
    {
        $out = [];
        $seen = [];

        foreach ($items as $raw) {
            // NOTE: `browser_profile`, never `profile`. See the class docblock —
            // a `profile` key gets the entire upload 403'd by the host firewall.
            $item = [
                'name' => MachineInventoryItem::clean(
                    $raw['name'] ?? $raw['process'] ?? $raw['adapter'] ?? $raw['title'] ?? null,
                    MachineInventoryItem::MAX_NAME,
                ),
                'browser' => MachineInventoryItem::clean($raw['browser'] ?? null, MachineInventoryItem::MAX_BROWSER),
                'browser_profile' => MachineInventoryItem::clean($raw['browser_profile'] ?? null, MachineInventoryItem::MAX_PROFILE),
                'publisher' => MachineInventoryItem::clean($raw['publisher'] ?? null, MachineInventoryItem::MAX_PUBLISHER),
                'version' => MachineInventoryItem::clean($raw['version'] ?? null, MachineInventoryItem::MAX_VERSION),
                'detail' => MachineInventoryItem::clean(
                    $raw['detail'] ?? $raw['description'] ?? $raw['path'] ?? null,
                    MachineInventoryItem::MAX_DETAIL,
                ),
                'enabled' => array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : null,
                // Used for the signature only — never stored, never matched on.
                'id' => MachineInventoryItem::clean($raw['id'] ?? null, 64),
            ];

            if ($item['name'] === null) {
                continue; // a nameless item is not actionable
            }

            $signature = MachineInventoryItem::signatureFor($kind, $item);

            // Two Chrome profiles can legitimately hold the same extension, and
            // that is two rows by design; an exact duplicate signature inside one
            // upload is not, and would break the upsert (duplicate key in a
            // single statement on MySQL).
            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $item['signature'] = $signature;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Write the CURRENT state for one kind.
     *
     * `extensions` and `programs` are stored in full (the dashboard browses
     * them). `processes` and `network` store only blocklist hits — a running
     * process list is enormous and changes by the minute, and the dashboard has
     * no browse view for it.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $hits  index => verdict
     * @return int rows written
     */
    private function persistInventory(Machine $machine, ?int $organizationId, string $kind, array $items, array $hits, Carbon $now, bool $blind): int
    {
        $full = in_array($kind, Machine::FULL_INVENTORY_KINDS, true);
        $rows = [];

        foreach ($items as $index => $item) {
            $hit = $hits[$index] ?? null;

            if (! $full && $hit === null) {
                continue;
            }

            $rows[] = [
                // Stamped BY HAND: upsert() bypasses the BelongsToOrganization
                // creating hook, and an unstamped row is invisible to its own
                // tenant and to the retention sweep.
                'organization_id' => $organizationId,
                'machine_id' => $machine->id,
                'user_id' => $machine->user_id,
                'kind' => $kind,
                'signature' => $item['signature'],
                'name' => $item['name'],
                'browser' => $item['browser'],
                'browser_profile' => $item['browser_profile'],
                'publisher' => $item['publisher'],
                'version' => $item['version'],
                'detail' => $item['detail'],
                'enabled' => $item['enabled'],
                'flagged' => $hit !== null,
                'rule' => $hit['rule'] ?? null,
                'severity' => $hit['severity'] ?? null,
                'alert' => (bool) ($hit['alert'] ?? false),
                'present' => true,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'removed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            MachineInventoryItem::upsert(
                $chunk,
                ['machine_id', 'kind', 'signature'],
                // first_seen_at is deliberately absent: it is the install date
                // and must survive every later report.
                [
                    'organization_id', 'user_id', 'name', 'browser', 'browser_profile', 'publisher',
                    'version', 'detail', 'enabled', 'flagged', 'rule', 'severity', 'alert',
                    'present', 'last_seen_at', 'removed_at', 'updated_at',
                ],
            );
        }

        // Anything on this machine+kind not touched by this report is gone. The
        // sweep is a timestamp comparison rather than a NOT IN over 800
        // signatures — one indexed statement instead of a query the size of the
        // payload. Skipped entirely on a blind report (see store()).
        if (! $blind) {
            MachineInventoryItem::query()
                ->where('organization_id', $organizationId)
                ->where('machine_id', $machine->id)
                ->where('kind', $kind)
                ->where('present', true)
                ->where('last_seen_at', '<', $now)
                ->update(['present' => false, 'removed_at' => $now, 'updated_at' => $now]);
        } else {
            DesktopDiagnostic::record([
                'organization_id' => $organizationId,
                'user_id' => $machine->user_id,
                'category' => DesktopDiagnostic::CATEGORY_DESKTOP,
                'level' => 'warn',
                'event' => 'compliance_empty_inventory',
                'summary' => sprintf('Empty %s inventory from %s — treated as UNKNOWN, not clean.', $kind, $machine->label),
                'context' => ['kind' => $kind, 'machine_id' => $machine->id],
                'device_name' => $machine->device_name,
            ]);
        }

        return count($rows);
    }

    /**
     * Sync the durable ledger for one kind and return the ALERTING findings that
     * newly appeared (a genuine install or reinstall), so they announce once.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $hits
     * @return array<int, array<string, mixed>>
     */
    private function reconcileFlags(
        Machine $machine,
        ?int $organizationId,
        string $kind,
        array $items,
        array $hits,
        ?string $appVersion,
        ?string $platform,
        Carbon $now,
        bool $blind,
    ): array {
        $announce = [];
        $processed = 0;

        foreach ($hits as $index => $hit) {
            if (++$processed > MachineFlag::MAX_PER_REPORT) {
                break; // the ledger must not be floodable
            }

            $item = $items[$index];
            $signature = MachineFlag::signatureFor(
                $kind,
                $hit['rule'],
                ($item['browser'] ?? '').'|'.($item['browser_profile'] ?? '').'|'.$item['name'],
            );

            $flag = MachineFlag::firstOrNew([
                'machine_id' => $machine->id,
                'signature' => $signature,
            ]);

            // "New" = never seen, or seen before and since resolved (the tool
            // disappeared and came back). Ignored flags stay silent forever —
            // that is the per-machine allowlist a manager built by hand.
            $reappeared = $flag->exists && $flag->status === MachineFlag::STATUS_RESOLVED;
            $isNew = (! $flag->exists || $reappeared) && $flag->status !== MachineFlag::STATUS_IGNORED;

            if ($isNew) {
                $flag->status = MachineFlag::STATUS_OPEN;
                $flag->first_seen_at = $flag->first_seen_at ?? $now;
                $flag->alerted_at = $now;
                $flag->resolved_at = null;
            }

            $flag->organization_id = $organizationId;
            $flag->user_id = $machine->user_id;
            $flag->device_name = $machine->device_name;
            $flag->kind = $kind;
            $flag->rule = $hit['rule'];
            $flag->severity = $hit['severity'];
            $flag->alert = (bool) $hit['alert'];
            $flag->label = $item['name'];
            $flag->browser_profile = $item['browser_profile'];
            $flag->app_version = $appVersion ?: $flag->app_version;
            $flag->platform = $platform ?: $flag->platform;
            $flag->last_seen_at = $now;
            $flag->save();

            // Audit breadcrumb on STATE TRANSITIONS only. The source captured one
            // diagnostic per hit per upload, which at hourly reports is its own
            // firehose; a finding that has not changed is already on the ledger.
            if ($isNew) {
                DesktopDiagnostic::record([
                    'organization_id' => $organizationId,
                    'user_id' => $machine->user_id,
                    'category' => DesktopDiagnostic::CATEGORY_DESKTOP,
                    'level' => ($hit['severity'] ?? 'medium') === 'critical' ? 'error' : 'warn',
                    'event' => 'compliance_flag',
                    'summary' => sprintf(
                        '[%s] %s on %s — %s / %s',
                        strtoupper((string) $hit['severity']),
                        $item['name'],
                        $machine->label,
                        $kind,
                        $hit['rule'],
                    ),
                    'context' => [
                        'kind' => $kind,
                        'rule' => $hit['rule'],
                        'severity' => $hit['severity'],
                        'alert' => (bool) $hit['alert'],
                        'browser_profile' => $item['browser_profile'],
                        'machine_id' => $machine->id,
                    ],
                    'device_name' => $machine->device_name,
                ]);
            }

            // Slack only for the Upwork-banning categories, and only on a real
            // open/reopen transition. VPN + automation frameworks are watch-only.
            if ($isNew && $flag->alert) {
                $announce[] = [
                    'item' => $item['name'],
                    'kind' => $kind,
                    'rule' => $hit['rule'],
                    'severity' => $hit['severity'],
                    'browser_profile' => $item['browser_profile'],
                ];
            }
        }

        // Auto-resolve findings on this kind that are no longer present. Same
        // timestamp trick as the inventory sweep, and the same blind-report
        // exemption: a machine that reported nothing has not proved a scraper
        // was removed, so its findings must stay open. Without this, sending one
        // empty report would clear a machine's whole compliance record.
        // 'ignored' rows are untouched — they are a manager's allowlist.
        if ($blind) {
            return $announce;
        }

        MachineFlag::query()
            ->where('organization_id', $organizationId)
            ->where('machine_id', $machine->id)
            ->where('kind', $kind)
            ->whereIn('status', [MachineFlag::STATUS_OPEN, MachineFlag::STATUS_ACKNOWLEDGED])
            ->where('last_seen_at', '<', $now)
            ->update(['status' => MachineFlag::STATUS_RESOLVED, 'resolved_at' => $now, 'updated_at' => $now]);

        return $announce;
    }

    /**
     * ONE consolidated message per person+machine per upload, to the tenant's own
     * configured channel. Never throws: a Slack outage must not fail an ingest
     * whose real job (recording the evidence) already succeeded.
     *
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function announce(string $userName, Machine $machine, array $findings): void
    {
        try {
            $channel = MonitoringSetting::current()->slack_attendance_channel
                ?: config('services.attendance.tracker_health_channel')
                ?: config('services.attendance.clockin_channel');

            if (! $channel) {
                return;
            }

            $slack = app(SlackBotService::class);

            if (! $slack->configured()) {
                return;
            }

            $critical = collect($findings)->contains(fn ($f) => $f['severity'] === 'critical');

            // Name the browser profile when we know it: "which Upwork account is
            // at risk" is the actionable part, not "which PC". Every value here
            // has already been normalised (no control characters), so a crafted
            // extension name cannot forge extra lines in this message.
            $lines = collect($findings)->map(fn ($f) => sprintf(
                '  • *%s*  _(%s · %s)_%s',
                $f['item'],
                $f['rule'],
                $f['kind'],
                $f['browser_profile'] ? sprintf('  → profile *%s*', $f['browser_profile']) : '',
            ))->implode("\n");

            $slack->postToChannel($channel, sprintf(
                "%s *Compliance — %s* on `%s`\nNewly installed %d tool%s that can get an Upwork profile flagged — review + remove:\n%s",
                $critical ? ':rotating_light:' : ':warning:',
                $userName,
                $machine->label,
                count($findings),
                count($findings) === 1 ? '' : 's',
                $lines,
            ));
        } catch (\Throwable $e) {
            Log::warning('Compliance Slack alert failed', ['message' => $e->getMessage()]);
        }
    }
}
