import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const STATUS_TONE = {
    trial: 'bg-amber-500/15 text-amber-300',
    active: 'bg-emerald-500/15 text-emerald-300',
    past_due: 'bg-rose-500/15 text-rose-300',
    suspended: 'bg-slate-700 text-slate-300',
};

function Stat({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-800 bg-slate-900 px-4 py-3">
            <p className="text-2xl font-bold text-body">{value}</p>
            <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
        </div>
    );
}

export default function PlatformIndex({ auth, organizations, stats }) {
    const setStatus = (org, action) => {
        router.post(route(`platform.${action}`, org.id), {}, { preserveScroll: true });
    };
    const impersonate = (org) => {
        if (confirm(`Sign in as ${org.name}'s owner? You can return to the platform afterwards.`)) {
            router.post(route('platform.impersonate', org.id));
        }
    };

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="text-xl font-semibold text-slate-100">Platform</h2>}>
            <Head title="Platform" />
            <div className="min-h-screen bg-slate-950">
                <div className="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <Stat label="Tenants" value={stats.tenants} />
                        <Stat label="Active" value={stats.active} />
                        <Stat label="Trialing" value={stats.trialing} />
                        <Stat label="Suspended" value={stats.suspended} />
                    </div>

                    <div className="overflow-x-auto rounded-lg border border-slate-800 bg-slate-900">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-800 text-left text-xs uppercase tracking-wide text-slate-400">
                                    <th className="px-4 py-3">Organization</th>
                                    <th className="px-4 py-3">Status</th>
                                    <th className="px-4 py-3">Plan</th>
                                    <th className="px-4 py-3">Users</th>
                                    <th className="px-4 py-3">Trial ends</th>
                                    <th className="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800">
                                {organizations.map((org) => (
                                    <tr key={org.id} className="text-slate-200">
                                        <td className="px-4 py-3">
                                            <Link href={route('platform.show', org.id)} className="font-medium text-slate-100 hover:text-orange-300">{org.name}</Link>
                                            <div className="text-xs text-slate-500">{org.slug}</div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${STATUS_TONE[org.status] || 'bg-slate-700 text-slate-300'}`}>{org.status}</span>
                                        </td>
                                        <td className="px-4 py-3">{org.plan || '—'}</td>
                                        <td className="px-4 py-3">{org.users}{org.seat_limit ? ` / ${org.seat_limit}` : ''}</td>
                                        <td className="px-4 py-3 text-slate-400">{org.trial_ends_at || '—'}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-2">
                                                <button onClick={() => impersonate(org)} className="rounded-md border border-slate-700 px-2 py-1 text-xs text-slate-300 hover:bg-slate-800">View as</button>
                                                {org.status === 'suspended'
                                                    ? <button onClick={() => setStatus(org, 'resume')} className="rounded-md bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700">Resume</button>
                                                    : <button onClick={() => setStatus(org, 'suspend')} className="rounded-md border border-rose-500/40 px-2 py-1 text-xs text-rose-300 hover:bg-rose-500/10">Suspend</button>}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {organizations.length === 0 && (
                                    <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-400">No tenants yet.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
