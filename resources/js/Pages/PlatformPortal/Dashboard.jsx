import PlatformLayout from '@/Layouts/PlatformLayout';

function Stat({ label, value }) {
    return (
        <div className="rounded-xl border border-zinc-800 bg-zinc-900 p-5">
            <div className="text-3xl font-bold text-orange-400">{value}</div>
            <div className="mt-1 text-sm text-zinc-400">{label}</div>
        </div>
    );
}

export default function Dashboard({ stats }) {
    return (
        <PlatformLayout title="Operator dashboard">
            <h1 className="text-2xl font-bold text-white">Operator console</h1>
            <p className="mt-1 text-sm text-zinc-400">Manage tenants, plans, the website and staff — separate from the customer app.</p>

            <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label="Tenants" value={stats?.tenants ?? 0} />
                <Stat label="Platform staff" value={stats?.staff ?? 0} />
            </div>

            <p className="mt-8 text-sm text-zinc-500">
                Next: tenant management, plans, the website builder and staff invitations move into this portal.
            </p>
        </PlatformLayout>
    );
}
