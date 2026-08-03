import React, { useEffect, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Avatar from '@/Components/Avatar';
import {
    Button,
    Card,
    CardHeader,
    Badge,
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
import { TrendChart, ActivityStrip, Gauge, GradientBar } from '@/Components/charts';
import {
    Activity,
    Clock,
    Target,
    Wallet,
    Lightbulb,
    TrendingUp,
    CalendarRange,
    Users,
    AlertTriangle,
    Info,
    CheckCircle2,
} from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

// Same concept, same colour, on every page: the dashboard's time-breakdown
// donut wears tracked=green (2), manual=blue (3), idle=amber (4), and this
// ribbon shows the same concepts, so it must agree. "Unfilled vs target"
// deliberately gets no accent — it is an absence, not an entity, and stays on
// the neutral tone class.
const RIBBON_ACCENTS = { tracked: 2, manual: 3, idle: 4 };

/**
 * INSIGHTS v1 — the management read of tracked time.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS PAGE COMPUTES NOTHING
 * ─────────────────────────────────────────────────────────────────────────────
 * Every hour, percentage, target and highlight arrives already aggregated from
 * InsightsAggregator (all of it SUM/COUNT/GROUP BY in SQL). The only arithmetic
 * here is turning a number into a bar width. If a figure is missing, the answer
 * is a real "not measured" state — never a client-side substitute, because a
 * number invented in the browser is indistinguishable from a real one.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NULL IS A FIRST-CLASS VALUE
 * ─────────────────────────────────────────────────────────────────────────────
 *   utilization === null  → nobody in scope has a shift length set. "No target
 *                           set", never 0% and never an assumed 8-hour day.
 *   activity    === null  → no tracked sessions in range. Manual time carries no
 *                           activity signal, so this is genuinely unknown, not
 *                           zero.
 * Both look visibly different from a real low reading.
 */

const PRESETS = [
    { key: '7d', label: 'Last 7 days' },
    { key: '30d', label: 'Last 30 days' },
    { key: 'week', label: 'This week' },
    { key: 'month', label: 'This month' },
    { key: 'last_month', label: 'Last month' },
    { key: '90d', label: 'Last 90 days' },
];

const HIGHLIGHT_ICON = {
    success: CheckCircle2,
    warning: AlertTriangle,
    info: Info,
};

// ---------------------------------------------------------------------------
// DEV_SAMPLE — rendered ONLY when `preview` is true (the local /_insights
// route), so the page can be reviewed offline with no database behind it.
// Mirrors the InsightsController payload exactly. Never touched in normal use.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = (() => {
    const trend = [];
    const base = new Date('2026-07-04T00:00:00Z');
    for (let i = 0; i < 30; i++) {
        const d = new Date(base.getTime() + i * 86400000);
        const dow = d.getUTCDay();
        const value = dow === 0 ? 0 : Math.round((28 + Math.sin(i / 2.4) * 9 + (i % 5) * 1.6) * 10) / 10;
        trend.push({
            date: d.toISOString().slice(0, 10),
            label: d.toLocaleDateString('en-US', { timeZone: 'UTC', month: 'short', day: 'numeric' }),
            value,
        });
    }

    return {
        user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.com', is_super_admin: true, permissions: [] },
        range: { start: '2026-07-04', end: '2026-08-02', days: 30, working_days: 26, max_days: 92, preset: '30d' },
        filters: { user_id: null, team_id: null },
        people: [
            { id: 2, name: 'Ayesha Khan' },
            { id: 3, name: 'Bilal Ahmed' },
            { id: 4, name: 'Chen Wei' },
            { id: 5, name: 'Maria Lopez' },
            { id: 6, name: 'Omar Farooq' },
        ],
        teams: [
            { id: 1, name: 'Engineering' },
            { id: 2, name: 'Design' },
        ],
        rows: [
            {
                id: 2, name: 'Ayesha Khan', avatar_url: null, designation: 'Engineering Lead', team_id: 1,
                worked_hours: 214.5, billable_hours: 181.25, non_billable_hours: 33.25, tracked_hours: 198.5,
                manual_hours: 16, days_logged: 25, target_hours: 208, expected_daily_hours: 8, eligible_days: 26,
                utilization: 103.1, billable_ratio: 84.5, activity_percent: 71, tracked_seconds: 714600,
            },
            {
                id: 3, name: 'Bilal Ahmed', avatar_url: null, designation: 'Backend Developer', team_id: 1,
                worked_hours: 196.75, billable_hours: 150.5, non_billable_hours: 46.25, tracked_hours: 190,
                manual_hours: 6.75, days_logged: 24, target_hours: 208, expected_daily_hours: 8, eligible_days: 26,
                utilization: 94.6, billable_ratio: 76.5, activity_percent: 63, tracked_seconds: 684000,
            },
            {
                id: 5, name: 'Maria Lopez', avatar_url: null, designation: 'Design Lead', team_id: 2,
                worked_hours: 172.25, billable_hours: 121, non_billable_hours: 51.25, tracked_hours: 148,
                manual_hours: 24.25, days_logged: 23, target_hours: 208, expected_daily_hours: 8, eligible_days: 26,
                utilization: 82.8, billable_ratio: 70.2, activity_percent: 48, tracked_seconds: 532800,
            },
            {
                id: 4, name: 'Chen Wei', avatar_url: null, designation: 'Frontend Developer', team_id: 1,
                worked_hours: 141.5, billable_hours: 92.75, non_billable_hours: 48.75, tracked_hours: 139,
                manual_hours: 2.5, days_logged: 21, target_hours: 208, expected_daily_hours: 8, eligible_days: 26,
                utilization: 68, billable_ratio: 65.5, activity_percent: 39, tracked_seconds: 500400,
            },
            {
                id: 6, name: 'Omar Farooq', avatar_url: null, designation: 'Operations', team_id: 2,
                worked_hours: 88, billable_hours: 12, non_billable_hours: 76, tracked_hours: 40,
                manual_hours: 48, days_logged: 18, target_hours: null, expected_daily_hours: null, eligible_days: 26,
                utilization: null, billable_ratio: 13.6, activity_percent: 44, tracked_seconds: 144000,
            },
        ],
        totals: {
            people: 5, people_with_time: 5, people_with_target: 4, people_without_target: 1,
            worked_hours: 813, billable_hours: 557.5, non_billable_hours: 255.5,
            tracked_hours: 715.5, manual_hours: 97.5, target_hours: 832,
            utilization: 87.1, billable_ratio: 68.6, activity_percent: 57, tracked_seconds: 2575800,
            working_days: 26, avg_hours_per_person: 162.6,
        },
        trend,
        ribbon: [
            { label: 'Active', pct: 407.8, tone: 'tracked' },
            { label: 'Tracked, low input', pct: 307.7, tone: 'idle' },
            { label: 'Manual', pct: 97.5, tone: 'manual' },
            { label: 'Unfilled vs target', pct: 19, tone: 'untracked' },
        ],
        categories: [
            { id: 1, name: 'Client Work', hours: 528.5, billable_hours: 528.5, share: 65.0 },
            { id: 2, name: 'Internal/Admin', hours: 122.25, billable_hours: 0, share: 15.0 },
            { id: 5, name: 'Meetings', hours: 89, billable_hours: 21, share: 10.9 },
            { id: 4, name: 'Training/Onboarding', hours: 44.25, billable_hours: 8, share: 5.4 },
            { id: 3, name: 'Business Development', hours: 29, billable_hours: 0, share: 3.6 },
        ],
        highlights: [
            {
                key: 'most_improved_activity', tone: 'success',
                title: 'Bilal Ahmed improved most on activity',
                detail: '55% in the first half of the range, 71% in the second — up 16 points.',
            },
            {
                key: 'top_billable_ratio', tone: 'info',
                title: 'Ayesha Khan has the highest billable share',
                detail: '85% of 214.5h billable, against a team average of 69%.',
            },
            {
                key: 'under_target', tone: 'warning',
                title: '2 people are under target',
                detail: 'Chen Wei logged 141.5h of a 208h target (68%) over 26 working days.',
            },
            {
                key: 'team_activity', tone: 'success',
                title: 'Team activity is 57% across tracked time',
                detail: 'At or above the 50% healthy band over 715.5h of tracked work.',
            },
            {
                key: 'dominant_category', tone: 'info',
                title: 'Client Work took 65% of the time',
                detail: '528.5h of 813h, spread across 5 categories.',
            },
            {
                key: 'missing_targets', tone: 'warning',
                title: '1 of 5 people have no target',
                detail: 'Utilization is calculated only for people whose shift sets a length. Set shift hours on the person or their named shift to include them.',
            },
        ],
        thresholds: {
            activity_healthy: 50, activity_low: 30,
            utilization_on_target: 90, utilization_under: 80,
        },
    };
})();

// --- small formatters (presentation only) ----------------------------------

function fmtHours(value) {
    if (value === null || value === undefined) return '—';
    const n = Number(value);
    if (Number.isNaN(n)) return '—';
    return `${n.toLocaleString(undefined, { maximumFractionDigits: 1 })}h`;
}

function fmtPct(value, digits = 0) {
    if (value === null || value === undefined) return '—';
    const n = Number(value);
    if (Number.isNaN(n)) return '—';
    return `${n.toFixed(digits)}%`;
}

function utilizationTone(utilization, thresholds) {
    if (utilization === null || utilization === undefined) return 'neutral';
    if (utilization >= thresholds.utilization_on_target) return 'success';
    if (utilization >= thresholds.utilization_under) return 'info';
    return 'warning';
}

function activityTone(activity, thresholds) {
    if (activity === null || activity === undefined) return 'neutral';
    if (activity >= thresholds.activity_healthy) return 'success';
    if (activity >= thresholds.activity_low) return 'info';
    return 'warning';
}

const BAR_FILL = {
    success: 'bg-success',
    info: 'bg-accent',
    warning: 'bg-warning',
    neutral: 'bg-surface-2',
};

/** A slim utilization bar. 100% of the track = the person's own target. */
function UtilizationBar({ utilization, thresholds }) {
    if (utilization === null || utilization === undefined) {
        return <span className="text-xs text-subtle">No target set</span>;
    }

    const tone = utilizationTone(utilization, thresholds);
    // Over-target work is real; cap only the DRAWING so the track stays readable.
    const width = Math.max(2, Math.min(100, utilization));

    return (
        <div className="flex items-center gap-2 min-w-[9rem]">
            <div className="h-2 flex-1 rounded-md bg-surface-2 overflow-hidden">
                <div className={cx('h-full rounded-md', BAR_FILL[tone])} style={{ width: `${width}%` }} />
            </div>
            <span className="w-12 shrink-0 text-right text-xs font-semibold tabular-nums text-body">
                {fmtPct(utilization)}
            </span>
        </div>
    );
}

function LoadingSkeleton() {
    return (
        <div className="space-y-6" aria-busy="true" aria-live="polite">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {[0, 1, 2, 3].map((i) => (
                    <div key={i} className="bg-surface border border-line rounded-xl p-5 space-y-3">
                        <Skeleton className="h-3 w-24" />
                        <Skeleton className="h-8 w-32" />
                        <Skeleton className="h-3 w-40" />
                    </div>
                ))}
            </div>
            <div className="grid gap-6 xl:grid-cols-3">
                <div className="bg-surface border border-line rounded-xl p-5 space-y-4">
                    <Skeleton className="h-4 w-32" />
                    <Skeleton className="h-40 w-full" rounded="rounded-lg" />
                </div>
                <div className="xl:col-span-2 bg-surface border border-line rounded-xl p-5 space-y-4">
                    <Skeleton className="h-4 w-40" />
                    <Skeleton className="h-56 w-full" rounded="rounded-lg" />
                </div>
            </div>
            <div className="bg-surface border border-line rounded-xl p-5 space-y-3">
                <Skeleton className="h-4 w-36" />
                {[0, 1, 2, 3, 4].map((i) => (
                    <Skeleton key={i} className="h-10 w-full" />
                ))}
            </div>
        </div>
    );
}

export default function InsightsIndex({
    auth,
    range,
    filters,
    people,
    teams,
    rows,
    totals,
    trend,
    ribbon,
    categories,
    highlights,
    thresholds,
    preview = false,
}) {
    // Preview mode ignores incoming props entirely and renders from DEV_SAMPLE.
    const authUser = preview ? DEV_SAMPLE.user : auth?.user;
    range = preview ? DEV_SAMPLE.range : range;
    filters = preview ? DEV_SAMPLE.filters : filters;
    people = preview ? DEV_SAMPLE.people : people || [];
    teams = preview ? DEV_SAMPLE.teams : teams || [];
    rows = preview ? DEV_SAMPLE.rows : rows || [];
    totals = preview ? DEV_SAMPLE.totals : totals;
    trend = preview ? DEV_SAMPLE.trend : trend || [];
    ribbon = preview ? DEV_SAMPLE.ribbon : ribbon || [];
    categories = preview ? DEV_SAMPLE.categories : categories || [];
    highlights = preview ? DEV_SAMPLE.highlights : highlights || [];
    thresholds = preview ? DEV_SAMPLE.thresholds : thresholds;

    const [loading, setLoading] = useState(false);
    const [customStart, setCustomStart] = useState(range?.start || '');
    const [customEnd, setCustomEnd] = useState(range?.end || '');

    useEffect(() => {
        setCustomStart(range?.start || '');
        setCustomEnd(range?.end || '');
    }, [range?.start, range?.end]);

    // The server clamps to range.max_days regardless; this only warns BEFORE the
    // round-trip so the picker explains itself instead of silently narrowing.
    const customSpanDays = useMemo(() => {
        if (!customStart || !customEnd) return null;
        const a = Date.parse(`${customStart}T00:00:00Z`);
        const b = Date.parse(`${customEnd}T00:00:00Z`);
        if (Number.isNaN(a) || Number.isNaN(b)) return null;
        return Math.floor(Math.abs(b - a) / 86400000) + 1;
    }, [customStart, customEnd]);

    const overCap = customSpanDays !== null && customSpanDays > (range?.max_days || 92);

    const go = (params) => {
        if (preview) return;
        setLoading(true);
        router.get(route('insights.index'), params, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setLoading(false),
        });
    };

    const filterParams = () => {
        const base = {};
        if (filters?.user_id) base.user_id = filters.user_id;
        if (filters?.team_id) base.team_id = filters.team_id;
        return base;
    };

    const rangeParams = () =>
        range?.preset === 'custom' ? { start: range.start, end: range.end } : { range: range?.preset || '30d' };

    const selectPreset = (key) => go({ ...filterParams(), range: key });

    const applyCustom = () => {
        if (!customStart || !customEnd) return;
        go({ ...filterParams(), start: customStart, end: customEnd });
    };

    const selectPerson = (value) => {
        const params = { ...rangeParams() };
        if (value) params.user_id = value;
        go(params);
    };

    const selectTeam = (value) => {
        const params = { ...rangeParams() };
        if (value) params.team_id = value;
        go(params);
    };

    const clearFilter = () => go(rangeParams());

    // ── The three genuinely different empty states ───────────────────────────
    const noPeople = totals?.people === 0;
    const noTime = !noPeople && (totals?.worked_hours || 0) <= 0;

    const trendPoints = useMemo(
        () => (trend || []).map((d) => ({ label: d.label, value: Number(d.value) || 0 })),
        [trend]
    );

    const trendAvg = useMemo(() => {
        const withWork = trendPoints.filter((p) => p.value > 0);
        if (withWork.length === 0) return undefined;
        return Math.round((withWork.reduce((s, p) => s + p.value, 0) / withWork.length) * 10) / 10;
    }, [trendPoints]);

    const categoryBars = useMemo(
        () =>
            (categories || []).slice(0, 8).map((c) => ({
                label: c.name,
                value: fmtHours(c.hours),
                pct: Number(c.share) || 0,
                meta:
                    c.billable_hours > 0
                        ? `${fmtHours(c.billable_hours)} billable · ${fmtPct(c.share, 1)} of all time`
                        : `Non-billable · ${fmtPct(c.share, 1)} of all time`,
            })),
        [categories]
    );

    // GradientBar slot per category — color follows the ENTITY, not its rank:
    // slots are assigned in fixed (alphabetical) order over the displayed set
    // (the Dashboard's top-projects precedent), so two categories swapping rank
    // between ranges keep their colors. Slots are never cycled past 5 — any
    // extra categories (list is capped at 8) wear the neutral subtle token.
    const categorySlots = useMemo(() => {
        const names = categoryBars
            .map((c) => c.label)
            .slice()
            .sort((a, b) => String(a).localeCompare(String(b)));
        const map = new Map();
        names.forEach((name, i) => map.set(name, i < 5 ? i + 1 : null));
        return map;
    }, [categoryBars]);

    const billableSplit = useMemo(() => {
        const billable = Number(totals?.billable_hours) || 0;
        const nonBillable = Number(totals?.non_billable_hours) || 0;
        const sum = billable + nonBillable;
        return { billable, nonBillable, sum, pct: sum > 0 ? (billable / sum) * 100 : 0 };
    }, [totals]);

    const activeFilterLabel = useMemo(() => {
        if (filters?.user_id) return people.find((p) => p.id === filters.user_id)?.name || 'Selected person';
        if (filters?.team_id) return teams.find((t) => t.id === filters.team_id)?.name || 'Selected team';
        return null;
    }, [filters, people, teams]);

    return (
        <AuthenticatedLayout user={authUser} header="Insights">
            <Head title="Insights" />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                {/* ── Range + filters ─────────────────────────────────────── */}
                <Card padded={false}>
                    <div className="flex flex-wrap items-end justify-between gap-4 p-5">
                        <div className="space-y-3">
                            <Tabs
                                tabs={PRESETS}
                                value={range?.preset}
                                onChange={selectPreset}
                                size="sm"
                                aria-label="Insights date range"
                            />
                            <div className="flex flex-wrap items-end gap-2">
                                <div className="w-40">
                                    <Field label="From">
                                        <Input
                                            type="date"
                                            value={customStart}
                                            max={customEnd || undefined}
                                            onChange={(e) => setCustomStart(e.target.value)}
                                        />
                                    </Field>
                                </div>
                                <div className="w-40">
                                    <Field label="To">
                                        <Input
                                            type="date"
                                            value={customEnd}
                                            min={customStart || undefined}
                                            onChange={(e) => setCustomEnd(e.target.value)}
                                        />
                                    </Field>
                                </div>
                                <Button variant="secondary" onClick={applyCustom} disabled={!customStart || !customEnd}>
                                    Apply
                                </Button>
                            </div>
                            {overCap ? (
                                <p className="text-xs text-warning">
                                    {`Ranges are capped at ${range?.max_days || 92} days — the most recent ${range?.max_days || 92} days of this selection will be shown.`}
                                </p>
                            ) : null}
                        </div>

                        <div className="flex flex-wrap items-end gap-2">
                            <div className="w-52">
                                <Field label="Person">
                                    <Select
                                        value={filters?.user_id || ''}
                                        onChange={(e) => selectPerson(e.target.value)}
                                    >
                                        <option value="">Everyone</option>
                                        {people.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            </div>
                            <div className="w-44">
                                <Field label="Team">
                                    <Select value={filters?.team_id || ''} onChange={(e) => selectTeam(e.target.value)}>
                                        <option value="">All teams</option>
                                        {teams.map((t) => (
                                            <option key={t.id} value={t.id}>
                                                {t.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            </div>
                            {activeFilterLabel ? (
                                <Button variant="ghost" onClick={clearFilter}>
                                    Clear
                                </Button>
                            ) : null}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3 text-xs text-muted">
                        <CalendarRange size={14} className="shrink-0" />
                        <span className="tabular-nums">{`${range?.start} → ${range?.end}`}</span>
                        <span className="text-subtle">·</span>
                        <span>{`${range?.days} days, ${range?.working_days} working`}</span>
                        <span className="text-subtle">·</span>
                        <Users size={14} className="shrink-0" />
                        <span>{`${totals?.people ?? 0} active ${totals?.people === 1 ? 'person' : 'people'}`}</span>
                        {activeFilterLabel ? (
                            <Badge tone="accent">{activeFilterLabel}</Badge>
                        ) : null}
                    </div>
                </Card>

                {loading ? (
                    <LoadingSkeleton />
                ) : noPeople ? (
                    <Card>
                        <EmptyState
                            icon={<Users size={20} />}
                            title="Nobody to measure yet"
                            description="Insights compares each active person's hours against their shift. Add people to the workspace — or clear the filter — and this fills in."
                        />
                    </Card>
                ) : noTime ? (
                    <Card>
                        <EmptyState
                            icon={<Clock size={20} />}
                            title="No time logged in this range"
                            description="Once someone tracks with the desktop app or adds time to their work diary, utilization, activity and the billable split appear here."
                        />
                    </Card>
                ) : (
                    <>
                        {/* ── Headline numbers ─────────────────────────────────
                            KpiCards on the cross-page color contract:
                            worked hours → slot 1 (time), utilization vs target
                            → slot 3 (people against their own shift), activity
                            → slot 2 (activity/rates), billable → slot 5 (money).
                            Value-only: the props carry no per-KPI series or
                            deltas, and we never invent one client-side. */}
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <KpiCard
                                accent={1}
                                label="Worked"
                                value={fmtHours(totals.worked_hours)}
                                icon={<Clock size={18} />}
                                sub={`${fmtHours(totals.tracked_hours)} tracked · ${fmtHours(totals.manual_hours)} manual`}
                            />
                            <KpiCard
                                accent={3}
                                label="Utilization vs target"
                                value={totals.utilization === null ? 'No target set' : fmtPct(totals.utilization, 1)}
                                icon={<Target size={18} />}
                                sub={
                                    totals.utilization === null
                                        ? 'Set shift hours to measure this'
                                        : `${fmtHours(totals.target_hours)} target across ${totals.people_with_target} ${totals.people_with_target === 1 ? 'person' : 'people'}`
                                }
                            />
                            <KpiCard
                                accent={2}
                                label="Activity"
                                value={totals.activity_percent === null ? 'Not tracked' : fmtPct(totals.activity_percent)}
                                icon={<Activity size={18} />}
                                sub={
                                    totals.activity_percent === null
                                        ? 'No desktop tracker sessions in range'
                                        : `Across ${fmtHours(totals.tracked_hours)} of tracked time`
                                }
                            />
                            <KpiCard
                                accent={5}
                                label="Billable share"
                                value={fmtPct(totals.billable_ratio, 1)}
                                icon={<Wallet size={18} />}
                                sub={`${fmtHours(totals.billable_hours)} billable · ${fmtHours(totals.non_billable_hours)} not`}
                            />
                        </div>

                        {/* ── Gauge + trend ────────────────────────────────── */}
                        <div className="grid gap-6 xl:grid-cols-3">
                            <Card
                                title="Activity"
                                description="Input density across tracked time"
                            >
                                <Gauge
                                    value={totals.activity_percent}
                                    label={
                                        totals.activity_percent === null
                                            ? 'No tracked sessions'
                                            : totals.activity_percent >= thresholds.activity_healthy
                                              ? 'Healthy'
                                              : totals.activity_percent >= thresholds.activity_low
                                                ? 'Moderate'
                                                : 'Low'
                                    }
                                    thresholds={[thresholds.activity_low, thresholds.activity_healthy]}
                                    bands={[
                                        { from: 0, tone: 'warning' },
                                        { from: thresholds.activity_low, tone: 'info' },
                                        { from: thresholds.activity_healthy, tone: 'success' },
                                    ]}
                                    emptyTitle="No activity signal"
                                    emptyDescription="Activity is measured by the desktop tracker. Manually added time carries no activity score, so this stays blank until someone tracks."
                                    caption={`Ticks at ${thresholds.activity_low}% and ${thresholds.activity_healthy}%. Quiet work — calls, reading, review — scores low by design, so read this next to the hours, not instead of them.`}
                                />
                            </Card>

                            <Card
                                className="xl:col-span-2"
                                title="Worked hours"
                                description={`Daily total across the range${trendAvg ? ` · ${fmtHours(trendAvg)} average on days with work` : ''}`}
                                actions={<TrendingUp size={16} className="text-muted" />}
                            >
                                <TrendChart data={trendPoints} avg={trendAvg} height={260} gradientFill glow />
                            </Card>
                        </div>

                        {/* ── Ribbon + billable split ──────────────────────── */}
                        <div className="grid gap-6 xl:grid-cols-3">
                            <Card
                                className="xl:col-span-2"
                                title="How the range broke down"
                                description="Every hour in the range, by what it was"
                            >
                                {ribbon.length > 0 ? (
                                    <>
                                        <ActivityStrip
                                            segments={ribbon.map((s) => ({
                                                ...s,
                                                accent: RIBBON_ACCENTS[s.tone] ?? null,
                                            }))}
                                            hours={false}
                                            glow
                                        />
                                        <p className="mt-4 text-xs text-muted">
                                            Active and low-input split tracked hours by their measured activity score.
                                            Manual hours are shown separately because they carry no activity signal at
                                            all — calling them idle would be a guess.
                                            {totals.target_hours !== null
                                                ? ' “Unfilled vs target” is the shortfall against the combined target of everyone who has one.'
                                                : ''}
                                        </p>
                                    </>
                                ) : (
                                    <EmptyState
                                        title="Nothing to break down"
                                        description="Segments appear once there are tracked or manual hours in this range."
                                    />
                                )}
                            </Card>

                            <Card title="Billable split" description="Billable against everything else">
                                {billableSplit.sum > 0 ? (
                                    <div className="space-y-4">
                                        <div className="flex items-baseline gap-2">
                                            <span className="text-3xl font-semibold tabular-nums text-body">
                                                {fmtPct(billableSplit.pct, 1)}
                                            </span>
                                            <span className="text-sm text-muted">billable</span>
                                        </div>
                                        <div className="flex h-3 overflow-hidden rounded-md border border-line">
                                            <span
                                                className="h-full bg-success"
                                                style={{ width: `${billableSplit.pct}%` }}
                                                title={`Billable ${fmtHours(billableSplit.billable)}`}
                                            />
                                            <span
                                                className="h-full bg-surface-2"
                                                style={{ width: `${100 - billableSplit.pct}%` }}
                                                title={`Non-billable ${fmtHours(billableSplit.nonBillable)}`}
                                            />
                                        </div>
                                        <dl className="grid grid-cols-2 gap-3 text-sm">
                                            <div>
                                                <dt className="text-xs uppercase tracking-wide text-muted">Billable</dt>
                                                <dd className="mt-0.5 font-semibold tabular-nums text-body">
                                                    {fmtHours(billableSplit.billable)}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs uppercase tracking-wide text-muted">
                                                    Non-billable
                                                </dt>
                                                <dd className="mt-0.5 font-semibold tabular-nums text-body">
                                                    {fmtHours(billableSplit.nonBillable)}
                                                </dd>
                                            </div>
                                        </dl>
                                        <p className="text-xs text-subtle">
                                            Billable follows each entry’s own flag, which defaults from its work
                                            category and can be overridden per entry.
                                        </p>
                                    </div>
                                ) : (
                                    <EmptyState
                                        title="No hours to split"
                                        description="The billable split appears once time is logged in this range."
                                    />
                                )}
                            </Card>
                        </div>

                        {/* ── Categories + highlights ──────────────────────── */}
                        <div className="grid gap-6 xl:grid-cols-3">
                            <Card
                                className="xl:col-span-2"
                                title="Hours by work category"
                                description="What the time was actually spent on"
                            >
                                {categoryBars.length > 0 ? (
                                    <div className="space-y-4">
                                        {categoryBars.map((bar) => {
                                            const slot = categorySlots.get(bar.label);
                                            return (
                                                <div key={bar.label}>
                                                    {slot ? (
                                                        <GradientBar
                                                            accent={slot}
                                                            label={bar.label}
                                                            pct={bar.pct}
                                                            count={bar.value}
                                                        />
                                                    ) : (
                                                        /* Past the 5 categorical slots: same bar anatomy,
                                                           neutral subtle fill — slots are never cycled. */
                                                        <div className="flex flex-col gap-1.5">
                                                            <div className="flex items-baseline justify-between gap-3 text-xs">
                                                                <span className="text-body truncate">{bar.label}</span>
                                                                <span className="text-muted tabular-nums shrink-0">
                                                                    {`${Math.round(bar.pct * 10) / 10}% (${bar.value})`}
                                                                </span>
                                                            </div>
                                                            <div
                                                                className="h-[10px] w-full rounded-full bg-surface-2 overflow-hidden"
                                                                role="progressbar"
                                                                aria-valuenow={Math.round(bar.pct * 10) / 10}
                                                                aria-valuemin={0}
                                                                aria-valuemax={100}
                                                            >
                                                                <div
                                                                    className="h-full rounded-full"
                                                                    style={{
                                                                        width: `${Math.min(100, Math.max(0, bar.pct))}%`,
                                                                        backgroundColor: 'var(--text-subtle)',
                                                                    }}
                                                                />
                                                            </div>
                                                        </div>
                                                    )}
                                                    {bar.meta ? (
                                                        <div className="mt-1.5 text-[11px] text-subtle">{bar.meta}</div>
                                                    ) : null}
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <EmptyState
                                        title="No categories yet"
                                        description="Time is grouped by the work categories your workspace defines in Settings. Once entries carry a category, the split appears here."
                                    />
                                )}
                            </Card>

                            <Card
                                title="Highlights"
                                description="Facts derived from this range"
                                actions={<Lightbulb size={16} className="text-muted" />}
                            >
                                {highlights.length > 0 ? (
                                    <ul className="space-y-4">
                                        {highlights.map((h) => {
                                            const Icon = HIGHLIGHT_ICON[h.tone] || Info;
                                            const toneClass =
                                                h.tone === 'success'
                                                    ? 'bg-success-soft text-success'
                                                    : h.tone === 'warning'
                                                      ? 'bg-warning-soft text-warning'
                                                      : 'bg-info-soft text-info';
                                            return (
                                                <li key={h.key} className="flex gap-3">
                                                    <span
                                                        className={cx(
                                                            'mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full',
                                                            toneClass
                                                        )}
                                                    >
                                                        <Icon size={15} />
                                                    </span>
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-medium text-body">{h.title}</p>
                                                        <p className="mt-0.5 text-xs text-muted">{h.detail}</p>
                                                    </div>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                ) : (
                                    <EmptyState
                                        icon={<Lightbulb size={20} />}
                                        title="Not enough data to call anything out"
                                        description="Highlights only appear when there is enough time in the range to say something honestly. Widen the range or keep tracking."
                                    />
                                )}
                            </Card>
                        </div>

                        {/* ── Per-person table ─────────────────────────────── */}
                        <Card
                            padded={false}
                            header={
                                <CardHeader
                                    title="Utilization by person"
                                    description={`Worked hours against each person's own shift target over ${totals.working_days} working ${totals.working_days === 1 ? 'day' : 'days'}. Active people only.`}
                                    actions={
                                        totals.people_without_target > 0 ? (
                                            <Badge tone="warning">
                                                {`${totals.people_without_target} without a target`}
                                            </Badge>
                                        ) : null
                                    }
                                />
                            }
                        >
                            <div className="overflow-x-auto">
                                <Table>
                                    <THead>
                                        <Tr>
                                            <Th>Person</Th>
                                            <Th className="text-right">Worked</Th>
                                            <Th className="text-right">Target</Th>
                                            <Th>Utilization</Th>
                                            <Th className="text-right">Activity</Th>
                                            <Th className="text-right">Billable</Th>
                                            <Th className="text-right">Days</Th>
                                        </Tr>
                                    </THead>
                                    <TBody>
                                        {[...rows]
                                            .sort((a, b) => {
                                                const au = a.utilization;
                                                const bu = b.utilization;
                                                if (au === null && bu === null) return b.worked_hours - a.worked_hours;
                                                if (au === null) return 1;
                                                if (bu === null) return -1;
                                                return bu - au;
                                            })
                                            .map((r) => (
                                                <Tr key={r.id}>
                                                    <Td>
                                                        <div className="flex items-center gap-3">
                                                            <Avatar
                                                                user={{
                                                                    name: r.name,
                                                                    avatar_url: r.avatar_url,
                                                                }}
                                                                size="sm"
                                                            />
                                                            <div className="min-w-0">
                                                                <div className="truncate font-medium text-body">
                                                                    {r.name}
                                                                </div>
                                                                {r.designation ? (
                                                                    <div className="truncate text-xs text-subtle">
                                                                        {r.designation}
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                        </div>
                                                    </Td>
                                                    <Td className="text-right tabular-nums">
                                                        {fmtHours(r.worked_hours)}
                                                    </Td>
                                                    <Td className="text-right tabular-nums text-muted">
                                                        {r.target_hours === null ? (
                                                            <span className="text-subtle">—</span>
                                                        ) : (
                                                            fmtHours(r.target_hours)
                                                        )}
                                                    </Td>
                                                    <Td>
                                                        <UtilizationBar
                                                            utilization={r.utilization}
                                                            thresholds={thresholds}
                                                        />
                                                    </Td>
                                                    <Td className="text-right">
                                                        {r.activity_percent === null ? (
                                                            <span className="text-xs text-subtle">Not tracked</span>
                                                        ) : (
                                                            <Badge tone={activityTone(r.activity_percent, thresholds)}>
                                                                {fmtPct(r.activity_percent)}
                                                            </Badge>
                                                        )}
                                                    </Td>
                                                    <Td className="text-right tabular-nums">
                                                        {r.billable_ratio === null ? (
                                                            <span className="text-subtle">—</span>
                                                        ) : (
                                                            fmtPct(r.billable_ratio, 1)
                                                        )}
                                                    </Td>
                                                    <Td className="text-right tabular-nums text-muted">
                                                        {`${r.days_logged}/${r.eligible_days}`}
                                                    </Td>
                                                </Tr>
                                            ))}
                                    </TBody>
                                </Table>
                            </div>
                        </Card>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
