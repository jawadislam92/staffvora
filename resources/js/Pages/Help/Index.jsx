import { useMemo, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Search, ChevronDown } from 'lucide-react';

const SECTIONS = [
    {
        title: 'Getting started',
        items: [
            { q: 'What is this app for?', a: 'It tracks work time and (optionally) desktop activity for your team: clock in/out, a work diary, attendance, screenshots, and reports. Admins configure what is tracked in Settings.' },
            { q: 'How do my team members start tracking?', a: 'Invite them under Users, then they download the desktop app from the “Desktop App” item in the user menu. They sign in there and clock in — time and any enabled monitoring flow back here.' },
            { q: 'How do I switch the theme?', a: 'Use the Light / Dark / Black switch at the bottom of the sidebar. Each person chooses their own; the workspace default is set in Settings → Branding.' },
        ],
    },
    {
        title: 'Time tracking & attendance',
        items: [
            { q: 'What is the difference between “In Office” and “Tracked Work”?', a: '“In Office” is clock-in to clock-out time (presence/attendance). “Tracked Work” is active time recorded by the desktop tracker. They can differ if the tracker is off or idle.' },
            { q: 'Why is someone marked Absent — and how do I stop that?', a: 'A past working day with no clock-in is marked Absent once the shift has ended. In Settings → Attendance policy you can set a tracking-start date (earlier days show “Not tracked”), choose your weekly-off days and holidays, or turn Auto-Absent off entirely (missing days become a neutral “No record”).' },
            { q: 'Can I correct a day manually?', a: 'Yes — on the Attendance page an admin can mark Leave, Half-day, Work-from-home, Holiday, etc. Manual marks always win and are audited.' },
        ],
    },
    {
        title: 'Monitoring & screenshots',
        items: [
            { q: 'How often are screenshots taken?', a: 'Set the rate in Settings → Screenshots. The number is an average; captures happen at random intervals. Your plan caps the maximum rate.' },
            { q: 'What does auto-pause do?', a: 'Tracking pauses after a set period of inactivity and resumes when the person is active again. Your plan sets the tightest allowed value.' },
            { q: 'Why can’t I increase a setting?', a: 'Some options are limited by your plan. A locked control shows “upgrade to unlock”. Contact us if you need a specific limit raised for your workspace.' },
        ],
    },
    {
        title: 'Plans & billing',
        items: [
            { q: 'How do I upgrade?', a: 'Open Billing to see each plan’s limits and features. Payment is by bank transfer for now — follow the instructions on the Billing page; we activate your plan once it clears.' },
            { q: 'Can I get a custom price or limit?', a: 'Yes. Contact us — we can set a custom price or raise a specific limit just for your workspace without changing your plan.' },
        ],
    },
    {
        title: 'Branding',
        items: [
            { q: 'How do I add my logo and colours?', a: 'Settings → Branding (available on plans with white-label). Upload a logo, set your display name, pick an accent colour and a default theme — they apply across the app and the sign-in page.' },
        ],
    },
    {
        title: 'Teams & users',
        items: [
            { q: 'How do teams work?', a: 'Create teams under Teams and assign people to them. Use teams to organise large workspaces; reports and views can be filtered by team.' },
            { q: 'I hit my user limit.', a: 'Each plan includes a number of seats. Upgrade in Billing to add more, or contact us for a custom seat count.' },
        ],
    },
];

function Item({ q, a }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="border-b border-line last:border-0">
            <button type="button" onClick={() => setOpen((o) => !o)} className="flex w-full items-center justify-between gap-3 py-3 text-left">
                <span className="text-sm font-medium text-body">{q}</span>
                <ChevronDown className={`h-4 w-4 shrink-0 text-subtle transition ${open ? 'rotate-180' : ''}`} />
            </button>
            {open && <p className="pb-3 text-sm leading-6 text-muted">{a}</p>}
        </div>
    );
}

export default function HelpIndex({ auth }) {
    const [query, setQuery] = useState('');
    const supportEmail = usePage().props?.supportEmail || null;

    const sections = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return SECTIONS;
        return SECTIONS
            .map((s) => ({ ...s, items: s.items.filter((i) => (i.q + ' ' + i.a).toLowerCase().includes(q)) }))
            .filter((s) => s.items.length > 0);
    }, [query]);

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Help" />
            <div className="min-h-screen bg-app">
                <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
                    <PageHeader title="Help center" purpose="Answers to common questions about tracking, attendance, plans and branding." />

                    <div className="relative mb-6">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-subtle" />
                        <input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search help…"
                            className="w-full rounded-lg border-line bg-surface pl-9 text-sm text-body placeholder:text-subtle"
                        />
                    </div>

                    <div className="space-y-5">
                        {sections.map((s) => (
                            <section key={s.title} className="rounded-lg border border-line bg-surface px-5">
                                <h2 className="border-b border-line py-3 text-sm font-semibold uppercase tracking-wide text-subtle">{s.title}</h2>
                                {s.items.map((i) => <Item key={i.q} {...i} />)}
                            </section>
                        ))}
                        {sections.length === 0 && (
                            <p className="rounded-lg border border-line bg-surface px-5 py-8 text-center text-sm text-muted">No results. Try a different search.</p>
                        )}
                    </div>

                    <p className="mt-6 text-center text-sm text-muted">
                        Still stuck? {supportEmail
                            ? <>Email <a href={`mailto:${supportEmail}`} className="font-medium text-accent hover:underline">{supportEmail}</a>.</>
                            : 'Contact your workspace administrator.'}
                    </p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
