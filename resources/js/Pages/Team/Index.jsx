import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Avatar from '@/Components/Avatar';
import { Eye, Search, Send, Users, Activity, Clock, UserCheck } from 'lucide-react';
import {
    Button,
    Card,
    CardHeader,
    Badge,
    StatCard,
    Table,
    THead,
    TBody,
    Tr,
    Th,
    Td,
    Input,
    EmptyState,
} from '@/Components/ui';
import { RankedBars } from '@/Components/charts';

const cx = (...a) => a.filter(Boolean).join(' ');

function fmtHm(seconds) {
    const s = Math.max(0, Math.floor(seconds || 0));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    if (h === 0) return `${m}m`;
    return `${h}h ${String(m).padStart(2, '0')}m`;
}

let DISPLAY = { timezone: 'Asia/Karachi', format: '12' };

function fmtRelative(iso) {
    if (!iso) return '—';
    const diffMs = Date.now() - new Date(iso).getTime();
    const mins = Math.round(diffMs / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins} min ago`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs} hr ago`;
    try {
        return new Date(iso).toLocaleDateString('en-US', { timeZone: DISPLAY.timezone, month: 'short', day: 'numeric' });
    } catch {
        return new Date(iso).toLocaleDateString();
    }
}

// Activity band → solid semantic fill. High = success, mid = warning, low = subtle.
function activityFill(percent) {
    if (percent >= 60) return 'bg-success';
    if (percent >= 30) return 'bg-warning';
    return 'bg-surface-2';
}

const PRESETS = [
    ['today', 'Today'],
    ['yesterday', 'Yesterday'],
    ['week', 'This week'],
    ['7d', 'Last 7 days'],
    ['month', 'This month'],
];

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true so the page renders fully
// offline for visual QA. Mirrors the TeamController@index Inertia payload
// (rows + totals + permissions + slack) with ~6 varied members: some live,
// some tracked-earlier, some idle, with top clients/apps + manual time so the
// ranked charts and the activity column have real spread. Never touched in
// normal rendering.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.com', is_super_admin: true, permissions: [] },
    start: '2026-07-22',
    end: '2026-07-22',
    range: 'today',
    rows: [
        {
            id: 2, name: 'Ayesha Khan', email: 'ayesha@staffvora.com', role: 'admin', designation: 'Engineering Lead',
            avatar_url: null, total_seconds: 21600, tracked_seconds: 21600, manual_seconds: 0, activity_percent: 78,
            top_client: { name: 'Acme Corp', total_seconds: 14400 }, top_app: 'VS Code',
            last_heartbeat_at: new Date(Date.now() - 30 * 1000).toISOString(), is_live: true,
            live: { client: 'Acme Corp', task_note: 'Auth refactor', started_at: new Date(Date.now() - 21600 * 1000).toISOString(), activity_percent: 78 },
        },
        {
            id: 3, name: 'Bilal Ahmed', email: 'bilal@staffvora.com', role: 'member', designation: 'Backend Developer',
            avatar_url: null, total_seconds: 19800, tracked_seconds: 19800, manual_seconds: 0, activity_percent: 64,
            top_client: { name: 'Nimbus SaaS', total_seconds: 12600 }, top_app: 'PhpStorm',
            last_heartbeat_at: new Date(Date.now() - 45 * 1000).toISOString(), is_live: true,
            live: { client: 'Nimbus SaaS', task_note: 'Queue worker', started_at: new Date(Date.now() - 19800 * 1000).toISOString(), activity_percent: 64 },
        },
        {
            id: 5, name: 'Maria Lopez', email: 'maria@staffvora.com', role: 'admin', designation: 'Design Lead',
            avatar_url: null, total_seconds: 16200, tracked_seconds: 14400, manual_seconds: 1800, activity_percent: 52,
            top_client: { name: 'Acme Corp', total_seconds: 9000 }, top_app: 'Figma',
            last_heartbeat_at: new Date(Date.now() - 40 * 60 * 1000).toISOString(), is_live: false, live: null,
        },
        {
            id: 7, name: 'Sana Riaz', email: 'sana@staffvora.com', role: 'member', designation: 'QA Engineer',
            avatar_url: null, total_seconds: 12600, tracked_seconds: 12600, manual_seconds: 0, activity_percent: 41,
            top_client: { name: 'Nimbus SaaS', total_seconds: 8100 }, top_app: 'Chrome',
            last_heartbeat_at: new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString(), is_live: false, live: null,
        },
        {
            id: 4, name: 'Chen Wei', email: 'chen@staffvora.com', role: 'member', designation: 'Frontend Developer',
            avatar_url: null, total_seconds: 9000, tracked_seconds: 9000, manual_seconds: 0, activity_percent: 33,
            top_client: { name: 'Orbit Media', total_seconds: 5400 }, top_app: 'VS Code',
            last_heartbeat_at: new Date(Date.now() - 5 * 60 * 60 * 1000).toISOString(), is_live: false, live: null,
        },
        {
            id: 6, name: 'Omar Farooq', email: 'omar@staffvora.com', role: 'member', designation: 'Operations',
            avatar_url: null, total_seconds: 0, tracked_seconds: 0, manual_seconds: 0, activity_percent: 0,
            top_client: null, top_app: null, last_heartbeat_at: null, is_live: false, live: null,
        },
    ],
    totals: { day: 79200, people_with_time: 5, people_live: 2, team_size: 6 },
    permissions: { view_screenshots: true, send_slack: true },
    slack: { configured: true, daily_enabled: true, daily_time: '09:00' },
};

export default function TeamIndex({
    auth,
    start,
    end,
    range,
    rows,
    totals,
    permissions,
    slack,
    preview = false,
}) {
    // In preview mode, ignore incoming props and render entirely from DEV_SAMPLE.
    const authUser = preview ? DEV_SAMPLE.user : auth?.user;
    start = preview ? DEV_SAMPLE.start : start;
    end = preview ? DEV_SAMPLE.end : end;
    range = preview ? DEV_SAMPLE.range : range;
    rows = preview ? DEV_SAMPLE.rows : rows;
    totals = preview ? DEV_SAMPLE.totals : totals;
    permissions = preview ? DEV_SAMPLE.permissions : permissions;
    slack = preview ? DEV_SAMPLE.slack : slack;

    DISPLAY = usePage().props.display || DISPLAY;
    const flash = preview ? {} : (usePage().props.flash || {});

    const [sending, setSending] = useState(false);
    const [query, setQuery] = useState('');
    const [nowTs, setNowTs] = useState(Date.now());
    const renderTsRef = useRef(Date.now());
    const [customStart, setCustomStart] = useState(start);
    const [customEnd, setCustomEnd] = useState(end);

    const can = (p) => authUser?.is_super_admin || authUser?.permissions?.includes(p);
    const canViewReports = can('reports.view');

    // Tick once a second so a Live member's tracked time counts up in place.
    useEffect(() => {
        const t = setInterval(() => setNowTs(Date.now()), 1000);
        return () => clearInterval(t);
    }, []);
    // Reset the tick baseline whenever fresh server rows arrive.
    useEffect(() => {
        renderTsRef.current = Date.now();
    }, [rows]);
    // Keep the custom-range inputs in sync with the active range.
    useEffect(() => {
        setCustomStart(start);
        setCustomEnd(end);
    }, [start, end]);

    // Live time only keeps growing when the range includes today.
    const todayStr = new Date().toLocaleDateString('en-CA', { timeZone: DISPLAY.timezone });
    const includesToday = end >= todayStr;

    const q = query.trim().toLowerCase();
    const visibleRows = q
        ? rows.filter((r) => r.name.toLowerCase().includes(q) || (r.designation || '').toLowerCase().includes(q))
        : rows;

    const trackedSecondsFor = (row) =>
        row.is_live && includesToday
            ? row.total_seconds + Math.max(0, Math.floor((nowTs - renderTsRef.current) / 1000))
            : row.total_seconds;

    // ------------------------------------------------------------------
    // Ranked chart data — derived from the same rows the table renders, so
    // the charts always match the numbers below. Members rank by tracked
    // time; clients aggregate each member's top client; apps count how many
    // members had that app as their primary.
    // ------------------------------------------------------------------
    const memberRanks = useMemo(() => {
        const withTime = rows.filter((r) => r.total_seconds > 0);
        const max = Math.max(...withTime.map((r) => r.total_seconds), 1);
        return withTime
            .slice()
            .sort((a, b) => b.total_seconds - a.total_seconds)
            .slice(0, 8)
            .map((r) => ({
                label: r.name,
                value: fmtHm(r.total_seconds),
                pct: (r.total_seconds / max) * 100,
                meta: `${r.activity_percent}% activity${r.top_client ? ` · ${r.top_client.name}` : ''}`,
            }));
    }, [rows]);

    const clientRanks = useMemo(() => {
        const agg = new Map();
        rows.forEach((r) => {
            if (!r.top_client) return;
            const key = r.top_client.name;
            const prev = agg.get(key) || { seconds: 0, members: 0 };
            agg.set(key, { seconds: prev.seconds + (r.top_client.total_seconds || 0), members: prev.members + 1 });
        });
        const list = [...agg.entries()].sort((a, b) => b[1].seconds - a[1].seconds);
        const max = list[0]?.[1].seconds || 1;
        return list.slice(0, 8).map(([name, v]) => ({
            label: name,
            value: fmtHm(v.seconds),
            pct: (v.seconds / max) * 100,
            meta: `${v.members} member${v.members > 1 ? 's' : ''}`,
        }));
    }, [rows]);

    const appRanks = useMemo(() => {
        const agg = new Map();
        rows.forEach((r) => {
            if (!r.top_app) return;
            agg.set(r.top_app, (agg.get(r.top_app) || 0) + 1);
        });
        const list = [...agg.entries()].sort((a, b) => b[1] - a[1]);
        const max = list[0]?.[1] || 1;
        return list.slice(0, 8).map(([name, count]) => ({
            label: name,
            value: `${count}`,
            pct: (count / max) * 100,
            meta: `${count} member${count > 1 ? 's' : ''}`,
        }));
    }, [rows]);

    const go = (params) => {
        if (preview) return;
        router.get(route('team.index'), params, { preserveScroll: true });
    };
    const applyCustom = () => {
        if (customStart && customEnd) go({ start: customStart, end: customEnd });
    };

    const sendDigest = () => {
        if (preview) return;
        const label = range === 'custom' ? `${start} → ${end}` : (PRESETS.find((p) => p[0] === range)?.[1] || range);
        if (!confirm(`Send activity digest for ${label} to Slack?`)) return;
        setSending(true);
        router.post(
            route('team.slack-digest'),
            range === 'custom' ? { start, end } : { range },
            { preserveScroll: true, onFinish: () => setSending(false) }
        );
    };

    const appsHref = preview ? '#' : route('team.apps');

    return (
        <AuthenticatedLayout user={authUser} header={<h2 className="text-xl font-semibold text-body">Team Performance</h2>}>
            <Head title="Team Performance" />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                {flash.success && (
                    <div className="rounded-lg border border-line bg-success-soft px-4 py-2.5 text-sm text-success">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-lg border border-line bg-danger-soft px-4 py-2.5 text-sm text-danger">
                        {flash.error}
                    </div>
                )}

                {/* View switch + Slack action */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="inline-flex items-center gap-1 rounded-lg bg-surface-2 p-1" role="tablist" aria-label="Team views">
                        <span
                            role="tab"
                            aria-selected="true"
                            className="inline-flex items-center rounded-md bg-surface px-3 py-1.5 text-sm font-medium text-body shadow-sm"
                        >
                            Members
                        </span>
                        <Link
                            href={appsHref}
                            role="tab"
                            aria-selected="false"
                            className="inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium text-muted transition hover:text-body"
                        >
                            Apps &amp; URLs
                        </Link>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {slack?.daily_enabled && (
                            <Badge tone="info" title={`Auto-posts daily at ${slack.daily_time}`}>Daily Slack digest on</Badge>
                        )}
                        {permissions?.send_slack && slack?.configured && (
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={sendDigest}
                                loading={sending}
                                icon={<Send className="h-3.5 w-3.5" />}
                            >
                                {sending ? 'Sending…' : 'Send Slack digest'}
                            </Button>
                        )}
                    </div>
                </div>

                {/* KPI row */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Live now"
                        value={totals.people_live}
                        hint="Tracking right now"
                        icon={<UserCheck className="h-4 w-4" />}
                    />
                    <StatCard
                        label="Tracked time"
                        value={`${totals.people_with_time} of ${totals.team_size}`}
                        hint="Members with time in range"
                        icon={<Users className="h-4 w-4" />}
                    />
                    <StatCard
                        label="Team total"
                        value={fmtHm(totals.day)}
                        hint="Combined hours in range"
                        icon={<Clock className="h-4 w-4" />}
                    />
                    <StatCard
                        label="Team size"
                        value={totals.team_size}
                        hint="Everyone on the roster"
                        icon={<Activity className="h-4 w-4" />}
                    />
                </div>

                {/* Range controls */}
                <Card padded>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="mr-1 text-xs uppercase tracking-wide text-muted">Range</span>
                        {PRESETS.map(([key, label]) => (
                            <Button
                                key={key}
                                variant={range === key ? 'primary' : 'secondary'}
                                size="sm"
                                onClick={() => go({ range: key })}
                            >
                                {label}
                            </Button>
                        ))}
                        <div className="mx-1 hidden h-6 w-px bg-line sm:block" />
                        <input
                            type="date"
                            value={customStart || ''}
                            onChange={(e) => setCustomStart(e.target.value)}
                            className="rounded-md border border-line bg-surface-2 px-2.5 py-1.5 text-xs text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent [color-scheme:light] dark:[color-scheme:dark]"
                            aria-label="Range start"
                        />
                        <span className="text-subtle">→</span>
                        <input
                            type="date"
                            value={customEnd || ''}
                            onChange={(e) => setCustomEnd(e.target.value)}
                            className="rounded-md border border-line bg-surface-2 px-2.5 py-1.5 text-xs text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent [color-scheme:light] dark:[color-scheme:dark]"
                            aria-label="Range end"
                        />
                        <Button
                            variant={range === 'custom' ? 'primary' : 'secondary'}
                            size="sm"
                            onClick={applyCustom}
                        >
                            Apply
                        </Button>
                        <span className="ml-auto text-xs text-subtle">{start} → {end}</span>
                    </div>
                </Card>

                {/* Ranked charts — primary chart surface */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card title="Top members" description="By tracked time in range">
                        <RankedBars items={memberRanks} />
                    </Card>
                    <Card title="Top clients" description="By tracked time in range">
                        <RankedBars items={clientRanks} />
                    </Card>
                    <Card title="Top apps" description="Members' primary application">
                        <RankedBars items={appRanks} />
                    </Card>
                </div>

                {/* Members table */}
                <Card padded={false}>
                    <CardHeader
                        title="Team members"
                        description={`${totals.people_live} live · ${totals.people_with_time} of ${totals.team_size} tracked · ${fmtHm(totals.day)} total`}
                        actions={
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                                <Input
                                    type="text"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Search member…"
                                    className="w-52 pl-8"
                                    aria-label="Search member"
                                />
                            </div>
                        }
                    />

                    {visibleRows.length === 0 ? (
                        <EmptyState
                            title={q ? 'No matching members' : 'No team activity yet'}
                            description={q ? `No members match “${query}”.` : 'No team members tracked time in this range.'}
                        />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>Member</Th>
                                    <Th>Status</Th>
                                    <Th className="text-right">Tracked</Th>
                                    <Th>Activity</Th>
                                    <Th>Top client</Th>
                                    <Th>Top app</Th>
                                    <Th>Last seen</Th>
                                    <Th className="text-right">Timeline</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {visibleRows.map((row) => (
                                    <Tr key={row.id}>
                                        <Td>
                                            {canViewReports ? (
                                                <Link
                                                    href={preview ? '#' : route('work-hours.report', { userIds: [row.id] })}
                                                    className="group flex items-center gap-3"
                                                    title={`View ${row.name}'s report`}
                                                >
                                                    <Avatar user={{ name: row.name, avatar_url: row.avatar_url }} size="sm" />
                                                    <div className="min-w-0">
                                                        <div className="truncate font-medium text-body group-hover:text-accent">{row.name}</div>
                                                        {row.designation && (
                                                            <div className="truncate text-[11px] text-subtle">{row.designation}</div>
                                                        )}
                                                    </div>
                                                </Link>
                                            ) : (
                                                <div className="flex items-center gap-3">
                                                    <Avatar user={{ name: row.name, avatar_url: row.avatar_url }} size="sm" />
                                                    <div className="min-w-0">
                                                        <div className="truncate font-medium text-body">{row.name}</div>
                                                        {row.designation && (
                                                            <div className="truncate text-[11px] text-subtle">{row.designation}</div>
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        </Td>
                                        <Td>
                                            {row.is_live ? (
                                                <Badge tone="success" dot>Live</Badge>
                                            ) : row.total_seconds > 0 ? (
                                                <span className="text-xs text-muted">Tracked earlier</span>
                                            ) : (
                                                <span className="text-xs text-subtle">Idle</span>
                                            )}
                                            {row.is_live && row.live?.client && (
                                                <div className="mt-1 max-w-[220px] truncate text-[11px] text-subtle">{row.live.client}</div>
                                            )}
                                        </Td>
                                        <Td className="text-right">
                                            <span className="font-mono tabular-nums text-body">{fmtHm(trackedSecondsFor(row))}</span>
                                            {row.manual_seconds > 0 && (
                                                <div className="text-[10px] text-warning" title="Manually logged work-diary hours — count as time but 0% activity">
                                                    {fmtHm(row.manual_seconds)} manual
                                                </div>
                                            )}
                                        </Td>
                                        <Td>
                                            <div className="flex items-center gap-2">
                                                <div className="h-1.5 w-24 overflow-hidden rounded-full bg-surface-2">
                                                    <div className={cx('h-full rounded-full', activityFill(row.activity_percent))} style={{ width: `${Math.min(100, row.activity_percent)}%` }} />
                                                </div>
                                                <span className="text-xs tabular-nums text-muted">{row.activity_percent}%</span>
                                            </div>
                                        </Td>
                                        <Td className="text-xs text-muted">
                                            {row.top_client ? (
                                                <span>{row.top_client.name} <span className="text-subtle">· {fmtHm(row.top_client.total_seconds)}</span></span>
                                            ) : '—'}
                                        </Td>
                                        <Td className="text-xs text-muted">{row.top_app || '—'}</Td>
                                        <Td className="text-xs text-subtle">{fmtRelative(row.last_heartbeat_at)}</Td>
                                        <Td className="text-right">
                                            <Button
                                                as={Link}
                                                href={preview ? '#' : route('timeline.index', { user_id: row.id, date: end })}
                                                variant="secondary"
                                                size="sm"
                                                icon={<Eye className="h-3.5 w-3.5" />}
                                            >
                                                Timeline
                                            </Button>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
