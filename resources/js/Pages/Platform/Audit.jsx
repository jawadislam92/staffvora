import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import { Button, Card, Badge, Field, Input, Select, EmptyState } from '@/Components/ui';
import { rt } from './entitlementUi';
import AuditFeed from './AuditFeed';

// ─────────────────────────────────────────────────────────────────────────────
// OPERATOR ACTIVITY LOG — the cross-tenant answer to "who changed what, when".
//
// Landlord-side only: `platform_audit_logs` is not tenant-scoped and no customer
// route can reach it. The table is append-only (the model refuses updates and
// deletes), so this page is read-only by construction.
// ─────────────────────────────────────────────────────────────────────────────

const DEV_SAMPLE = {
    entries: {
        data: [
            {
                id: 9, action: 'tenant.impersonated', label: 'Signed in as customer', actor: 'Sana (support)',
                organization: { id: 3, name: 'Blue Harbor Studio' }, reason: null, ip: '203.0.113.9',
                at: '2026-08-02T09:14:00+00:00', target_type: 'user', target_id: 12,
                before: null, after: { stage: 'entered', target_user: { id: 12, name: 'Mara Quist' } },
            },
            {
                id: 8, action: 'tenant.overrides_changed', label: 'Access overrides changed', actor: 'Jawad',
                organization: { id: 3, name: 'Blue Harbor Studio' }, reason: 'Sales trial of compliance',
                ip: '198.51.100.4', at: '2026-08-01T16:02:00+00:00', target_type: 'organization', target_id: 3,
                before: { entitlement_overrides: { max_users: 8 } },
                after: { entitlement_overrides: { max_users: 8, machine_compliance: { value: true, expires_at: '2026-09-01T00:00:00+00:00', reason: 'Sales trial' } } },
            },
            {
                id: 7, action: 'plan.updated', label: 'Plan updated', actor: 'Jawad', organization: null,
                reason: 'Q3 price review', ip: '198.51.100.4', at: '2026-07-30T11:40:00+00:00',
                target_type: 'plan', target_id: 3,
                before: { name: 'Growth', price_cents: 900, price_cents_yearly: 9000 },
                after: { name: 'Growth', price_cents: 1000, price_cents_yearly: 10000 },
            },
        ],
        current_page: 1, last_page: 3, total: 61,
    },
    filters: {},
    actions: [
        { value: 'plan.updated', label: 'Plan updated' },
        { value: 'tenant.overrides_changed', label: 'Access overrides changed' },
        { value: 'tenant.impersonated', label: 'Signed in as customer' },
    ],
    operators: [{ id: 1, name: 'Jawad', role: 'owner' }, { id: 2, name: 'Sana', role: 'support' }],
    organizations: [{ id: 3, name: 'Blue Harbor Studio' }],
};

export default function Audit({ entries, filters, actions, operators, organizations, preview = false }) {
    const data = preview ? DEV_SAMPLE : { entries, filters, actions, operators, organizations };
    const list = data.entries?.data || [];

    const [form, setForm] = useState({
        action: data.filters?.action || '',
        organization_id: data.filters?.organization_id || '',
        platform_user_id: data.filters?.platform_user_id || '',
        from: data.filters?.from || '',
        to: data.filters?.to || '',
    });

    const apply = (next) => {
        setForm(next);
        if (preview) return;
        const clean = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v !== null));
        router.get(rt('platform.audit'), clean, { preserveState: true, preserveScroll: true, replace: true });
    };

    const set = (key, value) => apply({ ...form, [key]: value });

    const goPage = (page) => {
        if (preview) return;
        const clean = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null));
        router.get(rt('platform.audit'), { ...clean, page }, { preserveScroll: true });
    };

    return (
        <PlatformLayout
            title="Activity"
            header={
                <PlatformPageHeader
                    title="Operator activity"
                    description="Every change your staff made to a plan, a feature, an add-on or a customer's access — including every time somebody signed in as a customer. Append-only: nothing here can be edited or deleted."
                    actions={<Button as={Link} href={rt('platform.plans')} variant="ghost" size="sm">Plans</Button>}
                />
            }
        >
            <Head title="Operator activity" />

            <div className="w-full space-y-5">
                <Card padded={false}>
                    <div className="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-5">
                        <Field label="Action" htmlFor="au-action">
                            <Select id="au-action" value={form.action} onChange={(e) => set('action', e.target.value)}>
                                <option value="">All actions</option>
                                {(data.actions || []).map((a) => <option key={a.value} value={a.value}>{a.label}</option>)}
                            </Select>
                        </Field>
                        <Field label="Company" htmlFor="au-org">
                            <Select id="au-org" value={form.organization_id} onChange={(e) => set('organization_id', e.target.value)}>
                                <option value="">All companies</option>
                                {(data.organizations || []).map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                            </Select>
                        </Field>
                        <Field label="Operator" htmlFor="au-op">
                            <Select id="au-op" value={form.platform_user_id} onChange={(e) => set('platform_user_id', e.target.value)}>
                                <option value="">Anyone</option>
                                {(data.operators || []).map((o) => <option key={o.id} value={o.id}>{o.name} ({o.role})</option>)}
                            </Select>
                        </Field>
                        <Field label="From" htmlFor="au-from">
                            <Input id="au-from" type="date" value={form.from} onChange={(e) => set('from', e.target.value)} />
                        </Field>
                        <Field label="To" htmlFor="au-to">
                            <Input id="au-to" type="date" value={form.to} onChange={(e) => set('to', e.target.value)} />
                        </Field>
                    </div>

                    <div className="p-4">
                        {list.length === 0 ? (
                            <EmptyState
                                title="Nothing recorded yet"
                                description="Operator actions appear here the moment somebody changes a plan, a feature or a customer's access."
                            />
                        ) : (
                            <AuditFeed entries={list} showOrganization />
                        )}
                    </div>

                    {data.entries?.last_page > 1 && (
                        <div className="flex items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm text-muted">
                            <span>
                                Page {data.entries.current_page} of {data.entries.last_page} · {data.entries.total} entries
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    disabled={data.entries.current_page <= 1}
                                    onClick={() => goPage(data.entries.current_page - 1)}
                                >
                                    Previous
                                </Button>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    disabled={data.entries.current_page >= data.entries.last_page}
                                    onClick={() => goPage(data.entries.current_page + 1)}
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    )}
                </Card>

                <p className="text-xs text-subtle">
                    Impersonation is recorded twice on purpose: once when an operator asks for the hand-off link
                    (<Badge tone="neutral">requested</Badge>) and once when the customer session is actually
                    established (<Badge tone="neutral">entered</Badge>), so a link that was minted but never used is
                    still visible.
                </p>
            </div>
        </PlatformLayout>
    );
}
