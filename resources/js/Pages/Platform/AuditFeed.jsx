import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/Components/ui';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { diffEntries, renderAuditValue, rt } from './entitlementUi';

// Shared renderer for the operator audit trail — used by the cross-tenant
// Activity page and by the History tab on a tenant's page.
//
// NOTE: this lives beside the pages that use it and is a plain component, not a
// route target. Inertia's resolver globs `./Pages/**/*.jsx`, so it is present in
// that map, but nothing ever renders `Platform/AuditFeed` as a page.

const TONE_FOR = (action) => {
    if (action === 'tenant.impersonated') return 'warning';
    if (action.endsWith('.revoked') || action.endsWith('.archived') || action === 'tenant.suspended') return 'danger';
    if (action.endsWith('.granted') || action.endsWith('.created') || action === 'tenant.resumed') return 'success';
    if (action.startsWith('tenant.')) return 'info';
    return 'accent';
};

const formatAt = (iso) => {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString(undefined, {
            year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
        });
    } catch {
        return iso;
    }
};

function Diff({ entry }) {
    const rows = diffEntries(entry.before, entry.after);

    if (rows.length === 0) {
        return <p className="text-xs text-subtle">No field-level detail recorded.</p>;
    }

    return (
        <dl className="space-y-1.5 text-xs">
            {rows.map((row) => (
                <div key={row.key} className="grid gap-1 sm:grid-cols-[10rem_1fr]">
                    <dt className="font-medium text-muted">{row.key}</dt>
                    <dd className="min-w-0 break-words text-body">
                        <span className="text-subtle line-through">{renderAuditValue(row.from)}</span>
                        <span className="mx-1.5 text-subtle" aria-hidden="true">→</span>
                        <span className="font-medium">{renderAuditValue(row.to)}</span>
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function Row({ entry, showOrganization }) {
    const [open, setOpen] = useState(false);
    const hasDetail = !!(entry.before || entry.after);

    return (
        <li className="border-b border-line py-3 last:border-0">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <Badge tone={TONE_FOR(entry.action)}>{entry.label || entry.action}</Badge>

                <span className="text-sm text-body">
                    <span className="font-medium">{entry.actor}</span>
                </span>

                {showOrganization && entry.organization && (
                    <>
                        <span className="text-subtle" aria-hidden="true">·</span>
                        <Link
                            href={rt('platform.show', entry.organization.id)}
                            className="text-sm text-accent transition hover:underline focus-visible:outline-none focus-visible:underline"
                        >
                            {entry.organization.name}
                        </Link>
                    </>
                )}

                <span className="ml-auto whitespace-nowrap text-xs text-subtle tabular-nums">
                    {formatAt(entry.at)}
                </span>
            </div>

            {(entry.reason || entry.ip) && (
                <div className="mt-1 flex flex-wrap items-center gap-x-3 text-xs text-muted">
                    {entry.reason && <span>“{entry.reason}”</span>}
                    {entry.ip && <span className="text-subtle">from {entry.ip}</span>}
                </div>
            )}

            {hasDetail && (
                <>
                    <button
                        type="button"
                        onClick={() => setOpen((v) => !v)}
                        aria-expanded={open}
                        className="mt-1.5 inline-flex items-center gap-1 rounded text-xs font-medium text-muted transition cursor-pointer hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        {open ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                        {open ? 'Hide detail' : 'What changed'}
                    </button>
                    {open && (
                        <div className="mt-2 overflow-x-auto rounded-lg border border-line bg-surface-2 p-3">
                            <Diff entry={entry} />
                        </div>
                    )}
                </>
            )}
        </li>
    );
}

export default function AuditFeed({ entries = [], showOrganization = false }) {
    return (
        <ul className="divide-y-0">
            {entries.map((entry) => (
                <Row key={entry.id} entry={entry} showOrganization={showOrganization} />
            ))}
        </ul>
    );
}
