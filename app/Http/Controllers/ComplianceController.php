<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\MachineFlag;
use App\Models\MachineInventoryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MACHINE COMPLIANCE — the review surface.
 *
 * The desktop agent reports what is installed and running on every PC; this page
 * is where a manager proves the fleet is clean, or finds the scraper that is
 * about to get an Upwork profile banned.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FOUR SWITCHABLE VIEWS (the source's hard-won UX, ported)
 * ─────────────────────────────────────────────────────────────────────────────
 *  Findings     the durable ledger grouped by TOOL — "Instant Data Scraper is on
 *               3 machines" is one row, not three. Acknowledge / Ignore here.
 *  By machine   one row per PC with its compliance state.
 *  By employee  what one person runs across their machines — EXTENSIONS AND
 *               PROGRAMS both (the source shipped a fix titled "By-employee now
 *               covers programs"; a programs-blind view hid installed jigglers).
 *  By extension deliberately NOT called "software": it lists browser extensions
 *               and who has them, and the source renamed the tab because that is
 *               what it actually shows.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SCALE — one view is loaded per request, and every count is a SQL aggregate
 * ─────────────────────────────────────────────────────────────────────────────
 * The single-tenant source pulled the WHOLE flag ledger and the WHOLE extension
 * inventory into PHP on every visit and grouped them with collection pipelines.
 * At 500 organizations of 100+ PCs (≈45,000 inventory rows per org) that is a
 * page that dies under its own payload.
 *
 * Here:
 *  • `view` selects ONE dataset; the other three are never queried;
 *  • every view is a GROUP BY in the database, paginated, never a full read;
 *  • the two grouped views take their page count as a COUNT(DISTINCT group key)
 *    rather than letting the paginator re-run the whole aggregate inside
 *    `select count(*) from (…)` — see paginateGroups();
 *  • the summary tiles are four bounded aggregate queries;
 *  • occurrence lists (who has this tool) are filtered and ordered IN SQL by the
 *    page's own (rule, tool) pairs and bounded by a hard row cap AND a per-tool
 *    display cap, with the true totals — including "+N more" — coming from the
 *    aggregate, never from the length of the fetched list;
 *  • the machine list uses correlated withCount subqueries on indexed columns
 *    rather than loading flags;
 *  • the heavy props are CLOSURES, so an Acknowledge partial reload
 *    (`only: ['rows']`) never recomputes the summary.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SECURITY — every string on this page is attacker-supplied
 * ─────────────────────────────────────────────────────────────────────────────
 * An employee can name a process, an extension or a PC anything they like, and
 * it lands here. The ingest already normalises and length-caps every value
 * (control characters stripped, angle brackets neutralised); this controller
 * adds the read-side half:
 *  • nothing is ever rendered as HTML — the page is React with no
 *    dangerouslySetInnerHTML anywhere, and no HTML string is built from these
 *    values on either side of the wire;
 *  • search terms are LIKE-escaped and length-capped before they reach a query;
 *  • every filter is whitelisted against a constant, never passed through;
 *  • tenancy is the OrganizationScope plus an EXPLICIT organization predicate on
 *    the write paths, so a mutation can never be aimed at another customer's row
 *    even if the scope were somehow unbound;
 *  • bulk acknowledge is re-scoped server-side to the same machine and the same
 *    rule as the flag in the URL, so a crafted id list cannot reach anything the
 *    caller did not already open.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * "UNKNOWN" IS NOT "CLEAN"
 * ─────────────────────────────────────────────────────────────────────────────
 * A machine whose agent predates extension reporting sends an empty extension
 * list. Painting that green is the single most dangerous thing this page could
 * do, so such a machine resolves to the UNKNOWN state, is counted separately in
 * the summary, and is rendered with its own badge. Same for a machine that has
 * stopped reporting altogether.
 *
 * ACCESS is decided ONLY by the `machine_compliance` entitlement key, applied as
 * route middleware. There is no plan check, no workspace_type check and no
 * second gate class in this file — see routes/web.php.
 */
class ComplianceController extends Controller
{
    /** The switchable views. Anything else falls back to the first. */
    private const VIEWS = ['findings', 'machines', 'employees', 'extensions'];

    /** Report kinds this page browses (the two stored in full by the ingest). */
    private const KIND_EXTENSIONS = 'extensions';

    private const KIND_PROGRAMS = 'programs';

    private const STATUS_FILTERS = ['live', 'open', 'acknowledged', 'ignored', 'resolved'];

    private const SEVERITY_FILTERS = ['all', 'critical', 'high', 'medium', 'low'];

    /** Machine compliance states, worst first. */
    private const STATE_ALERT = 'alert';

    private const STATE_UNKNOWN = 'unknown';

    private const STATE_WATCH = 'watch';

    private const STATE_CLEAN = 'clean';

    private const PER_PAGE = 25;

    /** Rows fetched to fill the inline "who has this" lists on one page. */
    private const MAX_OCCURRENCE_ROWS = 600;

    /** Occurrences shown inline per tool before "+N more". */
    private const OCCURRENCES_PER_TOOL = 12;

    /** Longest search term accepted (the value is attacker-supplied). */
    private const MAX_SEARCH = 80;

    /** A machine silent for longer than this is no longer evidence of anything. */
    private const SILENT_AFTER_DAYS = 3;

    /** Ceiling on ids accepted by a bulk acknowledge. */
    private const MAX_BULK_IDS = 200;

    public function index(Request $request): Response
    {
        $view = $this->pick((string) $request->input('view'), self::VIEWS);
        $status = $this->pick((string) $request->input('status'), self::STATUS_FILTERS);
        $severity = $this->pick((string) $request->input('severity'), self::SEVERITY_FILTERS);
        $search = $this->search($request->input('q'));

        // A drill-in target: an employee id (By employee) or a lowercased
        // extension name (By extension). Both are bounded and parameter-bound.
        $employeeId = $request->integer('employee') ?: null;
        // The drill-in key is a full extension name, so it gets the column's own
        // ceiling rather than the (shorter) search ceiling — a name truncated to
        // 80 characters would silently match nothing.
        $extension = (string) (MachineInventoryItem::clean($request->input('extension'), MachineInventoryItem::MAX_NAME) ?? '');

        $page = max(1, (int) $request->integer('page'));

        return Inertia::render('Compliance/Index', [
            'view' => $view,
            'filters' => [
                'q' => $search,
                'status' => $status,
                'severity' => $severity,
                'employee' => $employeeId,
                'extension' => $extension,
            ],
            'options' => [
                'statuses' => self::STATUS_FILTERS,
                'severities' => self::SEVERITY_FILTERS,
                'min_agent' => Machine::minAgentVersion(),
                'silent_after_days' => self::SILENT_AFTER_DAYS,
            ],

            // Closures: evaluated on a full visit, skipped on the partial reloads
            // that Acknowledge / Ignore fire (`only: ['rows']`), so a button click
            // never pays for the summary aggregates.
            'summary' => fn () => $this->summary(),

            // Exactly ONE view's dataset is ever built.
            'rows' => fn () => match ($view) {
                'machines' => $this->machineRows($search, $page),
                'employees' => $this->employeeRows($search, $employeeId, $page),
                'extensions' => $this->extensionRows($search, $extension, $page),
                default => $this->findingRows($search, $status, $severity, $page),
            },
        ]);
    }

    /**
     * Acknowledge (seen — stays visible, stays quiet), Ignore (a per-machine
     * allowlist that never alerts again, even on reinstall), or reopen.
     *
     * Accepts an optional `ids` array so ONE click clears a tool across every
     * browser profile it was found in on that machine. Findings are stored per
     * profile — that is what makes a reinstall in a different Chrome profile
     * alert again — but nobody should have to press Acknowledge four times for
     * one scraper.
     *
     * The id list is NOT trusted: it is re-queried and re-scoped to the same
     * machine and the same rule as the flag named in the URL, so a crafted list
     * can only ever hit rows the caller had already opened. The route model
     * binding is itself tenant-scoped (OrganizationScope), and the re-query adds
     * an explicit organization predicate on top.
     */
    public function updateFlag(Request $request, MachineFlag $flag): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', [
                MachineFlag::STATUS_OPEN,
                MachineFlag::STATUS_ACKNOWLEDGED,
                MachineFlag::STATUS_IGNORED,
            ])],
            'note' => ['nullable', 'string', 'max:500'],
            'ids' => ['sometimes', 'array', 'max:'.self::MAX_BULK_IDS],
            'ids.*' => ['integer'],
        ]);

        $targets = collect([$flag]);

        if (! empty($data['ids'])) {
            $scoped = MachineFlag::query()
                ->where('organization_id', $flag->organization_id)
                ->where('machine_id', $flag->machine_id)
                ->where('rule', $flag->rule)
                ->where('label', $flag->label)
                ->whereIn('id', array_slice($data['ids'], 0, self::MAX_BULK_IDS))
                ->get();

            if ($scoped->isNotEmpty()) {
                $targets = $scoped;
            }
        }

        // The note is free text typed by a manager, stored as text and rendered
        // by React as text. Never interpolated into markup anywhere.
        $note = isset($data['note']) ? MachineInventoryItem::clean($data['note'], 500) : null;
        $now = now();
        $actorId = (int) $request->user()->id;

        foreach ($targets as $target) {
            $target->status = $data['status'];

            if ($data['status'] === MachineFlag::STATUS_OPEN) {
                // Reopening clears the human decision as well as the resolution,
                // otherwise the row would read "acknowledged by X" while open.
                $target->acknowledged_by = null;
                $target->acknowledged_at = null;
                $target->resolved_at = null;
            } else {
                $target->acknowledged_by = $actorId;
                $target->acknowledged_at = $now;
            }

            if ($note !== null) {
                $target->note = $note;
            }

            $target->save();
        }

        return back()->with('success', match ($data['status']) {
            MachineFlag::STATUS_ACKNOWLEDGED => 'Finding acknowledged.',
            MachineFlag::STATUS_IGNORED => 'Finding ignored on this machine — it will not alert again.',
            default => 'Finding reopened.',
        });
    }

    /**
     * Remove a decommissioned machine and everything it ever reported.
     *
     * This is evidence deletion, so it is a manager-only action behind a
     * confirmation dialog, and it is scoped by BOTH the tenant-scoped route
     * binding and an explicit organization_id predicate on each statement — a
     * belt-and-braces pair, because getting this wrong deletes another
     * customer's compliance record.
     *
     * Row counts here are bounded by the ingest's own per-category item caps
     * (≈1,300 inventory rows and a handful of flags for one PC), so each delete
     * is a single indexed statement rather than a sweep.
     */
    public function destroyMachine(Request $request, Machine $machine): RedirectResponse
    {
        $organizationId = $machine->organization_id;
        $label = $machine->label;

        MachineInventoryItem::query()
            ->where('organization_id', $organizationId)
            ->where('machine_id', $machine->id)
            ->delete();

        MachineFlag::query()
            ->where('organization_id', $organizationId)
            ->where('machine_id', $machine->id)
            ->delete();

        $machine->delete();

        return back()->with('success', sprintf('Removed %s and its compliance history.', $label));
    }

    // ---------------------------------------------------------------- summary

    /**
     * Four bounded aggregates. Nothing here loads a list.
     *
     * `unknown` is the number of machines we cannot certify: an agent too old to
     * enumerate extensions, or one that has gone quiet. It is reported next to
     * "clean" precisely so silence is never read as an all-clear.
     *
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $minAgent = Machine::minAgentVersion();
        $silentBefore = now()->subDays(self::SILENT_AFTER_DAYS);

        // One row per DISTINCT agent version — a handful of rows however large
        // the fleet is — so the version comparison (which no database can do)
        // runs a few times instead of once per machine.
        $versions = Machine::query()
            ->selectRaw('app_version, COUNT(*) as machines, SUM(CASE WHEN last_seen_at < ? THEN 1 ELSE 0 END) as silent', [$silentBefore])
            ->groupBy('app_version')
            ->get();

        $machines = 0;
        $staleAgents = 0;
        $silent = 0;

        foreach ($versions as $row) {
            $count = (int) $row->machines;
            $machines += $count;
            $silent += (int) $row->silent;

            if (! $row->app_version || version_compare((string) $row->app_version, $minAgent, '<')) {
                $staleAgents += $count;
            }
        }

        $flagTotals = MachineFlag::query()
            ->selectRaw("SUM(CASE WHEN status = ? AND alert = 1 THEN 1 ELSE 0 END) as open_alerts", [MachineFlag::STATUS_OPEN])
            ->selectRaw("SUM(CASE WHEN status IN (?, ?) AND alert = 0 THEN 1 ELSE 0 END) as watch", [MachineFlag::STATUS_OPEN, MachineFlag::STATUS_ACKNOWLEDGED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as acknowledged', [MachineFlag::STATUS_ACKNOWLEDGED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as ignored', [MachineFlag::STATUS_IGNORED])
            ->first();

        // Machines carrying at least one open alerting finding.
        $flaggedMachines = MachineFlag::query()
            ->where('status', MachineFlag::STATUS_OPEN)
            ->where('alert', true)
            ->distinct()
            ->count('machine_id');

        $lastReport = Machine::query()->max('last_seen_at');

        return [
            'machines' => $machines,
            'open_alerts' => (int) ($flagTotals->open_alerts ?? 0),
            'watch' => (int) ($flagTotals->watch ?? 0),
            'acknowledged' => (int) ($flagTotals->acknowledged ?? 0),
            'ignored' => (int) ($flagTotals->ignored ?? 0),
            'flagged_machines' => $flaggedMachines,
            // Cannot be certified: too-old agent, or gone quiet. NEVER folded
            // into "clean".
            'stale_agents' => $staleAgents,
            'silent_machines' => $silent,
            'min_agent' => $minAgent,
            'last_report' => $lastReport ? (string) $lastReport : null,
        ];
    }

    // ----------------------------------------------------------- findings view

    /**
     * The ledger grouped by TOOL, in SQL.
     *
     * One row per (rule, tool name) with the people/machine/profile spread as
     * aggregates, ordered worst-first: alerting categories above watch-only,
     * then severity, then how far the tool has spread.
     *
     * @return array<string, mixed>
     */
    private function findingRows(string $search, string $status, string $severity, int $page): array
    {
        $query = MachineFlag::query()
            ->selectRaw('rule, LOWER(label) as label_key, MIN(label) as label')
            ->selectRaw('MAX(CASE WHEN alert = 1 THEN 1 ELSE 0 END) as alerting')
            ->selectRaw($this->severityWeightSql('MAX').' as severity_weight')
            ->selectRaw('COUNT(*) as findings')
            ->selectRaw('COUNT(DISTINCT machine_id) as machines')
            ->selectRaw('COUNT(DISTINCT user_id) as people')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as open_count', [MachineFlag::STATUS_OPEN])
            ->selectRaw('MIN(first_seen_at) as first_seen')
            ->selectRaw('MAX(last_seen_at) as last_seen')
            ->groupBy('rule', 'label_key')
            ->orderByRaw('alerting DESC')
            ->orderByRaw('severity_weight DESC')
            ->orderByRaw('people DESC')
            ->orderByRaw('MIN(label) ASC');

        $this->applyStatusFilter($query, $status);

        if ($severity !== 'all') {
            $query->where('severity', $severity);
        }

        if ($search !== '') {
            $like = $this->like($search);
            $query->where(fn (Builder $q) => $q
                ->whereRaw($this->likeSql('label'), [$like])
                ->orWhereRaw($this->likeSql('rule'), [$like]));
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $groups = collect($paginator->items());
        $occurrences = $this->occurrencesFor($groups, $status);

        $data = $groups->map(function ($row) use ($occurrences) {
            $key = $row->rule.'|'.$row->label_key;
            $rows = $occurrences->get($key, collect());
            $shown = $rows->take(self::OCCURRENCES_PER_TOOL)->values();

            // "+N more machines carry this tool" is derived from the AGGREGATE,
            // never from the length of the fetched list. `machines` is
            // COUNT(DISTINCT machine_id) over the same filters, and occurrences
            // are collapsed to one row per machine, so the two are the same
            // quantity — but the aggregate is exact even when the occurrence
            // fetch was truncated by MAX_OCCURRENCE_ROWS. Counting the list
            // instead is what made a truncated finding render as a clean one.
            $machines = (int) $row->machines;

            return [
                'key' => $key,
                'rule' => (string) $row->rule,
                'label' => (string) $row->label,
                'alerting' => (int) $row->alerting === 1,
                'severity' => $this->severityFromWeight((int) $row->severity_weight),
                // "ban" = an Upwork-banning category that alerts. "watch" = VPN /
                // automation frameworks: recorded and shown, never announced.
                'category' => (int) $row->alerting === 1 ? 'ban' : 'watch',
                'findings' => (int) $row->findings,
                'machines' => $machines,
                'people' => (int) $row->people,
                'open' => (int) $row->open_count,
                'first_seen' => $row->first_seen ? (string) $row->first_seen : null,
                'last_seen' => $row->last_seen ? (string) $row->last_seen : null,
                'occurrences' => $shown->all(),
                'occurrences_hidden' => max(0, $machines - $shown->count()),
            ];
        })->values()->all();

        return $this->page($paginator, $data);
    }

    /**
     * Load the "who has this tool" rows for the tool groups on THIS page only.
     *
     * ─── WHY THIS IS FILTERED AND ORDERED IN SQL, NOT IN PHP ─────────────────
     * This used to select `whereIn('rule', …)` capped at MAX_OCCURRENCE_ROWS
     * ordered `rule, label, id` — alphabetically — and then discard the rows
     * whose label was not on the page, in PHP after `get()`. But `findingRows()`
     * paginates GROUPS by `alerting DESC, severity_weight DESC, people DESC,
     * MIN(label) ASC`. The rows fetched were therefore the alphabetically
     * earliest labels while the page rendered the highest-severity ones, and the
     * two sets need not intersect EVEN ON PAGE 1. When they missed, a truncated
     * finding rendered with no occurrences and no "+N more" — a compliance page
     * showing a critical finding as clean, which is the worst possible direction
     * to fail in.
     *
     * So now:
     *  • the WHERE clause names the page's exact (rule, tool name) PAIRS, so
     *    every row fetched is a row the page actually wants — the cap can no
     *    longer be consumed by labels that are not being rendered;
     *  • the ORDER BY reproduces the page's own group ordering (a bound-parameter
     *    rank CASE over those same pairs), so the cap can only ever truncate the
     *    TAIL of the page rather than decapitate its top;
     *  • and the caller derives "+N more" from the aggregate rather than from
     *    this list's length, so even a truncated tail is reported honestly.
     *
     * At most PER_PAGE (25) pairs reach the query, all as bound parameters —
     * `rule` is a whitelisted constant from the ingest and the label key is a
     * lowercased, length-capped value that is never interpolated into SQL.
     *
     * Rows are collapsed to ONE per machine with the affected browser profiles
     * listed on it: four near-identical rows differing only in the profile
     * column is exactly what made the source's page unreadable.
     *
     * @param  Collection<int, object>  $groups
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private function occurrencesFor(Collection $groups, string $status): Collection
    {
        if ($groups->isEmpty()) {
            return collect();
        }

        $rules = $groups->pluck('rule')->unique()->values()->all();

        // The page's groups, IN PAGE ORDER — the paginator already emitted them
        // worst-first, so the array position is the rank.
        $pairs = $groups
            ->map(fn ($row) => [(string) $row->rule, mb_strtolower((string) $row->label_key)])
            ->values()
            ->all();

        $keys = collect($pairs)->map(fn (array $pair) => $pair[1])->flip();

        $query = MachineFlag::query()
            ->with('user:id,name')
            // Redundant next to the pair predicate below, but it is the clause an
            // index can lead on (mach_flag_org_rule_idx), so the planner narrows
            // before it evaluates the per-pair LOWER().
            ->whereIn('rule', $rules)
            ->where(function (Builder $outer) use ($pairs) {
                foreach ($pairs as [$rule, $labelKey]) {
                    $outer->orWhere(fn (Builder $q) => $q
                        ->where('rule', $rule)
                        ->whereRaw('LOWER(label) = ?', [$labelKey]));
                }
            })
            ->orderByRaw(...$this->pageRankSql($pairs))
            ->orderBy('id')
            ->limit(self::MAX_OCCURRENCE_ROWS);

        $this->applyStatusFilter($query, $status);

        return $query->get()
            // Belt and braces: the SQL already restricts to these labels, but a
            // collation that folds case differently from mb_strtolower must not
            // be able to file a row under a group key the page does not have.
            ->filter(fn (MachineFlag $flag) => $keys->has(mb_strtolower((string) $flag->label)))
            ->groupBy(fn (MachineFlag $flag) => $flag->rule.'|'.mb_strtolower((string) $flag->label))
            ->map(function (Collection $rows) {
                return $rows
                    ->groupBy(fn (MachineFlag $flag) => (string) $flag->machine_id)
                    ->map(function (Collection $perMachine) {
                        /** @var MachineFlag $first */
                        $first = $perMachine->first();

                        $profiles = $perMachine->pluck('browser_profile')->filter()->unique()->sort()->values()->all();

                        return [
                            'id' => $first->id,
                            // Every flag behind this row: one Acknowledge clears
                            // the tool across all the profiles it sits in.
                            'ids' => $perMachine->pluck('id')->values()->all(),
                            'machine_id' => $first->machine_id,
                            'device' => $first->device_name ?: 'unknown PC',
                            'user' => $first->user?->name,
                            'kind' => $first->kind,
                            'profiles' => $profiles,
                            // Findings from an agent that cannot report a Chrome
                            // profile at all: "unknown", never "no profile".
                            'unknown_profiles' => $perMachine->whereNull('browser_profile')->count(),
                            // The row is only acknowledged once EVERY profile is.
                            'status' => $perMachine->contains(fn (MachineFlag $f) => $f->status === MachineFlag::STATUS_OPEN)
                                ? MachineFlag::STATUS_OPEN
                                : (string) $first->status,
                            'note' => $first->note,
                            'app_version' => $first->app_version,
                            'first_seen' => optional($perMachine->min('first_seen_at'))->toDateTimeString(),
                            'last_seen' => optional($perMachine->max('last_seen_at'))->toDateTimeString(),
                        ];
                    })
                    ->sortBy(fn (array $row) => mb_strtolower((string) ($row['user'] ?? '')))
                    ->values();
            });
    }

    // ----------------------------------------------------------- machines view

    /**
     * One row per PC, with its live finding counts as correlated subqueries on
     * indexed columns — no flag rows are loaded to render this list.
     *
     * @return array<string, mixed>
     */
    private function machineRows(string $search, int $page): array
    {
        $query = Machine::query()
            ->with('user:id,name')
            ->withCount([
                'flags as open_alerts_count' => fn ($q) => $q
                    ->where('status', MachineFlag::STATUS_OPEN)->where('alert', true),
                'flags as watch_count' => fn ($q) => $q
                    ->whereIn('status', [MachineFlag::STATUS_OPEN, MachineFlag::STATUS_ACKNOWLEDGED])->where('alert', false),
                'flags as acknowledged_count' => fn ($q) => $q
                    ->where('status', MachineFlag::STATUS_ACKNOWLEDGED),
                'flags as ignored_count' => fn ($q) => $q
                    ->where('status', MachineFlag::STATUS_IGNORED),
            ])
            ->orderByDesc('last_seen_at');

        if ($search !== '') {
            $like = $this->like($search);
            $query->where(fn (Builder $q) => $q
                ->whereRaw($this->likeSql('device_name'), [$like])
                ->orWhereIn('user_id', $this->userIdsMatching($search)));
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $silentBefore = now()->subDays(self::SILENT_AFTER_DAYS);

        $data = collect($paginator->items())->map(function (Machine $machine) use ($silentBefore) {
            $counts = (array) ($machine->inventory_counts ?? []);
            $collected = (array) ($machine->inventory_collected_at ?? []);

            $staleAgent = $machine->isAgentStale();
            // An agent that has never sent an extension inventory is just as
            // uncertifiable as one that is too old to collect it.
            $neverReportedExtensions = ! array_key_exists('extensions', $collected);
            $silent = $machine->last_seen_at !== null && $machine->last_seen_at->lt($silentBefore);

            return [
                'id' => $machine->id,
                'device' => $machine->label,
                'user' => $machine->user?->name,
                'user_id' => $machine->user_id,
                'platform' => $machine->platform,
                'app_version' => $machine->app_version,
                'first_seen' => optional($machine->first_seen_at)->toDateTimeString(),
                'last_seen' => optional($machine->last_seen_at)->toDateTimeString(),
                'counts' => $counts,
                'collected_at' => $collected,
                'open_alerts' => (int) $machine->open_alerts_count,
                'watch' => (int) $machine->watch_count,
                'acknowledged' => (int) $machine->acknowledged_count,
                'ignored' => (int) $machine->ignored_count,
                'stale_agent' => $staleAgent,
                'never_reported_extensions' => $neverReportedExtensions,
                'silent' => $silent,
                'state' => $this->machineState(
                    (int) $machine->open_alerts_count,
                    (int) $machine->watch_count,
                    $staleAgent || $neverReportedExtensions || $silent,
                ),
            ];
        })->values()->all();

        return $this->page($paginator, $data);
    }

    /**
     * The single place a machine's badge is decided, ranked worst-first.
     *
     * UNKNOWN deliberately outranks WATCH: a watch-only VPN finding on a machine
     * we cannot fully see is still a machine we cannot certify, and the whole
     * point of the state is that it must never be mistaken for CLEAN.
     */
    private function machineState(int $openAlerts, int $watch, bool $uncertain): string
    {
        if ($openAlerts > 0) {
            return self::STATE_ALERT;
        }

        if ($uncertain) {
            return self::STATE_UNKNOWN;
        }

        return $watch > 0 ? self::STATE_WATCH : self::STATE_CLEAN;
    }

    // ---------------------------------------------------------- employees view

    /**
     * One row per person, aggregated in SQL over the CURRENT inventory —
     * extensions AND programs, because a jiggler is an installed program, not an
     * extension, and an extensions-only view hid them.
     *
     * With `?employee=<id>` this returns that person's actual item list instead,
     * paginated, so the drill-in is bounded exactly like the summary.
     *
     * @return array<string, mixed>
     */
    private function employeeRows(string $search, ?int $employeeId, int $page): array
    {
        if ($employeeId !== null) {
            return $this->employeeItems($employeeId, $search, $page);
        }

        // The filters, applied ONCE and shared by the page query and the group
        // count below — see paginateGroups() for why the count is taken
        // separately instead of by ->paginate().
        $filtered = MachineInventoryItem::query()->where('present', true);

        if ($search !== '') {
            $filtered->whereIn('user_id', $this->userIdsMatching($search));
        }

        // NOTE the `_count` suffixes. `flagged`, `alert` and `enabled` are cast to
        // BOOLEAN on the model, so aliasing a SUM() onto one of those names would
        // hand the page `true` instead of `17`.
        $query = (clone $filtered)
            ->selectRaw('user_id')
            ->selectRaw('COUNT(*) as item_count')
            ->selectRaw('SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) as extension_count', [self::KIND_EXTENSIONS])
            ->selectRaw('SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) as program_count', [self::KIND_PROGRAMS])
            ->selectRaw('SUM(CASE WHEN flagged = 1 THEN 1 ELSE 0 END) as flagged_count')
            ->selectRaw('SUM(CASE WHEN flagged = 1 AND alert = 1 THEN 1 ELSE 0 END) as alerting_count')
            ->selectRaw('COUNT(DISTINCT machine_id) as machine_count')
            ->selectRaw('MAX(last_seen_at) as last_seen')
            ->groupBy('user_id')
            ->orderByRaw('alerting_count DESC')
            ->orderByRaw('flagged_count DESC')
            ->orderByRaw('item_count DESC');

        $paginator = $this->paginateGroups(
            $query,
            fn () => (int) (clone $filtered)->distinct()->count('user_id'),
            $page,
        );

        $rows = collect($paginator->items());
        $names = $this->namesFor($rows->pluck('user_id'));

        $data = $rows->map(fn ($row) => [
            'user_id' => (int) $row->user_id,
            'user' => $names[(int) $row->user_id] ?? null,
            'machines' => (int) $row->machine_count,
            'items' => (int) $row->item_count,
            'extensions' => (int) $row->extension_count,
            'programs' => (int) $row->program_count,
            'flagged' => (int) $row->flagged_count,
            'alerting' => (int) $row->alerting_count,
            'last_seen' => $row->last_seen ? (string) $row->last_seen : null,
        ])->values()->all();

        return $this->page($paginator, $data, ['mode' => 'summary']);
    }

    /**
     * One person's current inventory, flagged items first. Paginated — a heavy
     * user can carry several hundred extensions across their Chrome profiles.
     *
     * @return array<string, mixed>
     */
    private function employeeItems(int $employeeId, string $search, int $page): array
    {
        $query = MachineInventoryItem::query()
            ->where('user_id', $employeeId)
            ->where('present', true)
            ->whereIn('kind', Machine::FULL_INVENTORY_KINDS)
            ->orderByDesc('flagged')
            ->orderByRaw($this->severityWeightSql().' DESC')
            ->orderBy('kind')
            ->orderBy('name');

        if ($search !== '') {
            $like = $this->like($search);
            $query->where(fn (Builder $q) => $q
                ->whereRaw($this->likeSql('name'), [$like])
                ->orWhereRaw($this->likeSql('publisher'), [$like])
                ->orWhereRaw($this->likeSql('browser_profile'), [$like]));
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $devices = $this->deviceNamesFor(collect($paginator->items())->pluck('machine_id'));

        $data = collect($paginator->items())->map(fn (MachineInventoryItem $item) => [
            'id' => $item->id,
            'kind' => $item->kind,
            'name' => $item->name,
            'browser' => $item->browser,
            // NULL is rendered as "profile unknown", never as "no profile" — a
            // missing attribution must not read as an all-clear.
            'browser_profile' => $item->browser_profile,
            'publisher' => $item->publisher,
            'version' => $item->version,
            'device' => $devices[$item->machine_id] ?? 'unknown PC',
            'flagged' => (bool) $item->flagged,
            'alert' => (bool) $item->alert,
            'rule' => $item->rule,
            'severity' => $item->severity,
            'last_seen' => optional($item->last_seen_at)->toDateTimeString(),
        ])->values()->all();

        return $this->page($paginator, $data, [
            'mode' => 'items',
            'employee' => [
                'user_id' => $employeeId,
                'user' => $this->namesFor(collect([$employeeId]))[$employeeId] ?? null,
            ],
        ]);
    }

    // --------------------------------------------------------- extensions view

    /**
     * "By extension" — deliberately NOT "software". Every distinct browser
     * extension across the fleet with how far it has spread, aggregated in SQL.
     *
     * With `?extension=<lowercased name>` it returns who has that one, so
     * "Instant Data Scraper is on 3 machines" is one click to the names.
     *
     * @return array<string, mixed>
     */
    private function extensionRows(string $search, string $extension, int $page): array
    {
        if ($extension !== '') {
            return $this->extensionHolders($extension, $page);
        }

        // Filters first and shared, so the group count does not have to re-run
        // the aggregates just to learn how many groups there are.
        $filtered = MachineInventoryItem::query()
            ->where('kind', self::KIND_EXTENSIONS)
            ->where('present', true);

        if ($search !== '') {
            $filtered->whereRaw($this->likeSql('name'), [$this->like($search)]);
        }

        $query = (clone $filtered)
            ->selectRaw('LOWER(name) as name_key, MIN(name) as name')
            ->selectRaw('MAX(CASE WHEN flagged = 1 THEN 1 ELSE 0 END) as flagged_count')
            ->selectRaw('MAX(CASE WHEN alert = 1 THEN 1 ELSE 0 END) as alerting_count')
            ->selectRaw($this->severityWeightSql('MAX').' as severity_weight')
            ->selectRaw('COUNT(DISTINCT user_id) as people')
            ->selectRaw('COUNT(DISTINCT machine_id) as machine_count')
            ->selectRaw('COUNT(DISTINCT browser_profile) as profile_count')
            ->selectRaw('MAX(last_seen_at) as last_seen')
            ->groupBy('name_key')
            ->orderByRaw('flagged_count DESC')
            ->orderByRaw('severity_weight DESC')
            ->orderByRaw('people DESC')
            ->orderByRaw('MIN(name) ASC');

        $paginator = $this->paginateGroups(
            $query,
            fn () => (int) (clone $filtered)->distinct()->count(DB::raw('LOWER(name)')),
            $page,
        );

        $data = collect($paginator->items())->map(fn ($row) => [
            'key' => (string) $row->name_key,
            'name' => (string) $row->name,
            'flagged' => (int) $row->flagged_count === 1,
            'alerting' => (int) $row->alerting_count === 1,
            'severity' => (int) $row->flagged_count === 1 ? $this->severityFromWeight((int) $row->severity_weight) : null,
            'people' => (int) $row->people,
            'machines' => (int) $row->machine_count,
            'profiles' => (int) $row->profile_count,
            'last_seen' => $row->last_seen ? (string) $row->last_seen : null,
        ])->values()->all();

        return $this->page($paginator, $data, ['mode' => 'summary']);
    }

    /**
     * Who has one extension: whose login, on which PC, in which browser profile
     * — the three things needed to actually go and remove it.
     *
     * The name is attacker-influenced (it came from an employee's browser and
     * back through the query string), so it is length-capped and compared with a
     * bound parameter, never interpolated into SQL.
     *
     * @return array<string, mixed>
     */
    private function extensionHolders(string $extension, int $page): array
    {
        $key = mb_strtolower(mb_substr($extension, 0, MachineInventoryItem::MAX_NAME));

        $query = MachineInventoryItem::query()
            ->where('kind', self::KIND_EXTENSIONS)
            ->where('present', true)
            ->whereRaw('LOWER(name) = ?', [$key])
            ->orderBy('user_id')
            ->orderBy('browser_profile');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $items = collect($paginator->items());
        $names = $this->namesFor($items->pluck('user_id'));
        $devices = $this->deviceNamesFor($items->pluck('machine_id'));

        $data = $items->map(fn (MachineInventoryItem $item) => [
            'id' => $item->id,
            'name' => $item->name,
            'user' => $names[(int) $item->user_id] ?? null,
            'user_id' => $item->user_id,
            'device' => $devices[$item->machine_id] ?? 'unknown PC',
            'browser' => $item->browser,
            'browser_profile' => $item->browser_profile,
            'enabled' => $item->enabled,
            'flagged' => (bool) $item->flagged,
            'alert' => (bool) $item->alert,
            'rule' => $item->rule,
            'severity' => $item->severity,
            'last_seen' => optional($item->last_seen_at)->toDateTimeString(),
        ])->values()->all();

        return $this->page($paginator, $data, [
            'mode' => 'holders',
            'extension' => $items->first()?->name ?? $extension,
        ]);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Names for a bounded set of user ids. Deactivated people still appear —
     * their machine's history is exactly what an audit needs.
     *
     * @param  Collection<int, mixed>  $ids
     * @return array<int, string>
     */
    private function namesFor(Collection $ids): array
    {
        $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        // Tenant-scoped by the global OrganizationScope, so a foreign id simply
        // resolves to nothing. Deactivated people are deliberately NOT filtered
        // out: their machine's history is exactly what an audit needs.
        return User::query()
            ->whereIn('id', $ids->all())
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Device labels for a bounded set of machine ids (tenant-scoped by the
     * global scope, so a foreign id simply resolves to nothing).
     *
     * @param  Collection<int, mixed>  $ids
     * @return array<int, string>
     */
    private function deviceNamesFor(Collection $ids): array
    {
        $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Machine::query()
            ->whereIn('id', $ids->all())
            ->pluck('device_name', 'id')
            ->map(fn ($name) => $name ?: 'unknown PC')
            ->all();
    }

    /**
     * User ids whose name or email matches the search, capped so a one-letter
     * term cannot turn into an unbounded IN list.
     *
     * @return array<int, int>
     */
    private function userIdsMatching(string $search): array
    {
        $like = $this->like($search);

        return User::query()
            ->where(fn (Builder $q) => $q
                ->whereRaw($this->likeSql('name'), [$like])
                ->orWhereRaw($this->likeSql('email'), [$like]))
            ->limit(200)
            ->pluck('id')
            ->all();
    }

    /** Apply the status filter shared by the findings views. */
    private function applyStatusFilter(Builder $query, string $status): void
    {
        if ($status === 'live') {
            $query->live();

            return;
        }

        $query->where('status', $status);
    }

    /**
     * The page's own group ordering, expressed as a sortable rank over raw rows.
     *
     * `findingRows()` orders GROUPS by aggregates (`alerting`, `severity_weight`,
     * `people`, `MIN(label)`) that a row-level query cannot recompute cheaply —
     * so instead of re-deriving the ordering, this pins each row to the position
     * its group already occupies on the page. That makes the occurrence fetch
     * ordered identically to the page by construction, which is what lets the
     * MAX_OCCURRENCE_ROWS cap truncate the tail instead of the top.
     *
     * Everything is a bound parameter; nothing from a row or a request is
     * interpolated into the statement.
     *
     * @param  array<int, array{0:string, 1:string}>  $pairs
     * @return array{0:string, 1:array<int, string>}
     */
    private function pageRankSql(array $pairs): array
    {
        if ($pairs === []) {
            return ['0', []];
        }

        $sql = 'CASE';
        $bindings = [];

        foreach ($pairs as $rank => [$rule, $labelKey]) {
            $sql .= ' WHEN rule = ? AND LOWER(label) = ? THEN '.$rank;
            $bindings[] = $rule;
            $bindings[] = $labelKey;
        }

        // Anything the WHERE clause somehow let through sorts last, so it can
        // only ever be what the cap discards.
        $sql .= ' ELSE '.count($pairs).' END';

        return [$sql, $bindings];
    }

    /**
     * Severity → sortable weight, as SQL. Wrapped in an aggregate when the query
     * is grouped.
     */
    private function severityWeightSql(?string $aggregate = null): string
    {
        $case = "CASE severity WHEN 'critical' THEN 3 WHEN 'high' THEN 2 WHEN 'medium' THEN 1 ELSE 0 END";

        return $aggregate ? $aggregate.'('.$case.')' : $case;
    }

    /** The inverse, for display. */
    private function severityFromWeight(int $weight): string
    {
        return match ($weight) {
            3 => 'critical',
            2 => 'high',
            1 => 'medium',
            default => 'low',
        };
    }

    /**
     * Normalise a client-supplied search term: trimmed, control characters
     * stripped and hard length-capped before it can reach a query.
     */
    private function search(mixed $value): string
    {
        return (string) (MachineInventoryItem::clean($value, self::MAX_SEARCH) ?? '');
    }

    /**
     * Build a LIKE pattern with the wildcards neutralised, so a term of "%" is a
     * search for a percent sign rather than a full-table scan disguised as a
     * filter.
     *
     * The escape character is `!`, NOT a backslash. A backslash cannot be used
     * portably: MySQL processes backslash escapes inside string literals (so the
     * clause has to be written `ESCAPE '\\'`) while SQLite does not (where the
     * same text is a two-character string and the statement errors). `!` needs no
     * escaping in either dialect.
     */
    private function like(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    }

    /**
     * The LIKE predicate that goes with like(). The column is always a hardcoded
     * constant from this file — never request input — and the pattern is bound.
     */
    private function likeSql(string $column): string
    {
        return $column." like ? escape '!'";
    }

    /**
     * Whitelist a request value against a constant. Never trust the raw input:
     * these values reach column comparisons and a match/switch.
     *
     * @param  array<int, string>  $allowed
     */
    private function pick(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : $allowed[0];
    }

    /**
     * Paginate a GROUPED query without paying for the whole aggregate twice.
     *
     * `->paginate()` clones the builder and wraps it in
     * `select count(*) as aggregate from (<the entire GROUP BY>)`, so rendering
     * 25 rows re-runs every SUM, every COUNT(DISTINCT) and every MAX over the
     * tenant's ENTIRE inventory purely to learn how many groups exist. At the
     * ~45,000 inventory rows per org this page is documented for, that duplicate
     * was measured at 19.7 ms (By employee) and 25.2 ms (By extension) — about
     * half of each page's total time, spent computing numbers already computed.
     *
     * The group total is instead taken as a `COUNT(DISTINCT <group key>)`, which
     * is the same number by definition and needs none of the aggregates. It is a
     * closure so a page whose first query already answered the question can skip
     * it, and so the count is not built when there is nothing to count.
     *
     * @param  Builder  $query  the grouped, ordered page query
     * @param  callable():int  $total  the group count
     */
    private function paginateGroups(Builder $query, callable $total, int $page): LengthAwarePaginator
    {
        $page = max(1, $page);

        $items = $query->forPage($page, self::PER_PAGE)->get();

        return new LengthAwarePaginator(
            $items,
            $total(),
            self::PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * The wire shape every view returns: rows plus bounded pagination metadata.
     * The paginator's own items are never serialised — only the mapped arrays,
     * so no model attribute can leak onto the page by accident.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function page(LengthAwarePaginator $paginator, array $data, array $extra = []): array
    {
        return array_merge([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], $extra);
    }
}
