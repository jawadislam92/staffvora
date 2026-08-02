import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Globe, Laptop, Users, Clock } from 'lucide-react';
import { Card, StatCard, Select, Button, Badge } from '@/Components/ui';
import { RankedBars } from '@/Components/charts';

function fmtHm(seconds) {
    const s = Math.max(0, Math.floor(seconds || 0));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    if (h === 0) return `${m}m`;
    return `${h}h ${String(m).padStart(2, '0')}m`;
}

const RANGES = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
];

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true so the page renders fully
// offline for visual QA. Mirrors the TeamController@apps Inertia payload
// (apps + urls rollups + users + totals) with a spread of applications and
// websites so both ranked charts have real bars. Never touched normally.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.com', is_super_admin: true, permissions: [] },
    range: '7d',
    start: '2026-07-16',
    end: '2026-07-22',
    userId: null,
    users: [
        { id: 2, name: 'Ayesha Khan' },
        { id: 3, name: 'Bilal Ahmed' },
        { id: 4, name: 'Chen Wei' },
        { id: 5, name: 'Maria Lopez' },
        { id: 7, name: 'Sana Riaz' },
    ],
    apps: [
        { name: 'Visual Studio Code', total_seconds: 154800, user_count: 4, top_user: 'Chen Wei' },
        { name: 'PhpStorm', total_seconds: 122400, user_count: 3, top_user: 'Bilal Ahmed' },
        { name: 'Google Chrome', total_seconds: 98400, user_count: 5, top_user: 'Sana Riaz' },
        { name: 'Figma', total_seconds: 61200, user_count: 2, top_user: 'Maria Lopez' },
        { name: 'Slack', total_seconds: 39600, user_count: 5, top_user: 'Ayesha Khan' },
        { name: 'Terminal', total_seconds: 21600, user_count: 3, top_user: 'Bilal Ahmed' },
        { name: 'Notion', total_seconds: 12600, user_count: 2, top_user: 'Maria Lopez' },
    ],
    urls: [
        { name: 'github.com', total_seconds: 82800, user_count: 4, top_user: 'Chen Wei' },
        { name: 'staffvora.com', total_seconds: 57600, user_count: 5, top_user: 'Ayesha Khan' },
        { name: 'stackoverflow.com', total_seconds: 43200, user_count: 4, top_user: 'Bilal Ahmed' },
        { name: 'figma.com', total_seconds: 39600, user_count: 2, top_user: 'Maria Lopez' },
        { name: 'linear.app', total_seconds: 18000, user_count: 3, top_user: 'Sana Riaz' },
        { name: 'developer.mozilla.org', total_seconds: 10800, user_count: 3, top_user: 'Chen Wei' },
    ],
    totals: { tracked_seconds: 590400, people: 5 },
};

function toItems(rows) {
    const max = Math.max(...rows.map((r) => r.total_seconds || 0), 1);
    return rows.map((r) => ({
        label: r.name,
        value: fmtHm(r.total_seconds),
        pct: Math.max(2, Math.round(((r.total_seconds || 0) / max) * 100)),
        meta: `${r.user_count} ${r.user_count === 1 ? 'person' : 'people'}${r.top_user ? ` · most: ${r.top_user}` : ''}`,
    }));
}

export default function TeamApps({
    auth,
    range,
    start,
    end,
    userId,
    users,
    apps,
    urls,
    totals,
    preview = false,
}) {
    const authUser = preview ? DEV_SAMPLE.user : auth?.user;
    range = preview ? DEV_SAMPLE.range : range;
    start = preview ? DEV_SAMPLE.start : start;
    end = preview ? DEV_SAMPLE.end : end;
    userId = preview ? DEV_SAMPLE.userId : userId;
    users = preview ? DEV_SAMPLE.users : users;
    apps = preview ? DEV_SAMPLE.apps : apps;
    urls = preview ? DEV_SAMPLE.urls : urls;
    totals = preview ? DEV_SAMPLE.totals : totals;

    const reload = (params) => {
        if (preview) return;
        router.get(route('team.apps'), { range, user_id: userId || undefined, ...params }, { preserveScroll: true });
    };

    const membersHref = preview ? '#' : route('team.index');

    return (
        <AuthenticatedLayout user={authUser} header={<h2 className="text-xl font-semibold text-body">Team Performance</h2>}>
            <Head title="Team Apps & URLs" />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                {/* View switch */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="inline-flex items-center gap-1 rounded-lg bg-surface-2 p-1" role="tablist" aria-label="Team views">
                        <Link
                            href={membersHref}
                            role="tab"
                            aria-selected="false"
                            className="inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium text-muted transition hover:text-body"
                        >
                            Members
                        </Link>
                        <span
                            role="tab"
                            aria-selected="true"
                            className="inline-flex items-center rounded-md bg-surface px-3 py-1.5 text-sm font-medium text-body shadow-sm"
                        >
                            Apps &amp; URLs
                        </span>
                    </div>
                    <Badge tone="neutral" title="Times are estimates from activity samples">Sampled activity</Badge>
                </div>

                {/* KPI row */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <StatCard
                        label="People sampled"
                        value={totals.people}
                        hint={`${start} → ${end}`}
                        icon={<Users className="h-4 w-4" />}
                    />
                    <StatCard
                        label="Sampled activity"
                        value={`~${fmtHm(totals.tracked_seconds)}`}
                        hint="Estimated from activity samples"
                        icon={<Clock className="h-4 w-4" />}
                    />
                    <StatCard
                        label="Tracked surfaces"
                        value={`${apps.length} apps · ${urls.length} sites`}
                        hint="Distinct apps and websites seen"
                        icon={<Laptop className="h-4 w-4" />}
                    />
                </div>

                {/* Filters */}
                <Card padded>
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="w-56">
                            <Select
                                value={userId || ''}
                                onChange={(e) => reload({ user_id: e.target.value || undefined })}
                                aria-label="Filter by member"
                            >
                                <option value="">Everyone</option>
                                {users.map((u) => (
                                    <option key={u.id} value={u.id}>{u.name}</option>
                                ))}
                            </Select>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {RANGES.map((r) => (
                                <Button
                                    key={r.value}
                                    variant={range === r.value ? 'primary' : 'secondary'}
                                    size="sm"
                                    onClick={() => reload({ range: r.value })}
                                >
                                    {r.label}
                                </Button>
                            ))}
                        </div>
                        <span className="ml-auto text-xs text-subtle">Times are estimates from activity samples.</span>
                    </div>
                </Card>

                {/* Ranked usage charts */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Card
                        title={<span className="inline-flex items-center gap-2"><Laptop className="h-4 w-4 text-muted" /> Applications</span>}
                        description="Ranked by sampled time"
                    >
                        <RankedBars items={toItems(apps)} />
                    </Card>
                    <Card
                        title={<span className="inline-flex items-center gap-2"><Globe className="h-4 w-4 text-muted" /> Websites</span>}
                        description="Ranked by sampled time"
                    >
                        <RankedBars items={toItems(urls)} />
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
