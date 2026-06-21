import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';

function Lead({ lead, open, onToggle }) {
    const remove = (e) => { e.stopPropagation(); if (confirm(`Delete the lead from ${lead.name}?`)) router.delete(route('platform.leads.destroy', lead.id), { preserveScroll: true }); };
    return (
        <div className={`rounded-xl border bg-surface ${lead.read ? 'border-line' : 'border-accent/50'}`}>
            <button type="button" onClick={onToggle} className="flex w-full items-center gap-3 p-4 text-left">
                {!lead.read && <span className="h-2 w-2 shrink-0 rounded-full bg-accent" title="Unread" />}
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className="font-semibold text-body">{lead.name}</span>
                        {lead.company && <span className="text-sm text-muted">· {lead.company}</span>}
                    </div>
                    <div className="truncate text-xs text-subtle">{lead.email}{lead.team_size ? ' · team ' + lead.team_size : ''} · {lead.date}</div>
                </div>
                {!open && <span className="max-w-[40%] truncate text-xs text-subtle">{lead.message}</span>}
            </button>
            {open && (
                <div className="space-y-3 border-t border-line px-4 py-3">
                    <p className="whitespace-pre-wrap text-sm text-body">{lead.message}</p>
                    <div className="flex flex-wrap items-center gap-3">
                        <a href={`mailto:${lead.email}`} className="rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">Reply by email</a>
                        <button type="button" onClick={remove} className="text-xs text-rose-400 hover:text-rose-300">Delete</button>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function LeadsIndex({ leads = [], unread = 0 }) {
    const [openId, setOpenId] = useState(null);
    const toggle = (l) => {
        setOpenId((id) => (id === l.id ? null : l.id));
        if (!l.read) router.post(route('platform.leads.read', l.id), {}, { preserveScroll: true, preserveState: true });
    };

    return (
        <PlatformLayout title="Leads" header={<h2 className="text-xl font-semibold text-white">Leads</h2>}>
            <Head title="Leads" />
            <div className="mx-auto w-full max-w-3xl space-y-4 px-4 py-6 sm:px-6">
                <p className="text-sm text-muted">Contact-form messages from your marketing site.{unread > 0 && <span className="ml-1 font-semibold text-body">{unread} unread.</span>}</p>
                {leads.length === 0 ? (
                    <div className="rounded-xl border border-line bg-surface p-10 text-center text-sm text-muted">No leads yet. Messages from the site’s contact form land here.</div>
                ) : (
                    <div className="space-y-2.5">
                        {leads.map((l) => <Lead key={l.id} lead={l} open={openId === l.id} onToggle={() => toggle(l)} />)}
                    </div>
                )}
            </div>
        </PlatformLayout>
    );
}
