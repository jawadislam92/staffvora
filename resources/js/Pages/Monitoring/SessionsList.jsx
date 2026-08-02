import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Button, Card, Badge, Table, THead, TBody, Tr, Th, Td, Field, Select, EmptyState } from '@/Components/ui';
import { ChevronLeft, ChevronRight, Clock, Eye } from 'lucide-react';

function fmtDuration(seconds) {
    if (!seconds) return '0m';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    return h ? `${h}h ${m}m` : `${m}m`;
}

// Honors the company display settings (timezone + 12/24h) instead of the
// viewer's machine locale.
let DISPLAY = { timezone: 'Asia/Karachi', format: '12' };

function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('en-US', {
            timeZone: DISPLAY.timezone,
            year: 'numeric', month: 'short', day: 'numeric',
            hour: 'numeric', minute: '2-digit',
            hour12: String(DISPLAY.format) !== '24',
        });
    } catch {
        return iso;
    }
}

// Status → semantic Badge tone. Active reads healthy, abandoned warns, and a
// cleanly stopped session stays neutral (it is not an error).
function statusTone(status) {
    if (status === 'active') return 'success';
    if (status === 'abandoned') return 'warning';
    return 'neutral';
}

// ---------------------------------------------------------------------------
// DEV_SAMPLE — a self-contained set of tracking sessions used only when
// `preview` is true. Renders the full page offline (no network / props) with a
// mock super-admin viewer "Jawad" and a handful of members. No external data.
// ---------------------------------------------------------------------------
function buildDevSample() {
    const now = Date.now();
    const iso = (minsAgo) => new Date(now - minsAgo * 60000).toISOString();
    const sessions = [
        { id: 1041, user: { id: 1, name: 'Ayesha Khan' }, user_id: 1, client: { name: 'Northwind Retail' }, started_at: iso(200), stopped_at: iso(5), total_seconds: 11700, activity_percent: 84, status: 'active' },
        { id: 1040, user: { id: 2, name: 'Bilal Ahmed' }, user_id: 2, client: { name: 'Acme Studios' }, started_at: iso(410), stopped_at: iso(250), total_seconds: 9600, activity_percent: 62, status: 'stopped' },
        { id: 1039, user: { id: 3, name: 'Sara Malik' }, user_id: 3, client: null, started_at: iso(520), stopped_at: iso(505), total_seconds: 900, activity_percent: 18, status: 'abandoned' },
        { id: 1038, user: { id: 1, name: 'Ayesha Khan' }, user_id: 1, client: { name: 'Northwind Retail' }, started_at: iso(760), stopped_at: iso(600), total_seconds: 9540, activity_percent: 77, status: 'stopped' },
        { id: 1037, user: { id: 4, name: 'Usman Tariq' }, user_id: 4, client: { name: 'Blue Harbor' }, started_at: iso(980), stopped_at: iso(815), total_seconds: 9900, activity_percent: 55, status: 'stopped' },
        { id: 1036, user: { id: 2, name: 'Bilal Ahmed' }, user_id: 2, client: null, started_at: iso(1300), stopped_at: iso(1150), total_seconds: 8700, activity_percent: 41, status: 'stopped' },
    ];
    return {
        auth: { user: { id: 99, name: 'Jawad', email: 'jawad@staffvora.com', role: 'owner', is_super_admin: true, permissions: [] } },
        sessions: { data: sessions, current_page: 1, last_page: 1, from: 1, to: sessions.length, total: sessions.length, prev_page_url: null, next_page_url: null },
        users: [
            { id: 1, name: 'Ayesha Khan' },
            { id: 2, name: 'Bilal Ahmed' },
            { id: 3, name: 'Sara Malik' },
            { id: 4, name: 'Usman Tariq' },
        ],
        filters: { user_id: '' },
        permissions: { view_screenshots: true, manage: true },
    };
}

export default function SessionsList({ auth, sessions, users = [], filters = {}, permissions = {}, preview = false }) {
    // Preview: swap every prop for the built-in offline sample and a mock
    // super-admin viewer, so the page renders in full with no network/props.
    if (preview) {
        const sample = buildDevSample();
        auth = auth || sample.auth;
        sessions = sample.sessions;
        users = sample.users;
        filters = sample.filters;
        permissions = sample.permissions;
    }

    DISPLAY = preview ? DISPLAY : (usePage().props.display || DISPLAY);

    const onUserChange = (e) => {
        if (preview) return;
        const userId = e.target.value;
        router.get(route('monitoring.sessions'), userId ? { user_id: userId } : {}, { preserveState: true });
    };

    const goto = (url) => {
        if (preview || !url) return;
        router.get(url, {}, { preserveState: true, preserveScroll: true });
    };

    const rows = sessions?.data || [];

    return (
        <AuthenticatedLayout user={auth.user} header="Tracking Sessions">
            <Head title="Tracking Sessions" />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {users.length > 0 && (
                    <Card padded={false}>
                        <div className="flex flex-wrap items-end gap-4 px-5 py-4">
                            <Field label="Filter by user" htmlFor="session-user-filter" className="w-full max-w-xs">
                                <Select
                                    id="session-user-filter"
                                    value={filters?.user_id || ''}
                                    onChange={onUserChange}
                                >
                                    <option value="">All users</option>
                                    {users.map((u) => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                    </Card>
                )}

                <Card padded={false}>
                    {rows.length === 0 ? (
                        <EmptyState
                            icon={<Clock className="h-5 w-5" />}
                            title="No tracking sessions yet"
                            description="Start the desktop tracker to begin recording sessions."
                        />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>User</Th>
                                    <Th>Client</Th>
                                    <Th>Started</Th>
                                    <Th>Stopped</Th>
                                    <Th className="text-right">Duration</Th>
                                    <Th className="text-right">Activity</Th>
                                    <Th>Status</Th>
                                    <Th className="text-right">Actions</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {rows.map((s) => (
                                    <Tr key={s.id}>
                                        <Td className="font-medium text-body">{s.user?.name || `User #${s.user_id}`}</Td>
                                        <Td className="text-muted">{s.client?.name || '—'}</Td>
                                        <Td className="text-muted">{fmtDate(s.started_at)}</Td>
                                        <Td className="text-muted">{fmtDate(s.stopped_at)}</Td>
                                        <Td className="text-right tabular-nums">{fmtDuration(s.total_seconds)}</Td>
                                        <Td className="text-right tabular-nums">{s.activity_percent ?? 0}%</Td>
                                        <Td>
                                            <Badge tone={statusTone(s.status)} dot>{s.status}</Badge>
                                        </Td>
                                        <Td className="text-right">
                                            <Button
                                                as={Link}
                                                href={preview ? '#' : route('monitoring.sessions.show', s.id)}
                                                variant="secondary"
                                                size="sm"
                                                icon={<Eye className="h-3.5 w-3.5" />}
                                            >
                                                View
                                            </Button>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}

                    {rows.length > 0 && (sessions.prev_page_url || sessions.next_page_url) && (
                        <div className="flex items-center justify-between gap-3 border-t border-line px-5 py-3 text-sm">
                            <span className="text-muted">
                                {sessions.total != null
                                    ? `Showing ${sessions.from}–${sessions.to} of ${sessions.total}`
                                    : `Page ${sessions.current_page} of ${sessions.last_page}`}
                            </span>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    disabled={!sessions.prev_page_url}
                                    onClick={() => goto(sessions.prev_page_url)}
                                    icon={<ChevronLeft className="h-4 w-4" />}
                                >
                                    Prev
                                </Button>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    disabled={!sessions.next_page_url}
                                    onClick={() => goto(sessions.next_page_url)}
                                    iconTrailing={<ChevronRight className="h-4 w-4" />}
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
