import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import { Button, Card, Badge, KpiCard, Table, THead, TBody, Tr, Th, Td, Field, Input, Select, EmptyState } from '@/Components/ui';
import Modal from '@/Components/Modal';
import { Activity, Building2, History, Layers, PauseCircle, Tags, Timer } from 'lucide-react';
import { rt } from './entitlementUi';

// ─────────────────────────────────────────────────────────────────────────────
// TENANT DIRECTORY.
//
// Filtering and paging happen on the SERVER. That is deliberate: each row's seat
// limit is resolved through the real entitlement engine (override → add-on →
// plan → default), so the number of rows a single request touches has to stay
// bounded — client-side filtering of "every organization" would not survive 500
// tenants.
// ─────────────────────────────────────────────────────────────────────────────

const STATUS_TONE = { trial: 'warning', active: 'success', past_due: 'danger', suspended: 'neutral' };
const STATUS_LABEL = { trial: 'Trialing', active: 'Active', past_due: 'Past due', suspended: 'Suspended' };

// ── Offline preview fixtures ────────────────────────────────────────────────
const DEV_SAMPLE = {
    organizations: [
        { id: 1, name: 'Northwind Labs', slug: 'northwind-labs', status: 'active', plan: 'Growth', users: 24, seat_limit: 30, trial_ends_at: null, created_at: '2025-02-14' },
        { id: 2, name: 'Acme Digital', slug: 'acme-digital', status: 'active', plan: 'Starter', users: 8, seat_limit: 10, trial_ends_at: null, created_at: '2025-04-02' },
        { id: 3, name: 'Blue Harbor Studio', slug: 'blue-harbor', status: 'trial', plan: 'Growth', users: 5, seat_limit: 80, trial_ends_at: '2026-08-05', created_at: '2026-07-08' },
        { id: 4, name: 'Rivera & Co', slug: 'rivera-co', status: 'past_due', plan: 'Starter', users: 12, seat_limit: 10, trial_ends_at: null, created_at: '2024-11-19' },
        { id: 5, name: 'Summit Freelance', slug: 'summit-freelance', status: 'active', plan: 'Free', users: 1, seat_limit: 2, trial_ends_at: null, created_at: '2025-09-30' },
        { id: 6, name: 'Orbit Media Group', slug: 'orbit-media', status: 'suspended', plan: 'Enterprise', users: 61, seat_limit: null, trial_ends_at: null, created_at: '2024-06-01' },
    ],
    pagination: { current_page: 1, last_page: 2, total: 34, per_page: 25 },
    filters: {},
    planOptions: [
        { key: 'free', name: 'Free' }, { key: 'starter', name: 'Starter' },
        { key: 'pro', name: 'Growth' }, { key: 'enterprise', name: 'Enterprise' },
    ],
    stats: { tenants: 34, active: 21, trialing: 8, suspended: 2 },
};

function ConfirmModal({ open, title, description, confirmLabel, tone = 'primary', onConfirm, onClose }) {
    return (
        <Modal show={open} onClose={onClose} maxWidth="md">
            <div className="p-6">
                <h3 className="text-lg font-semibold text-body">{title}</h3>
                {description && <p className="mt-2 text-sm text-muted">{description}</p>}
                <div className="mt-6 flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button variant={tone} onClick={onConfirm}>{confirmLabel}</Button>
                </div>
            </div>
        </Modal>
    );
}

export default function PlatformIndex({ organizations, pagination, filters, planOptions, stats, preview = false }) {
    const data = preview ? DEV_SAMPLE : { organizations, pagination, filters, planOptions, stats };
    const orgs = data.organizations || [];
    const page = data.pagination || { current_page: 1, last_page: 1, total: orgs.length };

    const [search, setSearch] = useState(data.filters?.q || '');
    const [statusFilter, setStatusFilter] = useState(data.filters?.status || '');
    const [planFilter, setPlanFilter] = useState(data.filters?.plan || '');
    const [confirm, setConfirm] = useState(null);

    // Debounced server-side search so typing doesn't fire a request per keystroke.
    const first = useRef(true);
    useEffect(() => {
        if (preview) return undefined;
        if (first.current) { first.current = false; return undefined; }
        const t = setTimeout(() => visit({ q: search, status: statusFilter, plan: planFilter }), 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, statusFilter, planFilter]);

    const visit = (params) => {
        const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v != null));
        router.get(rt('platform.index'), clean, { preserveState: true, preserveScroll: true, replace: true });
    };

    const goPage = (n) => {
        if (preview) return;
        visit({ q: search, status: statusFilter, plan: planFilter, page: n });
    };

    const runStatus = (org, action) => {
        if (!preview) router.post(rt(`platform.${action}`, org.id), {}, { preserveScroll: true });
        setConfirm(null);
    };
    const runImpersonate = (org) => {
        if (!preview) router.post(rt('platform.impersonate', org.id));
        setConfirm(null);
    };

    const askSuspend = (org) => setConfirm({ org, action: org.status === 'suspended' ? 'resume' : 'suspend' });
    const askImpersonate = (org) => setConfirm({ org, action: 'impersonate' });

    return (
        <PlatformLayout
            title="Tenants"
            header={
                <PlatformPageHeader
                    title="Tenants"
                    description="Every workspace, its plan and seats. Open one to grant a per-customer exception, attach an add-on, or read who changed what."
                    actions={
                        <>
                            <Button as={Link} href={rt('platform.addons')} variant="ghost" size="sm" icon={<Layers className="h-4 w-4" />}>
                                Add-ons
                            </Button>
                            <Button as={Link} href={rt('platform.audit')} variant="ghost" size="sm" icon={<History className="h-4 w-4" />}>
                                Activity
                            </Button>
                            <Button as={Link} href={rt('platform.plans')} variant="secondary" size="sm" icon={<Tags className="h-4 w-4" />}>
                                Plans &amp; pricing
                            </Button>
                        </>
                    }
                />
            }
        >
            <Head title="Tenants" />

            <div className="w-full space-y-6">
                {/* Headline KPI row — value-only KpiCards (the payload carries no
                    series or deltas; nothing is fabricated). Contract accents:
                    orgs → 3 (blue), good-standing → 2 (green), trials/pending →
                    4 (amber), suspended → 5 (the special/exception set). The
                    tenant-STATE indicators in the table stay status Badges. */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <KpiCard accent={3} icon={<Building2 size={18} />} label="Tenants" value={data.stats?.tenants ?? 0} />
                    <KpiCard accent={2} icon={<Activity size={18} />} label="Active" value={data.stats?.active ?? 0} />
                    <KpiCard accent={4} icon={<Timer size={18} />} label="Trialing" value={data.stats?.trialing ?? 0} />
                    <KpiCard accent={5} icon={<PauseCircle size={18} />} label="Suspended" value={data.stats?.suspended ?? 0} />
                </div>

                <Card padded={false}>
                    <div className="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-end">
                        <Field label="Search" className="sm:max-w-xs" htmlFor="tenant-search">
                            <Input
                                id="tenant-search"
                                type="search"
                                placeholder="Name, slug or owner email…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </Field>
                        <Field label="Status" className="sm:w-44" htmlFor="tenant-status">
                            <Select id="tenant-status" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                                <option value="">All statuses</option>
                                <option value="active">Active</option>
                                <option value="trial">Trialing</option>
                                <option value="past_due">Past due</option>
                                <option value="suspended">Suspended</option>
                            </Select>
                        </Field>
                        <Field label="Plan" className="sm:w-44" htmlFor="tenant-plan">
                            <Select id="tenant-plan" value={planFilter} onChange={(e) => setPlanFilter(e.target.value)}>
                                <option value="">All plans</option>
                                {(data.planOptions || []).map((p) => <option key={p.key} value={p.key}>{p.name}</option>)}
                            </Select>
                        </Field>
                        <div className="text-sm text-muted sm:ml-auto sm:pb-2.5">
                            {page.total} matching
                        </div>
                    </div>

                    {orgs.length === 0 ? (
                        <EmptyState
                            title={data.filters?.q || data.filters?.status || data.filters?.plan ? 'No matches' : 'No tenants yet'}
                            description={data.filters?.q || data.filters?.status || data.filters?.plan
                                ? 'Try a different search or clear the filters.'
                                : 'Tenants appear here once organizations sign up.'}
                        />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>Organization</Th>
                                    <Th>Status</Th>
                                    <Th>Plan</Th>
                                    <Th className="text-right">Users</Th>
                                    <Th className="text-right">Trial ends</Th>
                                    <Th className="text-right">Actions</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {orgs.map((org) => (
                                    <Tr key={org.id}>
                                        <Td>
                                            <Link
                                                href={rt('platform.show', org.id)}
                                                className="font-medium text-body transition hover:text-accent focus-visible:outline-none focus-visible:text-accent"
                                            >
                                                {org.name}
                                            </Link>
                                            <div className="text-xs text-muted">{org.slug}</div>
                                        </Td>
                                        <Td>
                                            <Badge tone={STATUS_TONE[org.status] || 'neutral'} dot>
                                                {STATUS_LABEL[org.status] || org.status}
                                            </Badge>
                                        </Td>
                                        <Td>
                                            {org.plan
                                                ? <Badge tone="accent">{org.plan}</Badge>
                                                : <span className="text-subtle">—</span>}
                                        </Td>
                                        <Td className="text-right tabular-nums">
                                            {org.users}
                                            <span className="text-muted"> / {org.seat_limit ?? '∞'}</span>
                                        </Td>
                                        <Td className="text-right text-muted tabular-nums">{org.trial_ends_at || '—'}</Td>
                                        <Td>
                                            <div className="flex items-center justify-end gap-2">
                                                <Button size="sm" variant="ghost" onClick={() => askImpersonate(org)}>View as</Button>
                                                {org.status === 'suspended'
                                                    ? <Button size="sm" variant="primary" onClick={() => askSuspend(org)}>Resume</Button>
                                                    : <Button size="sm" variant="secondary" onClick={() => askSuspend(org)}>Suspend</Button>}
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}

                    {page.last_page > 1 && (
                        <div className="flex items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm text-muted">
                            <span>Page {page.current_page} of {page.last_page}</span>
                            <div className="flex gap-2">
                                <Button size="sm" variant="secondary" disabled={page.current_page <= 1} onClick={() => goPage(page.current_page - 1)}>
                                    Previous
                                </Button>
                                <Button size="sm" variant="secondary" disabled={page.current_page >= page.last_page} onClick={() => goPage(page.current_page + 1)}>
                                    Next
                                </Button>
                            </div>
                        </div>
                    )}
                </Card>
            </div>

            <ConfirmModal
                open={confirm?.action === 'impersonate'}
                title="Sign in as this tenant?"
                description={confirm ? `You'll sign in as ${confirm.org.name}'s owner. This is recorded in the activity log with your name and IP.` : ''}
                confirmLabel="Sign in as owner"
                tone="primary"
                onConfirm={() => runImpersonate(confirm.org)}
                onClose={() => setConfirm(null)}
            />
            <ConfirmModal
                open={confirm?.action === 'suspend'}
                title="Suspend this tenant?"
                description={confirm ? `${confirm.org.name} will lose access until you resume them. No data is deleted.` : ''}
                confirmLabel="Suspend"
                tone="danger"
                onConfirm={() => runStatus(confirm.org, 'suspend')}
                onClose={() => setConfirm(null)}
            />
            <ConfirmModal
                open={confirm?.action === 'resume'}
                title="Resume this tenant?"
                description={confirm ? `${confirm.org.name} will regain access immediately.` : ''}
                confirmLabel="Resume"
                tone="primary"
                onConfirm={() => runStatus(confirm.org, 'resume')}
                onClose={() => setConfirm(null)}
            />
        </PlatformLayout>
    );
}
