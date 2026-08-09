import { useMemo, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle, ArrowLeft, ArrowRight, CalendarClock, Check, CircleDollarSign, Clock,
    Info, Layers, Plus, Repeat, ShieldCheck, Trash2, TrendingDown, Users, X,
} from 'lucide-react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import {
    Button, Card, KpiCard, Badge, Table, THead, TBody, Tr, Th, Td,
    Field, Input, Select, Tabs, EmptyState,
} from '@/Components/ui';
import Modal from '@/Components/Modal';
import AuditFeed from './AuditFeed';
import { UNLIMITED, SOURCE_META, formatValue, groupRegistry, money, rt } from './entitlementUi';

// ─────────────────────────────────────────────────────────────────────────────
// ONE CUSTOMER — everything the operator can do to a single company.
//
//   Overview  who they are, their SUBSCRIPTION (plan / cycle / status / trial)
//   Access    the EFFECTIVE entitlement map with provenance (override / add-on /
//             plan / default) plus the per-company override editor
//   Add-ons   grant or revoke a bought bundle, optionally time-boxed
//   Billing   record an off-platform payment, see the history
//   History   who changed this company's access, and when
//
// The Access tab never re-implements a rule: `effective[key].source` comes
// straight from the server's single resolver, and `layers[key]` is only what
// each layer SAYS, shown so the answer is explainable.
// ─────────────────────────────────────────────────────────────────────────────

const STATUS_TONE = { trial: 'warning', active: 'success', past_due: 'danger', suspended: 'neutral' };
const STATUS_LABEL = { trial: 'Trialing', active: 'Active', past_due: 'Past due', suspended: 'Suspended' };

// The SUBSCRIPTION's own states (subscriptions.status) — a different column from
// the organization's, and deliberately not merged with it.
const SUB_STATUS_TONE = { trialing: 'warning', active: 'success', past_due: 'danger', canceled: 'neutral' };
const SUB_STATUS_LABEL = { trialing: 'Trialing', active: 'Active', past_due: 'Past due', canceled: 'Cancelled' };
const SUB_STATUS_HELP = {
    active: 'Paying and in good standing.',
    past_due: 'Payment is overdue — the workspace is refused until it is settled or the trial/period is extended.',
    canceled: 'The subscription has ended. The workspace is refused, but nothing is deleted and the plan is remembered.',
};

const VISIBILITY_NOTE = {
    public: null,
    hidden: 'hidden',
    custom: 'custom',
    legacy: 'legacy',
};

// ── Offline preview fixtures ────────────────────────────────────────────────
const DEV_SAMPLE = {
    organization: {
        id: 3, name: 'Blue Harbor Studio', slug: 'blue-harbor', status: 'trial', created_at: '2026-07-08',
        users: 5, owner: { name: 'Mara Quist', email: 'mara@blueharbor.co' }, trial_ends_at: '2026-08-05',
        discount_percent: 15, base_price_cents: 4900, effective_price_cents: 4165, currency: 'USD',
    },
    subscription: {
        plan_key: 'pro', plan_name: 'Growth', status: 'trialing', billing_cycle: 'monthly',
        trial_ends_at: '2026-08-05', current_period_start: '2026-07-05', current_period_end: '2026-08-05',
        on_trial: true, usable: true,
        settable_statuses: ['active', 'past_due', 'canceled'],
        cycles: ['monthly', 'yearly'],
    },
    plans: [
        { key: 'free', name: 'Free', price_cents: 0, price_cents_yearly: 0, currency: 'USD', visibility: 'public', archived: false, max_users: 2 },
        { key: 'starter', name: 'Starter', price_cents: 500, price_cents_yearly: 5000, currency: 'USD', visibility: 'public', archived: false, max_users: 10 },
        { key: 'pro', name: 'Growth', price_cents: 900, price_cents_yearly: 9000, currency: 'USD', visibility: 'public', archived: false, max_users: 50 },
        { key: 'enterprise', name: 'Enterprise', price_cents: 1800, price_cents_yearly: null, currency: 'USD', visibility: 'custom', archived: false, max_users: null },
        { key: 'pro_2025', name: 'Growth (2025)', price_cents: 700, price_cents_yearly: 7000, currency: 'USD', visibility: 'legacy', archived: true, max_users: 50 },
    ],
    planImpact: {
        free: {
            plan_key: 'free', plan_name: 'Free', archived: false, visibility: 'public', is_current: false,
            price_cents: 0, price_cents_yearly: 0, currency: 'USD',
            seat_limit_now: 80, seat_limit: 80, retention_days_now: 365, retention_days: 7, active_users: 5,
            gains: [],
            losses: [{ key: 'monitoring', label: 'Screenshot monitoring', help: 'Capture screenshots.', source_now: 'plan' }, { key: 'white_label', label: 'White-label branding', help: 'Custom brand.', source_now: 'plan' }],
            limits: [{ key: 'max_retention_days', label: 'Screenshot retention (max days)', unit: 'days', kind: 'max', from: 365, to: 7, tightens: true }],
            seat_overage: null,
        },
        starter: {
            plan_key: 'starter', plan_name: 'Starter', archived: false, visibility: 'public', is_current: false,
            price_cents: 500, price_cents_yearly: 5000, currency: 'USD',
            seat_limit_now: 80, seat_limit: 4, retention_days_now: 365, retention_days: 90, active_users: 5,
            gains: [],
            losses: [{ key: 'white_label', label: 'White-label branding', help: 'Custom brand.', source_now: 'plan' }],
            limits: [{ key: 'max_users', label: 'User seats', unit: 'seats', kind: 'max', from: 80, to: 4, tightens: true }],
            seat_overage: { users: 5, limit: 4, excess: 1 },
        },
        pro: {
            plan_key: 'pro', plan_name: 'Growth', archived: false, visibility: 'public', is_current: true,
            price_cents: 900, price_cents_yearly: 9000, currency: 'USD',
            seat_limit_now: 80, seat_limit: 80, retention_days_now: 365, retention_days: 365, active_users: 5,
            gains: [], losses: [], limits: [], seat_overage: null,
        },
        enterprise: {
            plan_key: 'enterprise', plan_name: 'Enterprise', archived: false, visibility: 'custom', is_current: false,
            price_cents: 1800, price_cents_yearly: null, currency: 'USD',
            seat_limit_now: 80, seat_limit: 80, retention_days_now: 365, retention_days: null, active_users: 5,
            gains: [{ key: 'api', label: 'API access' }],
            losses: [], limits: [{ key: 'max_retention_days', label: 'Screenshot retention (max days)', unit: 'days', kind: 'max', from: 365, to: null, tightens: false }],
            seat_overage: null,
        },
        pro_2025: {
            plan_key: 'pro_2025', plan_name: 'Growth (2025)', archived: true, visibility: 'legacy', is_current: false,
            price_cents: 700, price_cents_yearly: 7000, currency: 'USD',
            seat_limit_now: 80, seat_limit: 80, retention_days_now: 365, retention_days: 365, active_users: 5,
            gains: [], losses: [], limits: [], seat_overage: null,
        },
    },
    payments: [
        { amount_cents: 4165, currency: 'USD', reference: 'BANK-2026-0043', paid_at: '2026-06-05', period_end: '2026-07-05' },
        { amount_cents: 4165, currency: 'USD', reference: 'BANK-2026-0031', paid_at: '2026-05-05', period_end: '2026-06-05' },
    ],
    addons: {
        catalogue: [
            { id: 1, key: 'agency_toolkit', name: 'Agency toolkit', description: 'Upwork profiles + Machine Compliance.', price_cents: 400, price_cents_yearly: 4000, currency: 'USD', entitlements: { upwork_profiles: true, machine_compliance: true } },
            { id: 2, key: 'extra_storage', name: 'Extra storage', description: 'Lifts the storage ceiling.', price_cents: 200, price_cents_yearly: 2000, currency: 'USD', entitlements: { max_storage_gb: 'unlimited' } },
        ],
        attached: [
            { id: 1, key: 'agency_toolkit', name: 'Agency toolkit', currency: 'USD', price_cents: 400, price_cents_yearly: 4000, archived: false, starts_at: null, ends_at: '2026-09-30', in_force: true, entitlements: { upwork_profiles: true, machine_compliance: true } },
        ],
    },
    entitlements: {
        unlimited_token: 'unlimited',
        registry: {
            max_users: { key: 'max_users', kind: 'max', default: 1, group: 'Limits', unit: 'seats', label: 'User seats', help: 'Maximum users.' },
            max_storage_gb: { key: 'max_storage_gb', kind: 'max', default: 0, group: 'Limits', unit: 'GB', label: 'Storage (max GB)', help: 'Total storage.' },
            min_auto_pause_minutes: { key: 'min_auto_pause_minutes', kind: 'min', default: 10, group: 'Monitoring', unit: 'min', label: 'Auto-pause floor', help: 'Tightest idle pause.' },
            monitoring: { key: 'monitoring', kind: 'flag', default: false, group: 'Features', label: 'Screenshot monitoring', help: 'Capture screenshots.' },
            ai: { key: 'ai', kind: 'flag', default: false, group: 'Features', label: 'AI assistant', help: 'Day summaries.' },
            machine_compliance: { key: 'machine_compliance', kind: 'flag', default: false, group: 'Agency', label: 'Machine compliance', help: 'Per-PC inventory.' },
            white_label: { key: 'white_label', kind: 'flag', default: false, group: 'Branding', label: 'White-label branding', help: 'Custom brand.' },
        },
        plan_defaults: { max_users: 50, max_storage_gb: 50, min_auto_pause_minutes: 1, monitoring: true, ai: true, machine_compliance: false, white_label: true },
        overrides: {
            max_users: { value: 80, expires_at: null, reason: 'Negotiated at renewal', expired: false },
            max_storage_gb: { value: null, expires_at: null, reason: null, expired: false },
            min_auto_pause_minutes: { value: null, expires_at: null, reason: null, expired: false },
            monitoring: { value: null, expires_at: null, reason: null, expired: false },
            ai: { value: false, expires_at: null, reason: 'Customer asked us to switch AI off', expired: false },
            machine_compliance: { value: null, expires_at: null, reason: null, expired: false },
            white_label: { value: null, expires_at: null, reason: null, expired: false },
        },
        layers: {
            max_users: { addons: [], plan: 50, has_plan: true, default: 1 },
            max_storage_gb: { addons: [{ name: 'Agency toolkit', key: 'agency_toolkit', value: 'unlimited' }], plan: 50, has_plan: true, default: 0 },
            min_auto_pause_minutes: { addons: [], plan: 1, has_plan: true, default: 10 },
            monitoring: { addons: [], plan: true, has_plan: true, default: false },
            ai: { addons: [], plan: true, has_plan: true, default: false },
            machine_compliance: { addons: [{ name: 'Agency toolkit', key: 'agency_toolkit', value: true }], plan: false, has_plan: true, default: false },
            white_label: { addons: [], plan: true, has_plan: true, default: false },
        },
        effective: {
            max_users: { value: 80, source: 'override' },
            max_storage_gb: { value: null, source: 'addon' },
            min_auto_pause_minutes: { value: 1, source: 'plan' },
            monitoring: { value: true, source: 'plan' },
            ai: { value: false, source: 'override' },
            machine_compliance: { value: true, source: 'addon' },
            white_label: { value: true, source: 'plan' },
        },
        custom_price_cents: null,
    },
    history: {
        data: [
            { id: 8, action: 'tenant.overrides_changed', label: 'Access overrides changed', actor: 'Jawad', reason: 'Negotiated at renewal', ip: '198.51.100.4', at: '2026-08-01T16:02:00+00:00', before: { entitlement_overrides: { max_users: 50 } }, after: { entitlement_overrides: { max_users: 80 } } },
            { id: 7, action: 'addon.granted', label: 'Add-on granted', actor: 'Jawad', reason: 'Paid for Q3', ip: '198.51.100.4', at: '2026-07-30T10:11:00+00:00', before: null, after: { addon: 'agency_toolkit', ends_at: '2026-09-30T23:59:59+00:00' } },
        ],
        current_page: 1, last_page: 1, total: 2,
    },
};

// Small confirmation dialog for the consequential actions.
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

export default function PlatformShow({ organization, subscription, plans, planImpact, payments, addons, entitlements, history, preview = false }) {
    const data = preview
        ? DEV_SAMPLE
        : { organization, subscription, plans, planImpact, payments, addons, entitlements, history };
    const org = data.organization;
    const sub = data.subscription;
    const planList = data.plans || [];
    const paymentList = data.payments || [];

    const pageRole = usePage().props.platformUser?.role;
    const role = preview ? 'owner' : pageRole;
    const canManage = role === 'owner' || role === 'admin';

    const [tab, setTab] = useState('overview');
    const [dialog, setDialog] = useState(null);
    const [trialDate, setTrialDate] = useState('');
    const closeDialog = () => setDialog(null);

    const setStatus = (action) => {
        if (!preview) router.post(rt(`platform.${action}`, org.id), {}, { preserveScroll: true });
        closeDialog();
    };
    const impersonate = () => {
        if (!preview) router.post(rt('platform.impersonate', org.id));
        closeDialog();
    };
    const trial = (action, days) => {
        if (preview) { closeDialog(); return; }
        router.post(rt('platform.trial', org.id), {
            action,
            days: days || null,
            date: action === 'set' ? trialDate : null,
        }, { preserveScroll: true });
        closeDialog();
    };

    const tabs = [
        { key: 'overview', label: 'Overview' },
        { key: 'access', label: 'Access' },
        { key: 'addons', label: `Add-ons${data.addons?.attached?.length ? ` (${data.addons.attached.length})` : ''}` },
        { key: 'billing', label: 'Billing' },
        canManage && { key: 'history', label: 'History' },
    ].filter(Boolean);

    return (
        <PlatformLayout
            title={org.name}
            header={
                <PlatformPageHeader
                    title={org.name}
                    description={org.slug}
                    actions={
                        <>
                            <Button variant="ghost" onClick={() => setDialog('impersonate')}>View as owner</Button>
                            {org.status === 'suspended'
                                ? <Button variant="primary" onClick={() => setDialog('resume')}>Resume</Button>
                                : canManage && <Button variant="secondary" onClick={() => setDialog('suspend')}>Suspend</Button>}
                        </>
                    }
                />
            }
        >
            <Head title={org.name} />

            <div className="w-full space-y-6">
                <Link
                    href={rt('platform.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-muted transition hover:text-body focus-visible:outline-none focus-visible:text-body"
                >
                    <ArrowLeft className="h-4 w-4" /> All tenants
                </Link>

                {/* Headline KPI row — value-only KpiCards (same data as before,
                    no fabricated deltas/series). Contract accents: people → 3,
                    money → 5, trial/pending → 4. The Status tile keeps the
                    tenant-state as a status Badge (state stays a status token,
                    never a viz slot); its card wears the org-identity blue. */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <KpiCard accent={3} icon={<Users size={18} />} label="Users" value={org.users} sub={sub ? sub.plan_name + ' plan' : 'No plan'} />
                    <KpiCard
                        accent={5}
                        icon={<CircleDollarSign size={18} />}
                        label="You charge"
                        value={org.effective_price_cents != null ? money(org.effective_price_cents, org.currency) : '—'}
                        sub={org.discount_percent ? `${org.discount_percent}% off list` : 'per month'}
                    />
                    <KpiCard
                        accent={3}
                        icon={<ShieldCheck size={18} />}
                        label="Status"
                        value={<Badge tone={STATUS_TONE[org.status] || 'neutral'} size="md" dot>{STATUS_LABEL[org.status] || org.status}</Badge>}
                    />
                    <KpiCard accent={4} icon={<CalendarClock size={18} />} label="Trial ends" value={org.trial_ends_at || '—'} />
                </div>

                <div className="max-w-full overflow-x-auto">
                    <Tabs tabs={tabs} value={tab} onChange={setTab} />
                </div>

                {tab === 'overview' && (
                    <div className="space-y-4">
                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card title="Organization">
                                <dl className="space-y-2.5 text-sm">
                                    <Row label="Slug" value={org.slug} />
                                    <Row label="Status" value={<Badge tone={STATUS_TONE[org.status] || 'neutral'} dot>{STATUS_LABEL[org.status] || org.status}</Badge>} />
                                    <Row label="Users" value={org.users} />
                                    <Row label="Owner" value={org.owner?.email || '—'} />
                                    <Row label="Created" value={org.created_at} />
                                </dl>
                            </Card>

                            <Card title="Subscription" actions={sub && !sub.usable ? <Badge tone="danger" dot>Not usable</Badge> : null}>
                                {sub ? (
                                    <dl className="space-y-2.5 text-sm">
                                        <Row label="Plan" value={<Badge tone="accent">{sub.plan_name}</Badge>} />
                                        <Row label="Status" value={<Badge tone={SUB_STATUS_TONE[sub.status] || 'neutral'} dot>{SUB_STATUS_LABEL[sub.status] || sub.status}</Badge>} />
                                        <Row label="Billing cycle" value={<span className="capitalize text-body">{sub.billing_cycle || 'monthly'}</span>} />
                                        <Row label="Trial ends" value={sub.trial_ends_at || '—'} />
                                        <Row label="Period ends" value={sub.current_period_end || '—'} />
                                        <Row
                                            label="You charge"
                                            value={org.effective_price_cents != null ? (
                                                <span>
                                                    {money(org.effective_price_cents, org.currency)}/mo
                                                    {org.discount_percent ? <span className="text-success"> · {org.discount_percent}% off</span> : null}
                                                </span>
                                            ) : '—'}
                                        />
                                    </dl>
                                ) : (
                                    <p className="text-sm text-muted">No subscription.</p>
                                )}
                            </Card>
                        </div>

                        {canManage && sub && (
                            <SubscriptionPanel
                                organization={org}
                                sub={sub}
                                plans={planList}
                                impact={data.planImpact || {}}
                                preview={preview}
                            />
                        )}

                        {canManage && (
                            <Card
                                title="Trial"
                                description="The same subscription, in its trialing state — starting or extending a trial puts the subscription back on “Trialing”, and ending one converts it to “Active”."
                                actions={<span className="text-sm text-muted">Ends: <span className="font-semibold text-body">{org.trial_ends_at || '—'}</span></span>}
                            >
                                <div className="flex flex-wrap items-end gap-2">
                                    {[7, 14, 30].map((d) => (
                                        <Button key={d} size="sm" variant="secondary" onClick={() => trial('extend', d)}>+{d} days</Button>
                                    ))}
                                    <Button size="sm" variant="ghost" className="text-danger" onClick={() => setDialog('endTrial')}>End trial now</Button>
                                    <span className="mx-1 self-center text-subtle">·</span>
                                    <Field label="Set an exact end date" htmlFor="trial-date" className="w-auto">
                                        <div className="flex items-center gap-2">
                                            <Input id="trial-date" type="date" value={trialDate} onChange={(e) => setTrialDate(e.target.value)} className="w-auto" />
                                            <Button size="sm" variant="primary" disabled={!trialDate} onClick={() => trial('set')}>Set date</Button>
                                        </div>
                                    </Field>
                                </div>
                            </Card>
                        )}
                    </div>
                )}

                {tab === 'access' && (
                    data.entitlements ? (
                        <AccessTab
                            organization={org}
                            plans={planList}
                            currentPlanKey={sub?.plan_key}
                            entitlements={data.entitlements}
                            canManage={canManage}
                            preview={preview}
                        />
                    ) : <Card><p className="text-sm text-muted">No entitlement data.</p></Card>
                )}

                {tab === 'addons' && (
                    <AddonsTab organization={org} addons={data.addons} canManage={canManage} preview={preview} />
                )}

                {tab === 'billing' && (
                    <div className="space-y-4">
                        {canManage && <PaymentForm org={org} sub={sub} plans={planList} preview={preview} />}
                        <Card title="Payment history" padded={false}>
                            {paymentList.length === 0 ? (
                                <EmptyState title="No payments recorded" description="Recorded bank transfers appear here." />
                            ) : (
                                <Table>
                                    <THead>
                                        <Tr>
                                            <Th>Date</Th>
                                            <Th>Reference</Th>
                                            <Th>Through</Th>
                                            <Th className="text-right">Amount</Th>
                                        </Tr>
                                    </THead>
                                    <TBody>
                                        {paymentList.map((p, i) => (
                                            <Tr key={i}>
                                                <Td className="tabular-nums">{p.paid_at}</Td>
                                                <Td className="text-muted">{p.reference || '—'}</Td>
                                                <Td className="text-muted tabular-nums">{p.period_end || '—'}</Td>
                                                <Td className="text-right font-medium tabular-nums">{money(p.amount_cents, p.currency)}</Td>
                                            </Tr>
                                        ))}
                                    </TBody>
                                </Table>
                            )}
                        </Card>
                    </div>
                )}

                {tab === 'history' && canManage && (
                    <HistoryTab organization={org} history={data.history} preview={preview} />
                )}
            </div>

            <ConfirmModal
                open={dialog === 'impersonate'}
                title="Sign in as this tenant?"
                description={`You'll sign in as ${org.name}'s owner and can see their employees' monitoring data. This is recorded in the activity log with your name, the time and your IP.`}
                confirmLabel="Sign in as owner"
                tone="primary"
                onConfirm={impersonate}
                onClose={closeDialog}
            />
            <ConfirmModal
                open={dialog === 'suspend'}
                title="Suspend this tenant?"
                description={`${org.name} will lose access until you resume them. No data is deleted.`}
                confirmLabel="Suspend"
                tone="danger"
                onConfirm={() => setStatus('suspend')}
                onClose={closeDialog}
            />
            <ConfirmModal
                open={dialog === 'resume'}
                title="Resume this tenant?"
                description={`${org.name} will regain access immediately.`}
                confirmLabel="Resume"
                tone="primary"
                onConfirm={() => setStatus('resume')}
                onClose={closeDialog}
            />
            <ConfirmModal
                open={dialog === 'endTrial'}
                title="End this trial now?"
                description={`${org.name}'s trial will end immediately.`}
                confirmLabel="End trial"
                tone="danger"
                onConfirm={() => trial('end')}
                onClose={closeDialog}
            />
        </PlatformLayout>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-4">
            <dt className="text-muted">{label}</dt>
            <dd className="text-right text-body">{value}</dd>
        </div>
    );
}

// ─── Subscription management ────────────────────────────────────────────────
// The control the console was missing: move a company between plans, flip the
// billing cycle, and set the subscription's state.
//
// Nothing here decides access. It changes the SUBSCRIPTION; the server still
// resolves every capability through the one engine (per-company override →
// add-on → plan → deny), which is also what produced the preview below — so a
// capability an override grants is correctly NOT listed as something the switch
// takes away.
//
// The preview is the point. Losing a feature the customer is using is exactly
// the surprise that generates support tickets, so the losses are shown in full,
// in the destructive tone, before anything is applied.

/** A resolved limit rendered for reading: "Unlimited" / "Not included" / "90 days". */
const limitText = (value, unit) => {
    if (value === null || value === undefined) return 'Unlimited';
    if (value === 0) return 'Not included';
    return unit ? `${value} ${unit}` : String(value);
};

function DeltaRow({ label, from, to, unit, tightens }) {
    const same = from === to;
    return (
        <div className="flex items-center justify-between gap-3 py-1.5 text-sm">
            <span className="text-muted">{label}</span>
            <span className="flex items-center gap-1.5 tabular-nums">
                <span className={same ? 'text-body' : 'text-subtle line-through'}>{limitText(from, unit)}</span>
                {!same && (
                    <>
                        <ArrowRight className="h-3.5 w-3.5 text-subtle" aria-hidden="true" />
                        <span className={tightens ? 'font-semibold text-warning' : 'font-semibold text-body'}>
                            {limitText(to, unit)}
                        </span>
                    </>
                )}
            </span>
        </div>
    );
}

function SubscriptionPanel({ organization, sub, plans, impact, preview }) {
    const cycles = sub.cycles || ['monthly', 'yearly'];
    const statuses = sub.settable_statuses || ['active', 'past_due', 'canceled'];

    const form = useForm({
        plan: sub.plan_key || '',
        billing_cycle: sub.billing_cycle || 'monthly',
        status: sub.status,
        acknowledge_seat_overage: false,
        reason: '',
    });

    const [confirming, setConfirming] = useState(false);

    const selected = plans.find((p) => p.key === form.data.plan) || null;
    const preview_ = impact?.[form.data.plan] || null;

    const planMoved = form.data.plan !== sub.plan_key;
    const cycleMoved = form.data.billing_cycle !== (sub.billing_cycle || 'monthly');
    // `trialing` is not offered here, so a trialing subscription only counts as
    // "moved" once the operator actually picks one of the settable states.
    const statusMoved = form.data.status !== sub.status && statuses.includes(form.data.status);
    const dirty = planMoved || cycleMoved || statusMoved;

    // A downgrade past the seat count is refused server-side unless it is
    // acknowledged; mirror that here so the button explains itself.
    const overage = planMoved ? preview_?.seat_overage : null;
    const blocked = !!overage && !form.data.acknowledge_seat_overage;

    const apply = () => {
        setConfirming(false);
        if (preview) return;
        form.transform((d) => ({
            plan: d.plan !== sub.plan_key ? d.plan : null,
            billing_cycle: d.billing_cycle !== (sub.billing_cycle || 'monthly') ? d.billing_cycle : null,
            status: statusMoved ? d.status : null,
            acknowledge_seat_overage: d.acknowledge_seat_overage,
            reason: d.reason || null,
        })).put(rt('platform.subscription.update', organization.id), {
            preserveScroll: true,
            onSuccess: () => form.setData({
                ...form.data,
                acknowledge_seat_overage: false,
                reason: '',
            }),
        });
    };

    const summary = [
        planMoved && `plan → ${selected?.name || form.data.plan}`,
        cycleMoved && `billed ${form.data.billing_cycle}`,
        statusMoved && `status → ${SUB_STATUS_LABEL[form.data.status] || form.data.status}`,
    ].filter(Boolean).join(', ');

    return (
        <Card
            title="Manage subscription"
            description="Change the plan, the billing cycle or the subscription's state. Every change is recorded against this company with your name, the time and the before → after values."
            actions={dirty ? <Badge tone="warning" dot>Unsaved</Badge> : <Badge tone="neutral">In sync</Badge>}
        >
            <div className="space-y-5">
                <div className="grid gap-4 lg:grid-cols-3">
                    {/* ── Plan ─────────────────────────────────────────────── */}
                    <Field
                        label="Plan"
                        htmlFor="sub-plan"
                        hint="Archived and legacy plans are listed too — a customer may legitimately sit on one."
                        error={form.errors.plan}
                    >
                        <Select id="sub-plan" value={form.data.plan} onChange={(e) => form.setData({ ...form.data, plan: e.target.value, acknowledge_seat_overage: false })}>
                            {plans.map((p) => (
                                <option key={p.key} value={p.key}>
                                    {p.name}
                                    {p.archived ? ' · archived' : VISIBILITY_NOTE[p.visibility] ? ` · ${VISIBILITY_NOTE[p.visibility]}` : ''}
                                    {' — '}{money(p.price_cents, p.currency)}/mo
                                    {p.key === sub.plan_key ? ' (current)' : ''}
                                </option>
                            ))}
                        </Select>
                    </Field>

                    {/* ── Billing cycle ────────────────────────────────────── */}
                    <Field
                        label="Billing cycle"
                        htmlFor="sub-cycle"
                        hint="Which price this company is billed on. It is never an access decision."
                        error={form.errors.billing_cycle}
                    >
                        <div id="sub-cycle" className="flex gap-2" role="group" aria-label="Billing cycle">
                            {cycles.map((c) => {
                                const on = form.data.billing_cycle === c;
                                const price = c === 'yearly' ? selected?.price_cents_yearly : selected?.price_cents;
                                return (
                                    <button
                                        key={c}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() => form.setData('billing_cycle', c)}
                                        className={`flex-1 rounded-md border px-3 py-2 text-sm capitalize transition ${
                                            on
                                                ? 'border-accent bg-accent text-white'
                                                : 'border-line bg-surface text-muted hover:text-body'
                                        }`}
                                    >
                                        <span className="flex items-center justify-center gap-1.5">
                                            {on && <Check className="h-3.5 w-3.5" aria-hidden="true" />}
                                            {c}
                                        </span>
                                        <span className={`block text-xs ${on ? 'text-white/80' : 'text-subtle'}`}>
                                            {price === null || price === undefined
                                                ? 'by negotiation'
                                                : `${money(price, selected?.currency)}/seat`}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </Field>

                    {/* ── Status ───────────────────────────────────────────── */}
                    <Field
                        label="Subscription status"
                        htmlFor="sub-status"
                        hint={SUB_STATUS_HELP[form.data.status] || 'Use the Trial controls below to put a company back on trial.'}
                        error={form.errors.status}
                    >
                        <Select id="sub-status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            {!statuses.includes(sub.status) && (
                                <option value={sub.status}>{SUB_STATUS_LABEL[sub.status] || sub.status} (current)</option>
                            )}
                            {statuses.map((s) => (
                                <option key={s} value={s}>
                                    {SUB_STATUS_LABEL[s] || s}{s === sub.status ? ' (current)' : ''}
                                </option>
                            ))}
                        </Select>
                    </Field>
                </div>

                {/* ── The before-you-apply preview ─────────────────────────── */}
                {planMoved && preview_ && (
                    <div className="rounded-lg border border-line bg-surface-2">
                        <div className="flex items-center gap-2 border-b border-line px-4 py-2.5 text-sm font-semibold text-body">
                            <Repeat className="h-4 w-4 text-info" aria-hidden="true" />
                            What changes when you apply {sub.plan_name} → {preview_.plan_name}
                        </div>

                        <div className="grid gap-x-6 px-4 py-3 sm:grid-cols-2">
                            <DeltaRow
                                label="User seats"
                                from={preview_.seat_limit_now}
                                to={preview_.seat_limit}
                                unit="seats"
                                tightens={preview_.seat_limit !== null && (preview_.seat_limit_now === null || preview_.seat_limit < preview_.seat_limit_now)}
                            />
                            <DeltaRow
                                label="Screenshot retention"
                                from={preview_.retention_days_now}
                                to={preview_.retention_days}
                                unit="days"
                                tightens={preview_.retention_days !== null && (preview_.retention_days_now === null || preview_.retention_days < preview_.retention_days_now)}
                            />
                            {(preview_.limits || [])
                                .filter((l) => l.key !== 'max_users' && l.key !== 'max_retention_days')
                                .map((l) => (
                                    <DeltaRow key={l.key} label={l.label} from={l.from} to={l.to} unit={l.unit} tightens={l.tightens} />
                                ))}
                        </div>

                        {preview_.losses?.length > 0 && (
                            <div className="border-t border-line px-4 py-3">
                                <div className="flex items-center gap-1.5 text-sm font-semibold text-danger">
                                    <TrendingDown className="h-4 w-4" aria-hidden="true" />
                                    {preview_.losses.length === 1
                                        ? '1 capability stops working immediately'
                                        : `${preview_.losses.length} capabilities stop working immediately`}
                                </div>
                                <p className="mt-1 text-xs text-muted">
                                    These are on today and off on {preview_.plan_name}. If the customer is using one, tell them
                                    first — or grant it back as a per-company override on the Access tab, which outranks the plan.
                                </p>
                                <ul className="mt-2 space-y-1">
                                    {preview_.losses.map((l) => (
                                        <li key={l.key} className="flex items-start gap-2 rounded-md bg-danger-soft px-2.5 py-1.5 text-sm">
                                            <X className="mt-0.5 h-3.5 w-3.5 shrink-0 text-danger" aria-hidden="true" />
                                            <span>
                                                <span className="font-medium text-body">{l.label}</span>
                                                {l.help && <span className="block text-xs text-muted">{l.help}</span>}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {preview_.gains?.length > 0 && (
                            <div className="border-t border-line px-4 py-3">
                                <div className="text-sm font-semibold text-success">
                                    {preview_.gains.length} {preview_.gains.length === 1 ? 'capability' : 'capabilities'} unlocked
                                </div>
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {preview_.gains.map((g) => (
                                        <Badge key={g.key} tone="success">{g.label}</Badge>
                                    ))}
                                </div>
                            </div>
                        )}

                        {preview_.losses?.length === 0 && preview_.gains?.length === 0 && (
                            <div className="border-t border-line px-4 py-3 text-sm text-muted">
                                No capability is gained or lost — only the limits above and the price change.
                            </div>
                        )}

                        {/* ── Seat safety ──────────────────────────────────── */}
                        {overage && (
                            <div className="border-t border-line px-4 py-3">
                                <div className="flex items-start gap-2 rounded-md bg-warning-soft p-3">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warning" aria-hidden="true" />
                                    <div className="space-y-2 text-sm">
                                        <p className="font-semibold text-body">
                                            {organization.name} has {overage.users} active user{overage.users === 1 ? '' : 's'} but{' '}
                                            {preview_.plan_name} allows {overage.limit}.
                                        </p>
                                        <p className="text-muted">
                                            Nobody is signed out and no user is deactivated — the ceiling only applies when a seat is
                                            claimed. They keep all {overage.users}, and simply cannot add or reactivate anyone until they
                                            are back under {overage.limit}. Your acknowledgement is stored in the activity log.
                                        </p>
                                        <label className="flex cursor-pointer items-center gap-2 font-medium text-body">
                                            <input
                                                type="checkbox"
                                                className="h-4 w-4 rounded border-line text-accent focus:ring-accent"
                                                checked={form.data.acknowledge_seat_overage}
                                                onChange={(e) => form.setData('acknowledge_seat_overage', e.target.checked)}
                                            />
                                            I understand — apply the downgrade anyway
                                        </label>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                <Field
                    label="Why are you changing this?"
                    htmlFor="sub-reason"
                    hint="Stored on the activity-log entry, so the History tab explains itself later."
                    error={form.errors.reason}
                >
                    <Input id="sub-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="e.g. Downgraded at renewal, agreed with Mara on 3 Aug" />
                </Field>

                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        variant="primary"
                        loading={form.processing}
                        disabled={form.processing || !dirty || blocked}
                        onClick={() => setConfirming(true)}
                    >
                        {form.recentlySuccessful ? 'Applied' : form.processing ? 'Applying…' : 'Apply changes'}
                    </Button>
                    {dirty && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => form.setData({
                                plan: sub.plan_key || '',
                                billing_cycle: sub.billing_cycle || 'monthly',
                                status: sub.status,
                                acknowledge_seat_overage: false,
                                reason: form.data.reason,
                            })}
                        >
                            Reset
                        </Button>
                    )}
                    {blocked && <span className="text-sm text-warning">Acknowledge the seat overage to continue.</span>}
                    {!dirty && <span className="text-sm text-subtle">Nothing selected has changed yet.</span>}
                </div>
            </div>

            <ConfirmModal
                open={confirming}
                title="Apply this subscription change?"
                description={
                    `${organization.name}: ${summary}. `
                    + (planMoved && preview_?.losses?.length
                        ? (preview_.losses.length === 1
                            ? '1 capability stops working immediately. '
                            : `${preview_.losses.length} capabilities stop working immediately. `)
                        : '')
                    + 'Entitlements re-resolve on their very next request, and this is recorded in the activity log.'
                }
                confirmLabel="Apply"
                tone={planMoved && preview_?.losses?.length ? 'danger' : 'primary'}
                onConfirm={apply}
                onClose={() => setConfirming(false)}
            />
        </Card>
    );
}

// ─── Billing: record a bank-transfer payment ────────────────────────────────
function PaymentForm({ org, sub, plans, preview }) {
    const form = useForm({ amount: '', currency: 'USD', months: 1, plan: sub?.plan_key || '', reference: '', reason: '' });

    const record = (e) => {
        e.preventDefault();
        if (preview) return;
        form.post(rt('platform.payment', org.id), {
            preserveScroll: true,
            onSuccess: () => form.setData({ ...form.data, amount: '', reference: '' }),
        });
    };

    return (
        <Card title="Record a bank-transfer payment" description="Log an off-platform payment and extend the paid period.">
            <form onSubmit={record}>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <Field label="Amount" htmlFor="pay-amount" error={form.errors.amount}>
                        <Input id="pay-amount" type="number" step="0.01" min="0" placeholder="80.00"
                            value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                    </Field>
                    <Field label="Currency" htmlFor="pay-currency">
                        <Input id="pay-currency" maxLength={3} value={form.data.currency}
                            onChange={(e) => form.setData('currency', e.target.value.toUpperCase())} />
                    </Field>
                    <Field label="Months" htmlFor="pay-months">
                        <Input id="pay-months" type="number" min="1" max="36" value={form.data.months}
                            onChange={(e) => form.setData('months', Number(e.target.value))} />
                    </Field>
                    <Field label="Plan" htmlFor="pay-plan">
                        <Select id="pay-plan" value={form.data.plan} onChange={(e) => form.setData('plan', e.target.value)}>
                            <option value="">Keep current</option>
                            {plans.map((p) => <option key={p.key} value={p.key}>{p.name}</option>)}
                        </Select>
                    </Field>
                    <Field label="Reference" htmlFor="pay-ref">
                        <Input id="pay-ref" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                    </Field>
                </div>
                <div className="mt-4">
                    <Button type="submit" variant="primary" loading={form.processing} disabled={form.processing || !form.data.amount}>
                        {form.processing ? 'Recording…' : 'Record payment & activate'}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

// ─── Access tab ─────────────────────────────────────────────────────────────
// A per-company override is one of four things:
//   inherit   (blank)          fall through to add-ons, then the plan
//   grant     (on / a number)  give them more than their plan
//   revoke    (off / a lower number)  take something the plan grants away
//   unlimited (the sentinel)   lift a ceiling entirely — distinct from blank
// and it may carry a REASON and an EXPIRY, so a sales trial switches itself off.
function AccessTab({ organization, plans, currentPlanKey, entitlements, canManage, preview }) {
    const { registry, plan_defaults: planDefaults, overrides, layers, effective } = entitlements;
    const groups = useMemo(() => groupRegistry(registry), [registry]);

    const initial = {};
    Object.keys(registry).forEach((key) => {
        const stored = overrides?.[key] || {};
        const raw = stored.value;
        initial[key] = {
            value: raw === null || raw === undefined ? '' : raw === true ? '1' : raw === false ? '0' : String(raw),
            expires_at: stored.expires_at ? String(stored.expires_at).slice(0, 10) : '',
            reason: stored.reason || '',
        };
    });

    // No `plan` field: the plan switcher lives on the Overview tab and is the
    // single place a company is moved between plans.
    const form = useForm({
        overrides: initial,
        custom_price_cents: entitlements.custom_price_cents ?? '',
        discount_percent: organization.discount_percent ?? '',
        reason: '',
    });

    const setOv = (key, patch) =>
        form.setData('overrides', { ...form.data.overrides, [key]: { ...form.data.overrides[key], ...patch } });

    const save = (e) => {
        e.preventDefault();
        if (preview) return;
        form
            .transform((d) => ({
                ...d,
                custom_price_cents: d.custom_price_cents === '' ? null : Number(d.custom_price_cents),
                discount_percent: d.discount_percent === '' ? null : Number(d.discount_percent),
                reason: d.reason || null,
            }))
            .put(rt('platform.tenant.update', organization.id), { preserveScroll: true });
    };

    const overrideCount = Object.values(form.data.overrides).filter((o) => o.value !== '').length;

    return (
        <form onSubmit={save} className="space-y-4">
            <Card
                title="Commercials"
                description="A negotiated price for this one customer. Blank = inherit the plan's price."
            >
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="Custom price (cents / month)" htmlFor="tc-price" error={form.errors.custom_price_cents}>
                        <Input id="tc-price" type="number" min="0" placeholder="inherit plan price" disabled={!canManage}
                            value={form.data.custom_price_cents} onChange={(e) => form.setData('custom_price_cents', e.target.value)} />
                    </Field>
                    <Field label="Discount (%)" htmlFor="tc-discount" error={form.errors.discount_percent}>
                        <Input id="tc-discount" type="number" min="0" max="100" placeholder="0" disabled={!canManage}
                            value={form.data.discount_percent} onChange={(e) => form.setData('discount_percent', e.target.value)} />
                    </Field>
                    {/* The plan lives on the Overview tab's subscription panel —
                        ONE place to move a company between plans, with the
                        gains/losses preview and the seat guard. Duplicating the
                        control here is how two switchers drift apart. */}
                    <div className="text-sm">
                        <div className="mb-1.5 font-medium text-body">Plan</div>
                        <div className="rounded-md border border-line bg-surface-2 px-3 py-2 text-muted">
                            {plans.find((p) => p.key === currentPlanKey)?.name || '—'}
                            <span className="block text-xs text-subtle">
                                Change it on the Overview tab, where you can see what the company gains and loses first.
                            </span>
                        </div>
                    </div>
                </div>
            </Card>

            <Card
                title="Effective access"
                description="What this workspace can actually do right now, and which layer decided it. The server enforces exactly these values."
                actions={<Badge tone={overrideCount ? 'warning' : 'neutral'}>{overrideCount} override{overrideCount === 1 ? '' : 's'}</Badge>}
                padded={false}
            >
                <div className="flex items-start gap-2 border-b border-line px-5 py-3 text-xs text-muted">
                    <Info className="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" />
                    <span>
                        Resolution order: <span className="font-medium text-body">override → add-on → plan → default (deny)</span>.
                        Leaving an override blank means “inherit”. Setting a flag to <span className="font-medium text-body">Off</span> is
                        a deliberate revoke and beats both the add-on and the plan. Type “{UNLIMITED}” on a limit to remove the ceiling.
                    </span>
                </div>

                {groups.map(({ group, entries }) => (
                    <div key={group}>
                        <div className="border-b border-line bg-surface-2 px-5 py-2 text-[11px] font-semibold uppercase tracking-wider text-subtle">
                            {group}
                        </div>
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>Feature</Th>
                                    <Th>Plan</Th>
                                    <Th>Add-ons</Th>
                                    <Th>Override for this company</Th>
                                    <Th>Effective</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {entries.map((entry) => {
                                    const key = entry.key;
                                    const layer = layers?.[key] || {};
                                    const eff = effective?.[key] || {};
                                    const src = SOURCE_META[eff.source] || SOURCE_META.default;
                                    const ov = form.data.overrides[key] || { value: '', expires_at: '', reason: '' };
                                    const stored = overrides?.[key] || {};
                                    const hasOverride = ov.value !== '';

                                    return (
                                        <Tr key={key}>
                                            <Td>
                                                <div className="font-medium text-body" title={entry.help || ''}>{entry.label}</div>
                                                <code className="text-xs text-subtle">{key}</code>
                                            </Td>
                                            <Td className="text-muted">
                                                {layer.has_plan ? formatValue(entry, layer.plan) : <span className="text-subtle">No plan</span>}
                                            </Td>
                                            <Td className="text-muted">
                                                {layer.addons?.length ? (
                                                    <div className="space-y-0.5">
                                                        {layer.addons.map((a) => (
                                                            <div key={a.key} className="whitespace-nowrap text-xs">
                                                                <span className="text-body">{formatValue(entry, a.value)}</span>
                                                                <span className="text-subtle"> · {a.name}</span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                ) : <span className="text-subtle">—</span>}
                                            </Td>
                                            <Td>
                                                <div className="space-y-1.5">
                                                    {entry.kind === 'flag' ? (
                                                        <Select
                                                            className="w-40"
                                                            disabled={!canManage}
                                                            value={ov.value}
                                                            aria-label={`${entry.label} override`}
                                                            onChange={(e) => setOv(key, { value: e.target.value })}
                                                        >
                                                            <option value="">Inherit ({formatValue(entry, planDefaults?.[key])})</option>
                                                            <option value="1">Grant — On</option>
                                                            <option value="0">Revoke — Off</option>
                                                        </Select>
                                                    ) : (
                                                        <Input
                                                            className="w-40 text-right"
                                                            type="text"
                                                            inputMode="numeric"
                                                            disabled={!canManage}
                                                            value={ov.value}
                                                            aria-label={`${entry.label} override`}
                                                            placeholder={`Inherit (${formatValue(entry, planDefaults?.[key])})`}
                                                            onChange={(e) => setOv(key, { value: e.target.value })}
                                                        />
                                                    )}

                                                    {hasOverride && (
                                                        <div className="space-y-1.5 rounded-md border border-line bg-surface-2 p-2">
                                                            <label className="flex items-center gap-1.5 text-[11px] text-subtle" htmlFor={`exp-${key}`}>
                                                                <Clock className="h-3 w-3" aria-hidden="true" /> Expires
                                                            </label>
                                                            <Input
                                                                id={`exp-${key}`}
                                                                type="date"
                                                                disabled={!canManage}
                                                                value={ov.expires_at}
                                                                onChange={(e) => setOv(key, { expires_at: e.target.value })}
                                                            />
                                                            <Input
                                                                type="text"
                                                                disabled={!canManage}
                                                                value={ov.reason}
                                                                placeholder="Why (e.g. sales trial)"
                                                                aria-label={`${entry.label} override reason`}
                                                                onChange={(e) => setOv(key, { reason: e.target.value })}
                                                            />
                                                            {stored.expired && (
                                                                <div className="text-[11px] text-warning">
                                                                    The saved grant has expired — it is being ignored.
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            </Td>
                                            <Td>
                                                <div className="flex flex-col items-start gap-1">
                                                    <span className="font-medium text-body">{formatValue(entry, eff.value)}</span>
                                                    <Badge tone={src.tone}>{src.label}</Badge>
                                                </div>
                                            </Td>
                                        </Tr>
                                    );
                                })}
                            </TBody>
                        </Table>
                    </div>
                ))}

                {canManage && (
                    <div className="space-y-3 border-t border-line p-5">
                        <Field label="Why are you changing this?" htmlFor="tc-reason" hint="Stored against the activity-log entry, so the History tab explains itself later.">
                            <Input id="tc-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="e.g. Renewal negotiation, 80 seats agreed" />
                        </Field>
                        <Button type="submit" variant="primary" loading={form.processing} disabled={form.processing}>
                            {form.recentlySuccessful ? 'Saved' : form.processing ? 'Saving…' : 'Save access & commercials'}
                        </Button>
                    </div>
                )}
            </Card>
        </form>
    );
}

// ─── Add-ons tab ────────────────────────────────────────────────────────────
function AddonsTab({ organization, addons, canManage, preview }) {
    const catalogue = addons?.catalogue || [];
    const attached = addons?.attached || [];
    const [granting, setGranting] = useState(false);
    const [removing, setRemoving] = useState(null);

    const form = useForm({ addon_id: '', starts_at: '', ends_at: '', reason: '' });

    const grant = (e) => {
        e.preventDefault();
        if (preview) { setGranting(false); return; }
        form.transform((d) => ({
            addon_id: Number(d.addon_id),
            starts_at: d.starts_at || null,
            ends_at: d.ends_at || null,
            reason: d.reason || null,
        })).post(rt('platform.tenant.addons.attach', organization.id), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setGranting(false); },
        });
    };

    const revoke = (addon) => {
        if (!preview) {
            router.delete(rt('platform.tenant.addons.detach', [organization.id, addon.id]), { preserveScroll: true });
        }
        setRemoving(null);
    };

    const alreadyHeld = new Set(attached.map((a) => a.id));
    const available = catalogue.filter((a) => !alreadyHeld.has(a.id));

    return (
        <div className="space-y-4">
            <Card
                title="Add-ons this company holds"
                description="An add-on contributes its grants on top of the plan. A time-boxed one switches itself off when it ends."
                actions={canManage && available.length > 0 && (
                    <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setGranting(true)}>Grant an add-on</Button>
                )}
                padded={false}
            >
                {attached.length === 0 ? (
                    <EmptyState
                        icon={<Layers className="h-5 w-5" />}
                        title="No add-ons"
                        description="This company gets exactly what its plan grants, plus any per-company override."
                    />
                ) : (
                    <Table>
                        <THead>
                            <Tr>
                                <Th>Add-on</Th>
                                <Th>Price</Th>
                                <Th>Window</Th>
                                <Th>State</Th>
                                <Th className="text-right">Actions</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {attached.map((a) => (
                                <Tr key={a.id}>
                                    <Td>
                                        <div className="font-medium text-body">{a.name}</div>
                                        <div className="flex flex-wrap gap-1 pt-0.5">
                                            {Object.keys(a.entitlements || {}).map((k) => (
                                                <Badge key={k} tone="info">{k}</Badge>
                                            ))}
                                        </div>
                                    </Td>
                                    <Td className="tabular-nums text-muted">
                                        {money(a.price_cents, a.currency)}<span className="text-xs"> /seat/mo</span>
                                    </Td>
                                    <Td className="text-muted tabular-nums">
                                        {a.starts_at || 'now'} → {a.ends_at || 'no end'}
                                    </Td>
                                    <Td>
                                        {a.in_force
                                            ? <Badge tone="success" dot>In force</Badge>
                                            : <Badge tone="neutral" dot>{a.archived ? 'Add-on retired' : 'Not active'}</Badge>}
                                    </Td>
                                    <Td className="text-right">
                                        {canManage && (
                                            <Button size="sm" variant="ghost" className="text-danger" icon={<Trash2 className="h-4 w-4" />} onClick={() => setRemoving(a)}>
                                                Remove
                                            </Button>
                                        )}
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            <Modal show={granting} onClose={() => setGranting(false)} maxWidth="lg">
                <form onSubmit={grant} className="p-6">
                    <h3 className="text-lg font-semibold text-body">Grant an add-on</h3>
                    <p className="mt-1 text-sm text-muted">
                        This company gets the add-on's capabilities on top of its plan — no plan change, nobody else affected.
                    </p>
                    <div className="mt-5 space-y-4">
                        <Field label="Add-on" htmlFor="ga-addon" required error={form.errors.addon_id}>
                            <Select id="ga-addon" value={form.data.addon_id} onChange={(e) => form.setData('addon_id', e.target.value)}>
                                <option value="">Choose…</option>
                                {available.map((a) => (
                                    <option key={a.id} value={a.id}>{a.name} — {money(a.price_cents, a.currency)}/seat/mo</option>
                                ))}
                            </Select>
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Starts" htmlFor="ga-start" hint="Blank = right now." error={form.errors.starts_at}>
                                <Input id="ga-start" type="date" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} />
                            </Field>
                            <Field label="Ends" htmlFor="ga-end" hint="Blank = no expiry. Set a date for a trial." error={form.errors.ends_at}>
                                <Input id="ga-end" type="date" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
                            </Field>
                        </div>
                        <Field label="Why (optional)" htmlFor="ga-reason" hint="Recorded in the activity log.">
                            <Input id="ga-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                        </Field>
                    </div>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setGranting(false)}>Cancel</Button>
                        <Button type="submit" loading={form.processing} disabled={form.processing || !form.data.addon_id}>Grant</Button>
                    </div>
                </form>
            </Modal>

            <ConfirmModal
                open={!!removing}
                title="Remove this add-on?"
                description={removing ? `${organization.name} loses everything ${removing.name} grants, immediately.` : ''}
                confirmLabel="Remove"
                tone="danger"
                onConfirm={() => revoke(removing)}
                onClose={() => setRemoving(null)}
            />
        </div>
    );
}

// ─── History tab ────────────────────────────────────────────────────────────
function HistoryTab({ organization, history, preview }) {
    const entries = history?.data || [];

    const goPage = (page) => {
        if (preview) return;
        // Partial visit: only the `history` prop is re-resolved, so paging the
        // log never re-runs the entitlement resolution for the whole page.
        router.get(rt('platform.show', organization.id), { hpage: page }, {
            only: ['history'],
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <Card
            title="Change history"
            description="Every operator action against this company — plan switches, access grants and revokes, price changes, suspensions, trials, payments and impersonation. Append-only."
            padded={false}
        >
            <div className="p-5">
                {entries.length === 0 ? (
                    <EmptyState
                        title="Nothing recorded yet"
                        description="Actions taken from this console appear here with who did them and when."
                    />
                ) : (
                    <AuditFeed entries={entries} />
                )}
            </div>

            {history?.last_page > 1 && (
                <div className="flex items-center justify-between gap-3 border-t border-line px-5 py-3 text-sm text-muted">
                    <span>Page {history.current_page} of {history.last_page} · {history.total} entries</span>
                    <div className="flex gap-2">
                        <Button size="sm" variant="secondary" disabled={history.current_page <= 1} onClick={() => goPage(history.current_page - 1)}>
                            Previous
                        </Button>
                        <Button size="sm" variant="secondary" disabled={history.current_page >= history.last_page} onClick={() => goPage(history.current_page + 1)}>
                            Next
                        </Button>
                    </div>
                </div>
            )}
        </Card>
    );
}
