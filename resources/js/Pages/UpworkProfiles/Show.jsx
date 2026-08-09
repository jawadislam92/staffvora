import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card } from '@/Components/ui';
import { ArrowLeft, Pencil, Mail, Clock, Users, CalendarDays } from 'lucide-react';

const DEV_SAMPLE = {
    user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.com', is_super_admin: true, permissions: [] },
    profile: {
        id: 1,
        name: 'Aqsa Malik',
        email: 'aqsa@sparkingasia.com',
        description: 'Full-stack Upwork profile — web & mobile agency work.',
        is_active: true,
    },
    stats: { entries: 128, hours: 642.5, people: 4, last_date: '2026-08-08' },
    recent: [
        { id: 1, date: '2026-08-08', hours: 6.5, user_name: 'Aqsa Malik' },
        { id: 2, date: '2026-08-07', hours: 7.25, user_name: 'Bilal Khan' },
    ],
};

function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(`${iso}T00:00:00`);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function Stat({ icon, label, value }) {
    return (
        <div className="flex items-center gap-3 rounded-xl border border-line bg-surface p-4 shadow-sm">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-surface-2 text-muted">
                {icon}
            </span>
            <span className="min-w-0">
                <span className="block text-xs font-medium uppercase tracking-wide text-subtle">{label}</span>
                <span className="block text-lg font-bold tabular-nums text-body">{value}</span>
            </span>
        </div>
    );
}

export default function Show({ auth, profile, stats, recent = [], preview = false }) {
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const model = preview ? DEV_SAMPLE.profile : profile;
    const s = preview ? DEV_SAMPLE.stats : (stats || { entries: 0, hours: 0, people: 0, last_date: null });
    const rows = preview ? DEV_SAMPLE.recent : (recent || []);

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">Upwork profile</h2>}
        >
            <Head title={model?.name ? `${model.name} — Upwork profile` : 'Upwork profile'} />

            <div className="mx-auto max-w-4xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link
                        href={preview ? '#' : route('upwork-profiles.index')}
                        className="inline-flex items-center gap-1.5 text-sm text-muted transition hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to profiles
                    </Link>
                    <Button
                        as={Link}
                        href={preview ? '#' : route('upwork-profiles.edit', model.id)}
                        variant="secondary"
                        icon={<Pencil className="h-4 w-4" />}
                    >
                        Edit profile
                    </Button>
                </div>

                <Card>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h1 className="text-2xl font-bold text-body">{model?.name}</h1>
                            {model?.email && (
                                <p className="mt-1 inline-flex items-center gap-1.5 text-sm text-muted">
                                    <Mail className="h-4 w-4" aria-hidden="true" />
                                    {model.email}
                                </p>
                            )}
                        </div>
                        <span
                            className={
                                model?.is_active
                                    ? 'inline-flex items-center rounded-full bg-success-soft px-2.5 py-0.5 text-xs font-semibold text-success'
                                    : 'inline-flex items-center rounded-full bg-surface-2 px-2.5 py-0.5 text-xs font-semibold text-muted'
                            }
                        >
                            {model?.is_active ? 'Active' : 'Archived'}
                        </span>
                    </div>
                    {model?.description && (
                        <p className="mt-4 whitespace-pre-line text-sm leading-6 text-body">{model.description}</p>
                    )}
                </Card>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat icon={<Clock className="h-5 w-5" />} label="Total hours" value={Number(s.hours).toLocaleString()} />
                    <Stat icon={<CalendarDays className="h-5 w-5" />} label="Entries" value={Number(s.entries).toLocaleString()} />
                    <Stat icon={<Users className="h-5 w-5" />} label="People" value={Number(s.people).toLocaleString()} />
                    <Stat icon={<CalendarDays className="h-5 w-5" />} label="Last activity" value={formatDate(s.last_date)} />
                </div>

                <Card padded={false}>
                    <div className="border-b border-line px-5 py-4">
                        <h2 className="font-bold text-body">Recent work hours</h2>
                        <p className="mt-1 text-sm text-muted">The 20 most recent entries logged against this profile.</p>
                    </div>
                    {rows.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted">No work hours have been logged against this profile yet.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-line text-left text-xs uppercase tracking-wide text-subtle">
                                        <th scope="col" className="px-5 py-2 font-semibold">Date</th>
                                        <th scope="col" className="px-5 py-2 font-semibold">Person</th>
                                        <th scope="col" className="px-5 py-2 text-right font-semibold">Hours</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {rows.map((row) => (
                                        <tr key={row.id}>
                                            <td className="px-5 py-2.5 text-body">{formatDate(row.date)}</td>
                                            <td className="px-5 py-2.5 text-body">{row.user_name || '—'}</td>
                                            <td className="px-5 py-2.5 text-right tabular-nums text-body">{Number(row.hours).toFixed(2)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
