import { Fragment, useCallback, useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Modal from '@/Components/Modal';
import {
    Button,
    Card,
    CardHeader,
    Badge,
    StatCard,
    KpiCard,
    Table,
    THead,
    TBody,
    Tr,
    Th,
    Td,
    Field,
    Input,
    Select,
    EmptyState,
    Skeleton,
    Tabs,
} from '@/Components/ui';
import {
    ShieldCheck,
    ShieldAlert,
    Monitor,
    Users,
    Puzzle,
    Search,
    Check,
    EyeOff,
    RotateCcw,
    Trash2,
    ChevronLeft,
    ChevronRight,
    ChevronDown,
    AlertTriangle,
    HelpCircle,
    Package,
} from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

/**
 * MACHINE COMPLIANCE.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EVERY STRING ON THIS PAGE IS ATTACKER-SUPPLIED
 * ─────────────────────────────────────────────────────────────────────────────
 * Extension names, program names, publishers, PC names and Chrome profile names
 * all come from the employee's own machine, and the people being monitored are
 * exactly the people with a motive to make one of them do something clever.
 * React escapes text children by default, so the rule is simply: render these
 * values as CHILDREN and nothing else. There is no dangerouslySetInnerHTML
 * anywhere in this file, no `innerHTML`, no HTML built by string concatenation,
 * and no attacker value is ever used as an href, src or style value. Do not
 * introduce one.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FOUR SWITCHABLE VIEWS
 * ─────────────────────────────────────────────────────────────────────────────
 * Findings · By machine · By employee · By extension. The server builds exactly
 * ONE of them per request (see ComplianceController), so switching a tab is an
 * Inertia partial reload that fetches only `rows`, and Acknowledge never
 * recomputes the summary tiles.
 *
 * The software view is labelled "By extension", not "software": it lists browser
 * extensions and who has them, and the source app renamed the tab deliberately
 * because that is what it actually shows.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * UNKNOWN IS NOT CLEAN
 * ─────────────────────────────────────────────────────────────────────────────
 * A machine whose agent is too old to enumerate browser extensions reports an
 * empty list. It gets its own amber "Unknown" badge and its own summary tile,
 * and it is never rendered green — a machine we cannot see is not a machine we
 * have cleared.
 */

// Machine compliance states, worst first. The server decides the state; this map
// only chooses how it looks. Watch-only findings (VPN / RPA frameworks) are
// informational by owner policy and never wear the danger tone.
const STATE_META = {
    alert: { label: 'Action needed', tone: 'danger', icon: ShieldAlert },
    unknown: { label: 'Unknown', tone: 'warning', icon: HelpCircle },
    watch: { label: 'Watch only', tone: 'info', icon: AlertTriangle },
    clean: { label: 'Clean', tone: 'success', icon: ShieldCheck },
};

const SEVERITY_TONE = {
    critical: 'danger',
    high: 'danger',
    medium: 'warning',
    low: 'neutral',
};

const FLAG_STATUS_META = {
    open: { label: 'Open', tone: 'danger' },
    acknowledged: { label: 'Acknowledged', tone: 'info' },
    ignored: { label: 'Ignored', tone: 'neutral' },
    resolved: { label: 'Resolved', tone: 'success' },
};

const STATUS_FILTER_LABEL = {
    live: 'Open + acknowledged',
    open: 'Open only',
    acknowledged: 'Acknowledged',
    ignored: 'Ignored',
    resolved: 'Resolved',
};

const SEVERITY_FILTER_LABEL = {
    all: 'Any severity',
    critical: 'Critical',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
};

const KIND_LABEL = {
    extensions: 'Extension',
    programs: 'Program',
    processes: 'Process',
    network: 'Network',
};

const TABS = [
    { key: 'findings', label: 'Findings', icon: <ShieldAlert size={14} /> },
    { key: 'machines', label: 'By machine', icon: <Monitor size={14} /> },
    { key: 'employees', label: 'By employee', icon: <Users size={14} /> },
    // Named "By extension" on purpose — see the file docblock.
    { key: 'extensions', label: 'By extension', icon: <Puzzle size={14} /> },
];

const DEFAULT_DISPLAY = { timezone: 'Asia/Karachi', format: '12' };

function makeFormatter(display) {
    return (value) => {
        if (!value) return '—';
        try {
            return new Date(String(value).replace(' ', 'T')).toLocaleString('en-US', {
                timeZone: display.timezone,
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: String(display.format) !== '24',
            });
        } catch {
            return String(value);
        }
    };
}

/** Ziggy route() that cannot explode the page when the route is not registered. */
function href(name, params) {
    try {
        return route(name, params);
    } catch {
        return '#';
    }
}

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true, so the page renders fully
// offline for visual QA (no auth, no database, no desktop agent). It mirrors the
// controller's payload exactly, including the paginator meta shape. Never
// touched during normal rendering.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    user: { id: 1, name: 'Jawad', email: 'jawad@example.test', role: 'super_admin', permissions: [] },
    summary: {
        machines: 18,
        open_alerts: 4,
        watch: 6,
        acknowledged: 3,
        ignored: 2,
        flagged_machines: 3,
        stale_agents: 2,
        silent_machines: 1,
        min_agent: '0.4.6',
        last_report: '2026-08-02 09:41:00',
    },
    rows: {
        findings: {
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 3, from: 1, to: 3 },
            data: [
                {
                    key: 'scraper|instant data scraper',
                    rule: 'scraper',
                    label: 'Instant Data Scraper',
                    alerting: true,
                    severity: 'critical',
                    category: 'ban',
                    findings: 4,
                    machines: 3,
                    people: 3,
                    open: 3,
                    first_seen: '2026-07-28 11:02:00',
                    last_seen: '2026-08-02 09:41:00',
                    occurrences: [
                        {
                            id: 91, ids: [91, 92], machine_id: 7, device: 'PC-VA-7', user: 'Aqsa',
                            kind: 'extensions', profiles: ['Upwork — Faryal', 'Default'], unknown_profiles: 0,
                            status: 'open', note: null, app_version: '0.5.0',
                            first_seen: '2026-07-28 11:02:00', last_seen: '2026-08-02 09:41:00',
                        },
                        {
                            id: 93, ids: [93], machine_id: 9, device: 'DESIGN-02', user: 'Bilal',
                            kind: 'extensions', profiles: [], unknown_profiles: 1,
                            status: 'acknowledged', note: 'Removing today.', app_version: '0.4.5',
                            first_seen: '2026-07-30 08:15:00', last_seen: '2026-08-02 08:55:00',
                        },
                    ],
                    occurrences_hidden: 1,
                },
                {
                    key: 'antidetect_browser|gologin',
                    rule: 'antidetect_browser',
                    label: 'GoLogin',
                    alerting: true,
                    severity: 'critical',
                    category: 'ban',
                    findings: 1,
                    machines: 1,
                    people: 1,
                    open: 1,
                    first_seen: '2026-08-01 14:20:00',
                    last_seen: '2026-08-02 09:10:00',
                    occurrences: [
                        {
                            id: 104, ids: [104], machine_id: 11, device: 'SALES-04', user: 'Hamza',
                            kind: 'programs', profiles: [], unknown_profiles: 0,
                            status: 'open', note: null, app_version: '0.5.0',
                            first_seen: '2026-08-01 14:20:00', last_seen: '2026-08-02 09:10:00',
                        },
                    ],
                    occurrences_hidden: 0,
                },
                {
                    key: 'vpn_proxy|windscribe',
                    rule: 'vpn_proxy',
                    label: 'Windscribe',
                    alerting: false,
                    severity: 'high',
                    category: 'watch',
                    findings: 6,
                    machines: 5,
                    people: 5,
                    open: 4,
                    first_seen: '2026-06-11 09:00:00',
                    last_seen: '2026-08-02 09:41:00',
                    occurrences: [
                        {
                            id: 55, ids: [55], machine_id: 3, device: 'PC-VA-3', user: 'Sana',
                            kind: 'network', profiles: [], unknown_profiles: 0,
                            status: 'open', note: null, app_version: '0.5.0',
                            first_seen: '2026-06-11 09:00:00', last_seen: '2026-08-02 09:41:00',
                        },
                    ],
                    occurrences_hidden: 4,
                },
            ],
        },
        machines: {
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 4, from: 1, to: 4 },
            data: [
                {
                    id: 7, device: 'PC-VA-7', user: 'Aqsa', user_id: 4, platform: 'win32', app_version: '0.5.0',
                    first_seen: '2026-05-02 09:00:00', last_seen: '2026-08-02 09:41:00',
                    counts: { extensions: 214, programs: 137, processes: 3, network: 1 },
                    collected_at: { extensions: '2026-08-02 09:41:00' },
                    open_alerts: 2, watch: 1, acknowledged: 0, ignored: 0,
                    stale_agent: false, never_reported_extensions: false, silent: false, state: 'alert',
                },
                {
                    id: 9, device: 'DESIGN-02', user: 'Bilal', user_id: 6, platform: 'win32', app_version: '0.4.5',
                    first_seen: '2026-05-20 09:00:00', last_seen: '2026-08-02 08:55:00',
                    counts: { programs: 96 },
                    collected_at: {},
                    open_alerts: 0, watch: 0, acknowledged: 1, ignored: 0,
                    stale_agent: true, never_reported_extensions: true, silent: false, state: 'unknown',
                },
                {
                    id: 3, device: 'PC-VA-3', user: 'Sana', user_id: 8, platform: 'win32', app_version: '0.5.0',
                    first_seen: '2026-04-14 09:00:00', last_seen: '2026-08-02 09:41:00',
                    counts: { extensions: 88, programs: 120, network: 1 },
                    collected_at: { extensions: '2026-08-02 09:41:00' },
                    open_alerts: 0, watch: 1, acknowledged: 0, ignored: 1,
                    stale_agent: false, never_reported_extensions: false, silent: false, state: 'watch',
                },
                {
                    id: 12, device: 'OPS-11', user: 'Rida', user_id: 11, platform: 'win32', app_version: '0.5.0',
                    first_seen: '2026-06-01 09:00:00', last_seen: '2026-08-02 09:38:00',
                    counts: { extensions: 41, programs: 88 },
                    collected_at: { extensions: '2026-08-02 09:38:00' },
                    open_alerts: 0, watch: 0, acknowledged: 0, ignored: 0,
                    stale_agent: false, never_reported_extensions: false, silent: false, state: 'clean',
                },
            ],
        },
        employees: {
            mode: 'summary',
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 3, from: 1, to: 3 },
            data: [
                { user_id: 4, user: 'Aqsa', machines: 2, items: 351, extensions: 214, programs: 137, flagged: 3, alerting: 2, last_seen: '2026-08-02 09:41:00' },
                { user_id: 8, user: 'Sana', machines: 1, items: 208, extensions: 88, programs: 120, flagged: 1, alerting: 0, last_seen: '2026-08-02 09:41:00' },
                { user_id: 11, user: 'Rida', machines: 1, items: 129, extensions: 41, programs: 88, flagged: 0, alerting: 0, last_seen: '2026-08-02 09:38:00' },
            ],
        },
        extensions: {
            mode: 'summary',
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 3, from: 1, to: 3 },
            data: [
                { key: 'instant data scraper', name: 'Instant Data Scraper', flagged: true, alerting: true, severity: 'critical', people: 3, machines: 3, profiles: 4, last_seen: '2026-08-02 09:41:00' },
                { key: 'browsec vpn', name: 'Browsec VPN', flagged: true, alerting: false, severity: 'high', people: 5, machines: 5, profiles: 6, last_seen: '2026-08-02 09:41:00' },
                { key: 'grammarly', name: 'Grammarly: AI Writing Assistant', flagged: false, alerting: false, severity: null, people: 12, machines: 14, profiles: 22, last_seen: '2026-08-02 09:41:00' },
            ],
        },
    },
};

// ---------------------------------------------------------------------------
// Small presentational pieces
// ---------------------------------------------------------------------------

function StateBadge({ state }) {
    const meta = STATE_META[state] || STATE_META.unknown;
    const Icon = meta.icon;
    return (
        <Badge tone={meta.tone} className="whitespace-nowrap">
            <Icon size={12} aria-hidden="true" />
            {meta.label}
        </Badge>
    );
}

function SeverityBadge({ severity }) {
    if (!severity) return null;
    return (
        <Badge tone={SEVERITY_TONE[severity] || 'neutral'} className="capitalize">
            {severity}
        </Badge>
    );
}

function FlagStatusBadge({ status }) {
    const meta = FLAG_STATUS_META[status] || FLAG_STATUS_META.open;
    return <Badge tone={meta.tone}>{meta.label}</Badge>;
}

/**
 * Chrome profile chips. An EMPTY profile list next to `unknown_profiles > 0`
 * means the agent could not tell us which profile the tool lives in — rendered
 * as "profile unknown", never as "no profile", so a missing attribution is never
 * mistaken for an all-clear.
 */
function ProfileChips({ profiles = [], unknown = 0 }) {
    if (!profiles.length && !unknown) {
        return <span className="text-subtle">—</span>;
    }
    return (
        <span className="flex flex-wrap gap-1">
            {profiles.map((p) => (
                <Badge key={p} tone="neutral">{p}</Badge>
            ))}
            {unknown > 0 && (
                <Badge tone="warning" title="This agent is too old to report which browser profile the tool sits in.">
                    profile unknown
                </Badge>
            )}
        </span>
    );
}

function Pagination({ meta, onPage, disabled }) {
    if (!meta || meta.last_page <= 1) return null;
    return (
        <div className="flex items-center justify-between gap-3 border-t border-line px-5 py-3">
            <span className="text-xs text-muted tabular-nums">
                {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
            </span>
            <div className="flex items-center gap-2">
                <Button
                    variant="secondary"
                    size="sm"
                    disabled={disabled || meta.current_page <= 1}
                    onClick={() => onPage(meta.current_page - 1)}
                >
                    <ChevronLeft size={14} /> Previous
                </Button>
                <span className="text-xs text-muted tabular-nums">
                    Page {meta.current_page} / {meta.last_page}
                </span>
                <Button
                    variant="secondary"
                    size="sm"
                    disabled={disabled || meta.current_page >= meta.last_page}
                    onClick={() => onPage(meta.current_page + 1)}
                >
                    Next <ChevronRight size={14} />
                </Button>
            </div>
        </div>
    );
}

function TableSkeleton({ rows = 6, cols = 5 }) {
    return (
        <div className="p-5 space-y-3" aria-hidden="true">
            {Array.from({ length: rows }).map((_, r) => (
                <div key={r} className="flex items-center gap-4">
                    {Array.from({ length: cols }).map((__, c) => (
                        <Skeleton key={c} className={cx('h-4', c === 0 ? 'w-1/4' : 'flex-1')} />
                    ))}
                </div>
            ))}
        </div>
    );
}

/** The empty state every view falls back to when the fleet has never reported. */
function NoReportsYet({ minAgent }) {
    return (
        <EmptyState
            icon={<Monitor size={20} />}
            title="No machines reporting yet"
            description={`Compliance data arrives from the desktop agent. Make sure every team member is running version ${minAgent} or newer — older builds cannot enumerate browser extensions at all, so they report nothing and would otherwise look clean.`}
        />
    );
}

// ---------------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------------

function FindingsView({ rows, fmt, canManage, busy, onAct }) {
    const [openKey, setOpenKey] = useState(null);

    if (!rows.data.length) {
        return (
            <EmptyState
                icon={<ShieldCheck size={20} />}
                title="No findings"
                description="Nothing on the blocklist was found on the machines that have reported. Check the By machine tab for any PC in the Unknown state — silence is not the same as clean."
            />
        );
    }

    return (
        <Table>
            <THead>
                <Tr>
                    <Th className="w-8" aria-label="Expand" />
                    <Th>Tool</Th>
                    <Th>Category</Th>
                    <Th>Severity</Th>
                    <Th className="text-right">People</Th>
                    <Th className="text-right">Machines</Th>
                    <Th className="text-right">Open</Th>
                    <Th>Last seen</Th>
                </Tr>
            </THead>
            <TBody>
                {rows.data.map((tool) => {
                    const expanded = openKey === tool.key;
                    return (
                        <Fragment key={tool.key}>
                            <Tr
                                className="cursor-pointer"
                                onClick={() => setOpenKey(expanded ? null : tool.key)}
                            >
                                <Td className="text-subtle">
                                    {expanded ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                                </Td>
                                <Td>
                                    <div className="font-medium text-body">{tool.label}</div>
                                    <div className="text-xs text-subtle">{tool.rule}</div>
                                </Td>
                                <Td>
                                    {tool.alerting ? (
                                        <Badge tone="danger">Bans a profile</Badge>
                                    ) : (
                                        <Badge tone="info">Watch only</Badge>
                                    )}
                                </Td>
                                <Td><SeverityBadge severity={tool.severity} /></Td>
                                <Td className="text-right tabular-nums">{tool.people}</Td>
                                <Td className="text-right tabular-nums">{tool.machines}</Td>
                                <Td className="text-right tabular-nums">
                                    {tool.open > 0 ? (
                                        <span className="text-danger font-medium">{tool.open}</span>
                                    ) : (
                                        <span className="text-subtle">0</span>
                                    )}
                                </Td>
                                <Td className="text-muted whitespace-nowrap">{fmt(tool.last_seen)}</Td>
                            </Tr>

                            {expanded && (
                                <Tr className="hover:bg-transparent">
                                    <Td colSpan={8} className="bg-surface-2/40">
                                        <div className="space-y-2">
                                            {tool.occurrences.map((occ) => (
                                                <div
                                                    key={occ.id}
                                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface px-4 py-3"
                                                >
                                                    <div className="min-w-0 space-y-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-medium text-body">{occ.user || 'Unknown user'}</span>
                                                            <span className="text-subtle">·</span>
                                                            <span className="text-muted">{occ.device}</span>
                                                            <Badge tone="neutral">{KIND_LABEL[occ.kind] || occ.kind}</Badge>
                                                            <FlagStatusBadge status={occ.status} />
                                                        </div>
                                                        <ProfileChips profiles={occ.profiles} unknown={occ.unknown_profiles} />
                                                        <div className="text-xs text-subtle">
                                                            First seen {fmt(occ.first_seen)} · last seen {fmt(occ.last_seen)} · agent {occ.app_version || 'unknown'}
                                                        </div>
                                                        {occ.note ? (
                                                            <div className="text-xs text-muted">Note: {occ.note}</div>
                                                        ) : null}
                                                    </div>

                                                    {canManage && (
                                                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                                                            {occ.status !== 'acknowledged' && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="secondary"
                                                                    disabled={busy}
                                                                    onClick={() => onAct(occ, 'acknowledged')}
                                                                >
                                                                    <Check size={14} /> Acknowledge
                                                                </Button>
                                                            )}
                                                            {occ.status !== 'ignored' && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="secondary"
                                                                    disabled={busy}
                                                                    onClick={() => onAct(occ, 'ignored')}
                                                                >
                                                                    <EyeOff size={14} /> Ignore
                                                                </Button>
                                                            )}
                                                            {occ.status !== 'open' && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    disabled={busy}
                                                                    onClick={() => onAct(occ, 'open')}
                                                                >
                                                                    <RotateCcw size={14} /> Reopen
                                                                </Button>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            ))}

                                            {tool.occurrences_hidden > 0 && (
                                                <p className="px-1 text-xs text-subtle">
                                                    {tool.occurrences_hidden} more machine{tool.occurrences_hidden === 1 ? '' : 's'} carry this tool.
                                                    Narrow the search to work through them.
                                                </p>
                                            )}
                                        </div>
                                    </Td>
                                </Tr>
                            )}
                        </Fragment>
                    );
                })}
            </TBody>
        </Table>
    );
}

function MachinesView({ rows, fmt, canManage, minAgent, silentDays, onRemove }) {
    if (!rows.data.length) {
        return <NoReportsYet minAgent={minAgent} />;
    }

    return (
        <Table>
            <THead>
                <Tr>
                    <Th>Machine</Th>
                    <Th>Employee</Th>
                    <Th>State</Th>
                    <Th className="text-right">Alerts</Th>
                    <Th className="text-right">Watch</Th>
                    <Th className="text-right">Extensions</Th>
                    <Th className="text-right">Programs</Th>
                    <Th>Agent</Th>
                    <Th>Last report</Th>
                    {canManage && <Th className="text-right">Actions</Th>}
                </Tr>
            </THead>
            <TBody>
                {rows.data.map((m) => (
                    <Tr key={m.id}>
                        <Td>
                            <div className="font-medium text-body">{m.device}</div>
                            <div className="text-xs text-subtle">{m.platform || 'unknown platform'}</div>
                        </Td>
                        <Td className="text-muted">{m.user || '—'}</Td>
                        <Td>
                            <div className="flex flex-col gap-1">
                                <StateBadge state={m.state} />
                                {/* Why a machine is uncertifiable is the actionable part —
                                    never let "Unknown" read as a mystery. */}
                                {m.stale_agent && (
                                    <span className="text-xs text-warning">Agent older than {minAgent}</span>
                                )}
                                {!m.stale_agent && m.never_reported_extensions && (
                                    <span className="text-xs text-warning">Never reported extensions</span>
                                )}
                                {m.silent && (
                                    <span className="text-xs text-warning">Silent for over {silentDays} days</span>
                                )}
                            </div>
                        </Td>
                        <Td className="text-right tabular-nums">
                            {m.open_alerts > 0 ? <span className="font-medium text-danger">{m.open_alerts}</span> : <span className="text-subtle">0</span>}
                        </Td>
                        <Td className="text-right tabular-nums">
                            {m.watch > 0 ? <span className="text-info">{m.watch}</span> : <span className="text-subtle">0</span>}
                        </Td>
                        <Td className="text-right tabular-nums">
                            {m.stale_agent || m.never_reported_extensions ? (
                                <span className="text-warning" title="This agent cannot enumerate browser extensions.">unknown</span>
                            ) : (
                                (m.counts?.extensions ?? 0)
                            )}
                        </Td>
                        <Td className="text-right tabular-nums">{m.counts?.programs ?? 0}</Td>
                        <Td className="text-muted whitespace-nowrap">{m.app_version || 'unknown'}</Td>
                        <Td className="text-muted whitespace-nowrap">{fmt(m.last_seen)}</Td>
                        {canManage && (
                            <Td className="text-right">
                                <Button size="sm" variant="ghost" onClick={() => onRemove(m)}>
                                    <Trash2 size={14} /> Remove
                                </Button>
                            </Td>
                        )}
                    </Tr>
                ))}
            </TBody>
        </Table>
    );
}

function EmployeesView({ rows, fmt, minAgent, onDrill, onBack }) {
    if (rows.mode === 'items') {
        return (
            <>
                <div className="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                    <Button size="sm" variant="secondary" onClick={onBack}>
                        <ChevronLeft size={14} /> All employees
                    </Button>
                    <span className="text-sm text-muted">
                        Everything currently installed for{' '}
                        <span className="font-medium text-body">{rows.employee?.user || 'this employee'}</span>
                    </span>
                </div>

                {rows.data.length ? (
                    <Table>
                        <THead>
                            <Tr>
                                <Th>Item</Th>
                                <Th>Type</Th>
                                <Th>Machine</Th>
                                <Th>Browser profile</Th>
                                <Th>Publisher</Th>
                                <Th>Flag</Th>
                                <Th>Last seen</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {rows.data.map((item) => (
                                <Tr key={item.id}>
                                    <Td>
                                        <div className="font-medium text-body">{item.name}</div>
                                        {item.version ? <div className="text-xs text-subtle">v{item.version}</div> : null}
                                    </Td>
                                    <Td>
                                        <Badge tone="neutral">
                                            {item.kind === 'programs' ? <Package size={12} /> : <Puzzle size={12} />}
                                            {KIND_LABEL[item.kind] || item.kind}
                                        </Badge>
                                    </Td>
                                    <Td className="text-muted">{item.device}</Td>
                                    <Td>
                                        {item.kind === 'extensions' ? (
                                            <ProfileChips
                                                profiles={item.browser_profile ? [item.browser_profile] : []}
                                                unknown={item.browser_profile ? 0 : 1}
                                            />
                                        ) : (
                                            <span className="text-subtle">—</span>
                                        )}
                                    </Td>
                                    <Td className="text-muted">{item.publisher || '—'}</Td>
                                    <Td>
                                        {item.flagged ? (
                                            <span className="flex flex-wrap items-center gap-1">
                                                <SeverityBadge severity={item.severity} />
                                                {!item.alert && <Badge tone="info">watch</Badge>}
                                            </span>
                                        ) : (
                                            <span className="text-subtle">—</span>
                                        )}
                                    </Td>
                                    <Td className="text-muted whitespace-nowrap">{fmt(item.last_seen)}</Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                ) : (
                    <EmptyState
                        icon={<Puzzle size={20} />}
                        title="Nothing recorded for this employee"
                        description="Their machines have not reported an extension or program inventory yet."
                    />
                )}
            </>
        );
    }

    if (!rows.data.length) {
        return <NoReportsYet minAgent={minAgent} />;
    }

    return (
        <Table>
            <THead>
                <Tr>
                    <Th>Employee</Th>
                    <Th className="text-right">Machines</Th>
                    <Th className="text-right">Extensions</Th>
                    <Th className="text-right">Programs</Th>
                    <Th className="text-right">Flagged</Th>
                    <Th>Last report</Th>
                    <Th className="text-right">Inventory</Th>
                </Tr>
            </THead>
            <TBody>
                {rows.data.map((row) => (
                    <Tr key={row.user_id}>
                        <Td className="font-medium text-body">{row.user || 'Unknown user'}</Td>
                        <Td className="text-right tabular-nums">{row.machines}</Td>
                        <Td className="text-right tabular-nums">{row.extensions}</Td>
                        {/* Programs are shown alongside extensions on purpose: a mouse
                            jiggler is an installed program, and an extensions-only view
                            hid them entirely. */}
                        <Td className="text-right tabular-nums">{row.programs}</Td>
                        <Td className="text-right tabular-nums">
                            {row.flagged > 0 ? (
                                <span className={row.alerting > 0 ? 'font-medium text-danger' : 'text-warning'}>
                                    {row.flagged}
                                </span>
                            ) : (
                                <span className="text-subtle">0</span>
                            )}
                        </Td>
                        <Td className="text-muted whitespace-nowrap">{fmt(row.last_seen)}</Td>
                        <Td className="text-right">
                            <Button size="sm" variant="secondary" onClick={() => onDrill(row.user_id)}>
                                View {row.items} items
                            </Button>
                        </Td>
                    </Tr>
                ))}
            </TBody>
        </Table>
    );
}

function ExtensionsView({ rows, fmt, minAgent, onDrill, onBack }) {
    if (rows.mode === 'holders') {
        return (
            <>
                <div className="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                    <Button size="sm" variant="secondary" onClick={onBack}>
                        <ChevronLeft size={14} /> All extensions
                    </Button>
                    <span className="text-sm text-muted">
                        Who has <span className="font-medium text-body">{rows.extension}</span>
                    </span>
                </div>

                {rows.data.length ? (
                    <Table>
                        <THead>
                            <Tr>
                                <Th>Employee</Th>
                                <Th>Machine</Th>
                                <Th>Browser</Th>
                                <Th>Browser profile</Th>
                                <Th>Flag</Th>
                                <Th>Last seen</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {rows.data.map((row) => (
                                <Tr key={row.id}>
                                    <Td className="font-medium text-body">{row.user || 'Unknown user'}</Td>
                                    <Td className="text-muted">{row.device}</Td>
                                    <Td className="text-muted">{row.browser || '—'}</Td>
                                    <Td>
                                        <ProfileChips
                                            profiles={row.browser_profile ? [row.browser_profile] : []}
                                            unknown={row.browser_profile ? 0 : 1}
                                        />
                                    </Td>
                                    <Td>
                                        {row.flagged ? (
                                            <span className="flex flex-wrap items-center gap-1">
                                                <SeverityBadge severity={row.severity} />
                                                {!row.alert && <Badge tone="info">watch</Badge>}
                                            </span>
                                        ) : (
                                            <span className="text-subtle">—</span>
                                        )}
                                    </Td>
                                    <Td className="text-muted whitespace-nowrap">{fmt(row.last_seen)}</Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                ) : (
                    <EmptyState
                        icon={<Puzzle size={20} />}
                        title="Nobody has this extension any more"
                        description="It was uninstalled, or the machines that had it have stopped reporting."
                    />
                )}
            </>
        );
    }

    if (!rows.data.length) {
        return <NoReportsYet minAgent={minAgent} />;
    }

    return (
        <Table>
            <THead>
                <Tr>
                    <Th>Extension</Th>
                    <Th>Flag</Th>
                    <Th className="text-right">People</Th>
                    <Th className="text-right">Machines</Th>
                    <Th className="text-right">Profiles</Th>
                    <Th>Last seen</Th>
                    <Th className="text-right">Holders</Th>
                </Tr>
            </THead>
            <TBody>
                {rows.data.map((row) => (
                    <Tr key={row.key}>
                        <Td className="font-medium text-body">{row.name}</Td>
                        <Td>
                            {row.flagged ? (
                                <span className="flex flex-wrap items-center gap-1">
                                    <SeverityBadge severity={row.severity} />
                                    <Badge tone={row.alerting ? 'danger' : 'info'}>
                                        {row.alerting ? 'bans a profile' : 'watch only'}
                                    </Badge>
                                </span>
                            ) : (
                                <Badge tone="success">clean</Badge>
                            )}
                        </Td>
                        <Td className="text-right tabular-nums">{row.people}</Td>
                        <Td className="text-right tabular-nums">{row.machines}</Td>
                        <Td className="text-right tabular-nums">{row.profiles}</Td>
                        <Td className="text-muted whitespace-nowrap">{fmt(row.last_seen)}</Td>
                        <Td className="text-right">
                            <Button size="sm" variant="secondary" onClick={() => onDrill(row.key)}>
                                Who has it
                            </Button>
                        </Td>
                    </Tr>
                ))}
            </TBody>
        </Table>
    );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function ComplianceIndex({
    auth,
    view = 'findings',
    filters = {},
    options = {},
    summary = null,
    rows = null,
    preview = false,
}) {
    const page = usePage();

    // Preview mode ignores every incoming prop and renders entirely from
    // DEV_SAMPLE, so the page can be opened with no database behind it.
    const [previewView, setPreviewView] = useState('findings');
    const activeView = preview ? previewView : view;

    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const stats = preview ? DEV_SAMPLE.summary : summary;
    const data = preview ? DEV_SAMPLE.rows[previewView] : rows;

    const display = (!preview && page.props?.display) || DEFAULT_DISPLAY;
    const fmt = useMemo(() => makeFormatter(display), [display.timezone, display.format]);

    const opts = preview
        ? { statuses: ['live', 'open', 'acknowledged', 'ignored', 'resolved'], severities: ['all', 'critical', 'high', 'medium', 'low'], min_agent: '0.4.6', silent_after_days: 3 }
        : options;
    const minAgent = opts.min_agent || '0.4.6';

    const canManage = preview
        ? true
        : Boolean(user?.role === 'super_admin' || user?.permissions?.includes?.('compliance.manage'));

    const [busy, setBusy] = useState(false);
    const [search, setSearch] = useState(filters.q || '');
    const [removeTarget, setRemoveTarget] = useState(null);

    /**
     * One navigation helper for every filter, tab and page change. Partial
     * reload: only the active view's `rows` (and the echoed filters) come back,
     * so switching tabs never recomputes the summary aggregates.
     */
    const go = useCallback(
        (params, { resetPage = true } = {}) => {
            if (preview) return;
            const next = {
                view: activeView,
                q: search,
                status: filters.status,
                severity: filters.severity,
                employee: filters.employee ?? undefined,
                extension: filters.extension || undefined,
                ...params,
            };
            if (resetPage && next.page === undefined) delete next.page;

            router.get(href('monitoring.compliance'), next, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['rows', 'filters', 'view'],
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            });
        },
        [preview, activeView, search, filters.status, filters.severity, filters.employee, filters.extension],
    );

    const switchView = (key) => {
        if (preview) {
            setPreviewView(key);
            return;
        }
        // A tab change drops any drill-in so the new tab opens at its top level.
        go({ view: key, employee: undefined, extension: undefined });
    };

    /** Acknowledge / Ignore / Reopen, across every browser profile at once. */
    const act = (occurrence, status) => {
        if (preview) return;
        router.patch(
            href('monitoring.compliance.flag', occurrence.id),
            { status, ids: occurrence.ids },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['rows', 'summary', 'flash', 'errors'],
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    };

    const confirmRemove = () => {
        if (preview || !removeTarget) return;
        router.delete(href('monitoring.compliance.machine.destroy', removeTarget.id), {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setRemoveTarget(null);
            },
        });
    };

    const empty = { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: 0, to: 0 } };
    const rowset = data || empty;
    // `rows` is a closure prop: on a partial reload it is briefly absent, which
    // is exactly when the skeleton should show.
    const loading = !preview && (busy || data === null);

    return (
        <AuthenticatedLayout user={user} header="Machine compliance">
            <Head title="Machine compliance" />

            {/* FULL WIDTH. The source explicitly moved this page off a centred
                max-w-6xl column: the tables carry a lot of columns and the whole
                point is scanning a fleet at a glance. */}
            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {/* Fleet size is an INVENTORY count (machines → slot 3, blue, per the
                        cross-page contract). The three tiles beside it are TRUE STATUS
                        (danger / info-adjacent / warning) and deliberately stay on the
                        status-toned StatCard — "12 machines flagged" must never look
                        decorative. */}
                    <KpiCard
                        accent={3}
                        label="Machines reporting"
                        value={stats?.machines ?? 0}
                        sub={stats?.last_report ? `Last report ${fmt(stats.last_report)}` : 'No report received yet'}
                        icon={<Monitor className="h-[18px] w-[18px]" />}
                    />
                    <StatCard
                        label="Open alerts"
                        value={stats?.open_alerts ?? 0}
                        hint={`${stats?.flagged_machines ?? 0} machine(s) affected`}
                        icon={<ShieldAlert size={18} />}
                        className={stats?.open_alerts > 0 ? 'ring-1 ring-danger' : undefined}
                    />
                    <StatCard
                        label="Watch only"
                        value={stats?.watch ?? 0}
                        hint="VPNs and automation frameworks — recorded, never alerted"
                        icon={<AlertTriangle size={18} />}
                    />
                    {/* Deliberately its own tile. A fleet that is 40% unknown is not
                        a clean fleet, and burying that number is how you get a
                        profile banned. */}
                    <StatCard
                        label="Cannot be certified"
                        value={(stats?.stale_agents ?? 0) + (stats?.silent_machines ?? 0)}
                        hint={`${stats?.stale_agents ?? 0} on an agent older than ${minAgent}, ${stats?.silent_machines ?? 0} silent`}
                        icon={<HelpCircle size={18} />}
                        className={(stats?.stale_agents ?? 0) + (stats?.silent_machines ?? 0) > 0 ? 'ring-1 ring-warning' : undefined}
                    />
                </div>

                {(stats?.stale_agents ?? 0) > 0 && (
                    <div className="flex items-start gap-3 rounded-xl border border-line bg-warning-soft px-4 py-3">
                        <HelpCircle size={18} className="mt-0.5 shrink-0 text-warning" aria-hidden="true" />
                        <p className="text-sm text-warning">
                            {stats.stale_agents} machine{stats.stale_agents === 1 ? '' : 's'} run a desktop agent older than{' '}
                            {minAgent}, which cannot enumerate browser extensions at all. Their inventory is shown as
                            <span className="font-medium"> unknown</span>, never as clean — update the agent on those PCs
                            before treating the fleet as verified.
                        </p>
                    </div>
                )}

                <Card padded={false}>
                    <CardHeader
                        title="Endpoint inventory"
                        description="What is installed and running on every team machine, matched against the blocklist."
                    />

                    {/* The switcher lives on its own row rather than in the header
                        actions slot: four tabs overflow a narrow header, and the
                        wrapper scrolls itself so the page body never does. */}
                    <div className="overflow-x-auto border-b border-line px-5 py-3">
                        <Tabs tabs={TABS} value={activeView} onChange={switchView} size="sm" />
                    </div>

                    <div className="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                        <Field label="Search" className="min-w-[16rem] flex-1">
                            <div className="relative">
                                <Search
                                    size={15}
                                    className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-subtle"
                                    aria-hidden="true"
                                />
                                <Input
                                    className="pl-9"
                                    value={search}
                                    maxLength={80}
                                    placeholder={
                                        activeView === 'machines'
                                            ? 'PC name or employee…'
                                            : activeView === 'employees'
                                              ? 'Employee name…'
                                              : 'Tool or extension name…'
                                    }
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') go({ q: e.target.value });
                                    }}
                                />
                            </div>
                        </Field>

                        {activeView === 'findings' && (
                            <>
                                <Field label="Status" className="w-52">
                                    <Select
                                        value={filters.status || 'live'}
                                        onChange={(e) => go({ status: e.target.value })}
                                    >
                                        {(opts.statuses || []).map((s) => (
                                            <option key={s} value={s}>{STATUS_FILTER_LABEL[s] || s}</option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="Severity" className="w-44">
                                    <Select
                                        value={filters.severity || 'all'}
                                        onChange={(e) => go({ severity: e.target.value })}
                                    >
                                        {(opts.severities || []).map((s) => (
                                            <option key={s} value={s}>{SEVERITY_FILTER_LABEL[s] || s}</option>
                                        ))}
                                    </Select>
                                </Field>
                            </>
                        )}

                        <Button variant="secondary" onClick={() => go({ q: search })} disabled={busy}>
                            Apply
                        </Button>
                        {(search || filters.employee || filters.extension) && (
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    setSearch('');
                                    go({ q: '', employee: undefined, extension: undefined });
                                }}
                            >
                                Clear
                            </Button>
                        )}
                    </div>

                    {loading ? (
                        <TableSkeleton rows={6} cols={activeView === 'machines' ? 7 : 5} />
                    ) : activeView === 'machines' ? (
                        <MachinesView
                            rows={rowset}
                            fmt={fmt}
                            canManage={canManage}
                            minAgent={minAgent}
                            silentDays={opts.silent_after_days ?? 3}
                            onRemove={setRemoveTarget}
                        />
                    ) : activeView === 'employees' ? (
                        <EmployeesView
                            rows={rowset}
                            fmt={fmt}
                            minAgent={minAgent}
                            onDrill={(id) => go({ employee: id })}
                            onBack={() => go({ employee: undefined })}
                        />
                    ) : activeView === 'extensions' ? (
                        <ExtensionsView
                            rows={rowset}
                            fmt={fmt}
                            minAgent={minAgent}
                            onDrill={(key) => go({ extension: key })}
                            onBack={() => go({ extension: undefined })}
                        />
                    ) : (
                        <FindingsView
                            rows={rowset}
                            fmt={fmt}
                            canManage={canManage}
                            busy={busy}
                            onAct={act}
                        />
                    )}

                    <Pagination
                        meta={rowset.meta}
                        disabled={busy}
                        onPage={(p) => go({ page: p }, { resetPage: false })}
                    />
                </Card>
            </div>

            {/* Removing a machine deletes evidence, so it is always confirmed. */}
            <Modal show={Boolean(removeTarget)} maxWidth="md" onClose={() => setRemoveTarget(null)}>
                <div className="space-y-4 p-6">
                    <h2 className="text-lg font-semibold text-body">Remove this machine?</h2>
                    <p className="text-sm text-muted">
                        <span className="font-medium text-body">{removeTarget?.device}</span>
                        {removeTarget?.user ? ` (${removeTarget.user})` : ''} and its full compliance history —
                        inventory and findings — will be deleted permanently. Use this only for a PC that has been
                        decommissioned; a machine that is still in use will simply reappear on its next report.
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setRemoveTarget(null)}>Cancel</Button>
                        <Button variant="danger" disabled={busy} onClick={confirmRemove}>
                            <Trash2 size={14} /> Remove machine
                        </Button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
