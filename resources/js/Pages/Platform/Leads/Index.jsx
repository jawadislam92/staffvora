import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import { Button, Table, THead, TBody, Tr, Th, Td, Badge, EmptyState } from '@/Components/ui';

const cx = (...a) => a.filter(Boolean).join(' ');

// Offline sample so `preview` renders fully without a backend / authenticated
// platform user. Shape mirrors the controller payload consumed below.
// const DEV_SAMPLE = ...
const DEV_SAMPLE = {
    unread: 3,
    leads: [
        { id: 1, name: 'Priya Raman', company: 'Northwind Studios', email: 'priya@northwind.co', team_size: 24, read: false, date: 'Jul 22, 2026', message: 'We run a 24-person design studio and are shopping for a time tracker with screenshots and idle detection. Do you offer an agency plan with client-facing reports?' },
        { id: 2, name: 'Marcus Bell', company: 'Bell & Co Accounting', email: 'marcus@bellco.com', team_size: 8, read: false, date: 'Jul 22, 2026', message: 'Interested in the General edition for our bookkeeping team. Is there a way to export timesheets to CSV for payroll?' },
        { id: 3, name: 'Sofia Alvarez', company: '', email: 'sofia.alvarez@gmail.com', team_size: null, read: false, date: 'Jul 21, 2026', message: 'Freelancer here — just wanted to say the demo looked great. Do you have a solo plan?' },
        { id: 4, name: 'David Okafor', company: 'Lumen Health', email: 'd.okafor@lumenhealth.io', team_size: 60, read: true, date: 'Jul 20, 2026', message: 'Evaluating vendors for a 60-seat rollout. Need SSO and an audit trail for compliance. Can we book a call this week?' },
        { id: 5, name: 'Hannah Kim', company: 'Driftwave Media', email: 'hannah@driftwave.tv', team_size: 15, read: true, date: 'Jul 19, 2026', message: 'Switching from a competitor. How does data import work — can we bring over historical time entries?' },
        { id: 6, name: 'Tomasz Nowak', company: 'Nowak Logistics', email: 'tomasz@nowak-logistics.pl', team_size: 40, read: true, date: 'Jul 18, 2026', message: 'Do you support timezones for a distributed team across Europe and North America? Billing questions too.' },
    ],
};

function MailIcon(props) {
    return (
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="m3 7 9 6 9-6" />
        </svg>
    );
}

function LeadRow({ lead, open, onToggle, onMarkRead, onDelete }) {
    return (
        <>
            <Tr
                onClick={onToggle}
                aria-expanded={open}
                className={cx('cursor-pointer', !lead.read && 'bg-accent/[0.04]')}
            >
                <Td className="w-px whitespace-nowrap">
                    {lead.read ? (
                        <Badge tone="neutral">Read</Badge>
                    ) : (
                        <Badge tone="accent" dot>Unread</Badge>
                    )}
                </Td>
                <Td>
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className={cx('truncate', lead.read ? 'font-medium text-body' : 'font-semibold text-body')}>{lead.name}</span>
                        {lead.company && <span className="text-xs text-muted">{lead.company}</span>}
                    </div>
                    <div className="truncate text-xs text-subtle">
                        {lead.email}{lead.team_size ? ` · team ${lead.team_size}` : ''}
                    </div>
                </Td>
                <Td className="hidden md:table-cell">
                    <span className="block max-w-[16rem] truncate text-sm text-muted lg:max-w-[28rem]">{lead.message}</span>
                </Td>
                <Td className="hidden whitespace-nowrap text-xs text-subtle sm:table-cell">{lead.date}</Td>
                <Td className="w-px whitespace-nowrap text-right" onClick={(e) => e.stopPropagation()}>
                    <div className="inline-flex items-center gap-2">
                        {!lead.read && (
                            <Button size="sm" variant="secondary" onClick={() => onMarkRead(lead)}>Mark read</Button>
                        )}
                        <Button size="sm" variant="ghost" className="text-danger hover:bg-danger-soft" onClick={() => onDelete(lead)}>Delete</Button>
                    </div>
                </Td>
            </Tr>
            {open && (
                <tr className="border-b border-line last:border-0 bg-surface-2/30">
                    <td colSpan={5} className="px-4 py-4">
                        <p className="whitespace-pre-wrap text-sm text-body">{lead.message}</p>
                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            <Button size="sm" as="a" href={`mailto:${lead.email}`} icon={<MailIcon width="14" height="14" />}>
                                Reply by email
                            </Button>
                            {!lead.read && (
                                <Button size="sm" variant="secondary" onClick={() => onMarkRead(lead)}>Mark read</Button>
                            )}
                            <Button size="sm" variant="ghost" className="text-danger hover:bg-danger-soft" onClick={() => onDelete(lead)}>Delete</Button>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

export default function LeadsIndex({ leads: leadsProp, unread: unreadProp, preview = false }) {
    const source = preview ? DEV_SAMPLE : { leads: leadsProp ?? [], unread: unreadProp ?? 0 };
    // Local mirror so preview (offline) mark-read/delete update the UI without a server.
    const [leads, setLeads] = useState(source.leads);
    const [openId, setOpenId] = useState(null);

    const unread = leads.filter((l) => !l.read).length;

    const markRead = (l) => {
        if (l.read) return;
        setLeads((prev) => prev.map((x) => (x.id === l.id ? { ...x, read: true } : x)));
        if (!preview) router.post(route('platform.leads.read', l.id), {}, { preserveScroll: true, preserveState: true });
    };

    const toggle = (l) => {
        setOpenId((id) => (id === l.id ? null : l.id));
        markRead(l);
    };

    const remove = (l) => {
        if (!confirm(`Delete the lead from ${l.name}?`)) return;
        setLeads((prev) => prev.filter((x) => x.id !== l.id));
        if (!preview) router.delete(route('platform.leads.destroy', l.id), { preserveScroll: true });
    };

    return (
        <PlatformLayout
            title="Leads"
            header={
                <PlatformPageHeader
                    title="Leads"
                    description="Contact-form messages from your marketing site."
                    actions={unread > 0 ? <Badge tone="accent" dot>{unread} unread</Badge> : undefined}
                />
            }
        >
            <Head title="Leads" />

            <div className="rounded-xl border border-line bg-surface shadow-sm">
                {leads.length === 0 ? (
                    <EmptyState
                        title="No leads yet"
                        description="Messages from your site’s contact form will land here."
                    />
                ) : (
                    <Table>
                        <THead>
                            <Tr>
                                <Th>Status</Th>
                                <Th>Contact</Th>
                                <Th className="hidden md:table-cell">Message</Th>
                                <Th className="hidden sm:table-cell">Received</Th>
                                <Th className="text-right">Actions</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {leads.map((l) => (
                                <LeadRow
                                    key={l.id}
                                    lead={l}
                                    open={openId === l.id}
                                    onToggle={() => toggle(l)}
                                    onMarkRead={markRead}
                                    onDelete={remove}
                                />
                            ))}
                        </TBody>
                    </Table>
                )}
            </div>
        </PlatformLayout>
    );
}
