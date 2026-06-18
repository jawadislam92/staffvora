import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';

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

export default function SessionsList({ auth, sessions, users = [], filters = {}, permissions = {} }) {
    DISPLAY = usePage().props.display || DISPLAY;
    const onUserChange = (e) => {
        const userId = e.target.value;
        router.get(route('monitoring.sessions'), userId ? { user_id: userId } : {}, { preserveState: true });
    };

    const rows = sessions?.data || [];

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl leading-tight">Tracking Sessions</h2>}
        >
            <Head title="Tracking Sessions" />

            <div className="min-h-screen bg-slate-950">
                <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                    {users.length > 0 && (
                        <div className="flex items-center gap-3 rounded-lg border border-slate-800 bg-slate-900 p-4 shadow">
                            <label className="text-sm font-medium text-slate-300">Filter user:</label>
                            <select
                                value={filters?.user_id || ''}
                                onChange={onUserChange}
                                className="rounded-md border-slate-700 bg-slate-900 text-sm text-slate-200 shadow-sm [color-scheme:dark]"
                            >
                                <option value="">All users</option>
                                {users.map((u) => (
                                    <option key={u.id} value={u.id}>{u.name}</option>
                                ))}
                            </select>
                        </div>
                    )}

                    <div className="overflow-hidden rounded-lg border border-slate-800 bg-slate-900 shadow">
                        <table className="min-w-full divide-y divide-slate-800">
                            <thead className="bg-slate-950/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">User</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Client</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Started</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Stopped</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Duration</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Activity</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-slate-500">Status</th>
                                    <th className="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800">
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-8 text-center text-slate-500">
                                            No tracking sessions yet. Start the desktop tracker to begin recording.
                                        </td>
                                    </tr>
                                )}
                                {rows.map((s) => (
                                    <tr key={s.id} className="transition hover:bg-slate-800/40">
                                        <td className="px-4 py-3 text-sm text-slate-200">{s.user?.name || `User #${s.user_id}`}</td>
                                        <td className="px-4 py-3 text-sm text-slate-300">{s.client?.name || '—'}</td>
                                        <td className="px-4 py-3 text-sm text-slate-300">{fmtDate(s.started_at)}</td>
                                        <td className="px-4 py-3 text-sm text-slate-300">{fmtDate(s.stopped_at)}</td>
                                        <td className="px-4 py-3 text-sm text-slate-200">{fmtDuration(s.total_seconds)}</td>
                                        <td className="px-4 py-3 text-sm text-slate-300">{s.activity_percent ?? 0}%</td>
                                        <td className="px-4 py-3 text-sm">
                                            <span
                                                className={
                                                    s.status === 'active'
                                                        ? 'inline-flex rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs text-emerald-300'
                                                        : s.status === 'abandoned'
                                                            ? 'inline-flex rounded-full bg-amber-500/15 px-2 py-0.5 text-xs text-amber-300'
                                                            : 'inline-flex rounded-full bg-slate-700/40 px-2 py-0.5 text-xs text-slate-300'
                                                }
                                            >
                                                {s.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-sm">
                                            <Link
                                                href={route('monitoring.sessions.show', s.id)}
                                                className="font-semibold text-orange-400 hover:text-orange-300"
                                            >
                                                View
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
