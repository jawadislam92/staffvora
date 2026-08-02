import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import {
    Badge, Button, Card, Checkbox, EmptyState, Field, Input, Select,
    Table, TBody, Td, Textarea, THead, Th, Toggle, Tr,
} from '@/Components/ui';
import Modal from '@/Components/Modal';
import { Archive, ArchiveRestore, Globe, Info, Plus, Save, Search, Users } from 'lucide-react';
import { centsToInput, inputToCents, money, rt } from './entitlementUi';

// ─────────────────────────────────────────────────────────────────────────────
// REGION PRICING RULES — which plans a part of the world may buy, and at what
// price. PKR-first local pricing lives here.
//
// A region rule is deliberately NOT an access-control object: it can only
// narrow, or re-price, the standard PUBLIC plan catalogue. It never grants a
// capability, because the country a visitor claims is attacker-controlled. All
// the real decisions (and the price a customer is actually charged) are made
// server-side in App\Services\RegionPricing — this page only edits the data and
// asks the server to explain what a given country resolves to.
// ─────────────────────────────────────────────────────────────────────────────

const OFFER_MODE_META = {
    all_except: {
        label: 'All public plans, except…',
        help: 'Every public plan is sold here unless you switch it off. A new plan is sold here automatically.',
    },
    only_listed: {
        label: 'Only the plans I list',
        help: 'Only the plans switched on below are sold here. A new plan is NOT sold here until you say so.',
    },
};

const parseCountries = (text) =>
    Array.from(
        new Set(
            String(text || '')
                .split(/[\s,;]+/)
                .map((c) => c.trim().toUpperCase())
                .filter((c) => /^[A-Z]{2}$/.test(c)),
        ),
    );

// ─── Editor ──────────────────────────────────────────────────────────────────

function RegionModal({ region, plans, offerModes, maxCountries, onClose, preview }) {
    const editing = !!region;

    const initialPlans = Object.fromEntries(
        plans.map((plan) => {
            const rule = (region?.plans || []).find((r) => r.plan_id === plan.id);
            return [
                plan.id,
                {
                    is_offered: rule ? !!rule.is_offered : true,
                    price: centsToInput(rule?.price_cents ?? null),
                    price_yearly: centsToInput(rule?.price_cents_yearly ?? null),
                },
            ];
        }),
    );

    const form = useForm({
        key: region?.key || '',
        name: region?.name || '',
        description: region?.description || '',
        countries: (region?.countries || []).join(', '),
        currency: region?.currency || 'USD',
        offer_mode: region?.offer_mode || 'all_except',
        priority: String(region?.priority ?? 100),
        is_active: !!region?.is_active,
        reason: '',
        plans: initialPlans,
    });

    const codes = parseCountries(form.data.countries);

    const setPlan = (planId, patch) =>
        form.setData('plans', {
            ...form.data.plans,
            [planId]: { ...form.data.plans[planId], ...patch },
        });

    const submit = (e) => {
        e.preventDefault();
        if (preview) { onClose(); return; }

        const payload = (d) => ({
            name: d.name,
            description: d.description || null,
            countries: parseCountries(d.countries),
            currency: (d.currency || 'USD').toUpperCase(),
            offer_mode: d.offer_mode,
            priority: Number.parseInt(d.priority, 10) || 0,
            is_active: !!d.is_active,
            reason: d.reason || null,
            plans: plans.map((plan) => ({
                plan_id: plan.id,
                is_offered: !!d.plans[plan.id]?.is_offered,
                price_cents: inputToCents(d.plans[plan.id]?.price),
                price_cents_yearly: inputToCents(d.plans[plan.id]?.price_yearly),
            })),
        });

        if (editing) {
            form.transform(payload).put(rt('platform.regions.update', region.id), {
                preserveScroll: true,
                onSuccess: onClose,
            });
        } else {
            form.transform((d) => ({ ...payload(d), key: d.key || null })).post(rt('platform.regions.store'), {
                preserveScroll: true,
                onSuccess: () => { form.reset(); onClose(); },
            });
        }
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="max-h-[85vh] overflow-y-auto p-6">
                <h3 className="text-lg font-semibold text-body">{editing ? `Edit “${region.name}”` : 'New region rule'}</h3>
                <p className="mt-1 text-sm text-muted">
                    A region changes <span className="font-medium text-body">which public plans are offered</span> and{' '}
                    <span className="font-medium text-body">what they cost</span> — nothing else. It can never unlock a
                    feature, and it can never surface a plan you have not published.
                </p>

                <div className="mt-5 space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" htmlFor="r-name" required error={form.errors.name}>
                            <Input id="r-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="South Asia" />
                        </Field>
                        <Field
                            label="Key"
                            htmlFor="r-key"
                            hint={editing ? 'Permanent.' : 'Leave blank to derive it from the name.'}
                            error={form.errors.key}
                        >
                            <Input id="r-key" value={form.data.key} disabled={editing} onChange={(e) => form.setData('key', e.target.value)} placeholder="auto" />
                        </Field>
                    </div>

                    <Field label="Description" htmlFor="r-desc" error={form.errors.description}>
                        <Textarea id="r-desc" rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="PKR-first local pricing, below the USD conversion." />
                    </Field>

                    <Field
                        label="Countries"
                        htmlFor="r-countries"
                        hint={`Two-letter country codes, separated by commas. Up to ${maxCountries}. Anything unrecognised is dropped.`}
                        error={form.errors.countries}
                    >
                        <Textarea id="r-countries" rows={2} value={form.data.countries} onChange={(e) => form.setData('countries', e.target.value)} placeholder="PK, IN, BD, LK, NP" />
                    </Field>

                    <div className="text-xs text-muted">
                        {codes.length === 0 ? (
                            <span className="text-warning">No valid country yet — a rule that names no country matches nobody.</span>
                        ) : (
                            <span>{codes.length} {codes.length === 1 ? 'country' : 'countries'}: {codes.join(' · ')}</span>
                        )}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Local currency" htmlFor="r-cur" hint="Used for the prices you set below." error={form.errors.currency}>
                            <Input id="r-cur" maxLength={3} value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value.toUpperCase())} />
                        </Field>
                        <Field label="Priority" htmlFor="r-prio" hint="Lower wins when two rules claim a country." error={form.errors.priority}>
                            <Input id="r-prio" type="number" min="0" max="10000" value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)} />
                        </Field>
                        <Field label="Offer mode" htmlFor="r-mode" hint={OFFER_MODE_META[form.data.offer_mode]?.help}>
                            <Select id="r-mode" value={form.data.offer_mode} onChange={(e) => form.setData('offer_mode', e.target.value)}>
                                {offerModes.map((m) => <option key={m} value={m}>{OFFER_MODE_META[m]?.label || m}</option>)}
                            </Select>
                        </Field>
                    </div>

                    <div className="flex items-center justify-between gap-3 rounded-lg border border-line bg-surface-2 px-4 py-3">
                        <label htmlFor="r-active" className="cursor-pointer text-sm text-body">
                            Rule is live
                            <span className="ml-1.5 text-xs text-subtle">
                                Off = the rule exists but its countries see the standard catalogue.
                            </span>
                        </label>
                        <Toggle id="r-active" checked={form.data.is_active} onChange={(next) => form.setData('is_active', next)} />
                    </div>

                    <div>
                        <div className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-subtle">
                            Plans in this region
                        </div>
                        <div className="overflow-x-auto rounded-lg border border-line">
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>Plan</Th>
                                        <Th className="text-center">Offered</Th>
                                        <Th className="text-right">Local / seat / mo</Th>
                                        <Th className="text-right">Local / seat / yr</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {plans.map((plan) => {
                                        const row = form.data.plans[plan.id] || {};
                                        return (
                                            <Tr key={plan.id}>
                                                <Td>
                                                    <div className="font-medium text-body">{plan.name}</div>
                                                    <div className="text-xs text-subtle">
                                                        list {money(plan.price_cents, plan.currency)} /mo
                                                    </div>
                                                </Td>
                                                <Td className="text-center">
                                                    <Checkbox
                                                        id={`r-off-${plan.id}`}
                                                        checked={!!row.is_offered}
                                                        onChange={(e) => setPlan(plan.id, { is_offered: e.target.checked })}
                                                    />
                                                </Td>
                                                <Td className="text-right">
                                                    <Input
                                                        type="number" step="0.01" min="0"
                                                        className="w-32 text-right"
                                                        placeholder="list price"
                                                        disabled={!row.is_offered}
                                                        value={row.price ?? ''}
                                                        onChange={(e) => setPlan(plan.id, { price: e.target.value })}
                                                    />
                                                </Td>
                                                <Td className="text-right">
                                                    <Input
                                                        type="number" step="0.01" min="0"
                                                        className="w-32 text-right"
                                                        placeholder="list price"
                                                        disabled={!row.is_offered}
                                                        value={row.price_yearly ?? ''}
                                                        onChange={(e) => setPlan(plan.id, { price_yearly: e.target.value })}
                                                    />
                                                </Td>
                                            </Tr>
                                        );
                                    })}
                                </TBody>
                            </Table>
                        </div>
                        <div className="mt-2 flex items-start gap-2 rounded-lg border border-line bg-surface-2 px-4 py-3 text-xs text-muted">
                            <Info className="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" />
                            <span>
                                Leave a price blank to charge the plan's own list price in its own currency. Type{' '}
                                <span className="font-medium text-body">0</span> only if the plan is genuinely free here —
                                blank and 0 are not the same thing.
                            </span>
                        </div>
                    </div>

                    <Field label="Why (optional)" htmlFor="r-reason" hint="Recorded in the activity log.">
                        <Input id="r-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" loading={form.processing} disabled={form.processing || !form.data.name} icon={<Save className="h-4 w-4" />}>
                        {editing ? 'Save region' : 'Create region'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

// ─── "Why does this visitor see these prices?" ───────────────────────────────

function Simulator({ sample, preview }) {
    const [country, setCountry] = useState(sample?.country || '');
    const [result, setResult] = useState(sample || null);
    const [busy, setBusy] = useState(false);

    useEffect(() => { setResult(sample || null); }, [sample]);

    const run = async () => {
        if (preview) return;
        const url = rt('platform.regions.resolve');
        if (url === '#') return;

        setBusy(true);
        try {
            const res = await fetch(`${url}?country=${encodeURIComponent(country.trim().toUpperCase())}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (res.ok) setResult(await res.json());
        } catch {
            /* leave the previous answer on screen rather than blanking it */
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card
            title="What does a visitor see?"
            description="Resolved by the same server code that prices signup and checkout — a preview here can never disagree with what a customer is charged."
        >
            <div className="space-y-4">
                <div className="flex flex-wrap items-end gap-3">
                    <div className="w-40">
                        <Field label="Country code" htmlFor="sim-country">
                            <Input
                                id="sim-country"
                                maxLength={2}
                                placeholder="PK"
                                value={country}
                                onChange={(e) => setCountry(e.target.value.toUpperCase())}
                                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); run(); } }}
                            />
                        </Field>
                    </div>
                    <Button onClick={run} loading={busy} disabled={busy} icon={<Search className="h-4 w-4" />}>
                        Resolve
                    </Button>
                </div>

                {result && (
                    <>
                        <div className={`rounded-lg border px-4 py-3 text-sm ${result.fell_back ? 'border-warning/40 bg-warning-soft text-warning' : 'border-line bg-surface-2 text-muted'}`}>
                            {result.reason}
                        </div>

                        <div className="overflow-x-auto">
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>Plan</Th>
                                        <Th className="text-right">/ seat / mo</Th>
                                        <Th className="text-right">/ seat / yr</Th>
                                        <Th>Price from</Th>
                                    </Tr>
                                </THead>
                                <TBody>
                                    {(result.plans || []).map((plan) => (
                                        <Tr key={plan.plan_id}>
                                            <Td className="font-medium text-body">{plan.name}</Td>
                                            <Td className="text-right">{money(plan.price_cents, plan.currency)}</Td>
                                            <Td className="text-right">
                                                {plan.price_cents_yearly === null || plan.price_cents_yearly === undefined
                                                    ? <span className="text-muted">negotiated</span>
                                                    : money(plan.price_cents_yearly, plan.currency)}
                                            </Td>
                                            <Td>
                                                <Badge tone={plan.price_source === 'region' ? 'info' : 'neutral'}>
                                                    {plan.price_source === 'region' ? 'Region price' : 'List price'}
                                                </Badge>
                                            </Td>
                                        </Tr>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                    </>
                )}
            </div>
        </Card>
    );
}

// ─── Offline preview fixture ─────────────────────────────────────────────────

const DEV_PLANS = [
    { id: 1, key: 'free', name: 'Free', price_cents: 0, price_cents_yearly: 0, currency: 'USD' },
    { id: 2, key: 'starter', name: 'Starter', price_cents: 500, price_cents_yearly: 5000, currency: 'USD' },
    { id: 3, key: 'pro', name: 'Growth', price_cents: 900, price_cents_yearly: 9000, currency: 'USD' },
    { id: 4, key: 'enterprise', name: 'Enterprise', price_cents: 1800, price_cents_yearly: null, currency: 'USD' },
];

const DEV_REGIONS = [
    {
        id: 1, key: 'pakistan', name: 'Pakistan',
        description: 'PKR-first local pricing, deliberately below the USD conversion.',
        countries: ['PK'], currency: 'PKR', offer_mode: 'all_except', priority: 10,
        is_active: true, archived_at: null, tenants: 14,
        plans: [
            { plan_id: 1, is_offered: true, price_cents: 0, price_cents_yearly: 0 },
            { plan_id: 2, is_offered: true, price_cents: 90000, price_cents_yearly: 900000 },
            { plan_id: 3, is_offered: true, price_cents: 160000, price_cents_yearly: 1600000 },
            { plan_id: 4, is_offered: true, price_cents: 320000, price_cents_yearly: null },
        ],
    },
    {
        id: 2, key: 'south_asia', name: 'South Asia',
        description: 'Regional pricing for neighbouring markets.',
        countries: ['BD', 'IN', 'LK', 'NP', 'PK'], currency: 'USD', offer_mode: 'only_listed', priority: 50,
        is_active: false, archived_at: null, tenants: 3,
        plans: [
            { plan_id: 2, is_offered: true, price_cents: 300, price_cents_yearly: 3000 },
            { plan_id: 3, is_offered: true, price_cents: 600, price_cents_yearly: 6000 },
        ],
    },
];

const DEV_SAMPLE = {
    country: 'PK',
    region: { id: 1, key: 'pakistan', name: 'Pakistan', currency: 'PKR', offer_mode: 'all_except', priority: 10 },
    fell_back: false,
    reason: 'Region “Pakistan” (rule “pakistan”, priority 10) matched PK: 4 of 4 public plans offered, priced in PKR.',
    plans: [
        { plan_id: 1, key: 'free', name: 'Free', price_cents: 0, price_cents_yearly: 0, currency: 'PKR', price_source: 'region' },
        { plan_id: 2, key: 'starter', name: 'Starter', price_cents: 90000, price_cents_yearly: 900000, currency: 'PKR', price_source: 'region' },
        { plan_id: 3, key: 'pro', name: 'Growth', price_cents: 160000, price_cents_yearly: 1600000, currency: 'PKR', price_source: 'region' },
        { plan_id: 4, key: 'enterprise', name: 'Enterprise', price_cents: 320000, price_cents_yearly: null, currency: 'PKR', price_source: 'region' },
    ],
};

// ─── Page ────────────────────────────────────────────────────────────────────

export default function Regions({ regions, plans, offerModes, maxCountries, sample, preview = false }) {
    const list = preview ? DEV_REGIONS : (regions || []);
    const planList = preview ? DEV_PLANS : (plans || []);
    const modes = (preview ? Object.keys(OFFER_MODE_META) : offerModes) || Object.keys(OFFER_MODE_META);
    const limit = maxCountries || 250;

    const [editing, setEditing] = useState(null); // region | 'new' | null

    const planById = useMemo(
        () => Object.fromEntries(planList.map((p) => [p.id, p])),
        [planList],
    );

    const toggleArchive = (region) => {
        if (preview) return;
        const name = region.archived_at ? 'platform.regions.restore' : 'platform.regions.archive';
        router.post(rt(name, region.id), {}, { preserveScroll: true });
    };

    const statusOf = (region) => {
        if (region.archived_at) return { tone: 'danger', label: 'Archived' };
        if (!region.is_active) return { tone: 'neutral', label: 'Inactive' };
        return { tone: 'success', label: 'Live' };
    };

    return (
        <PlatformLayout
            title="Regions"
            header={
                <PlatformPageHeader
                    title="Regions & local pricing"
                    description="Decide which public plans a part of the world may buy, and what they cost there. A region never unlocks a feature — it only narrows visibility and changes price."
                    actions={
                        <>
                            <Button as={Link} href={rt('platform.plans')} variant="ghost" size="sm">Plans</Button>
                            <Button as={Link} href={rt('platform.addons')} variant="ghost" size="sm">Add-ons</Button>
                            <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setEditing('new')}>
                                New region
                            </Button>
                        </>
                    }
                />
            }
        >
            <Head title="Regions" />

            <Simulator sample={preview ? DEV_SAMPLE : sample} preview={preview} />

            {list.length === 0 ? (
                <Card>
                    <EmptyState
                        title="No region rules yet"
                        description="Every visitor sees the standard public catalogue at its list prices. Add a region to sell a local price list — or to stop selling a plan somewhere."
                        action={<Button onClick={() => setEditing('new')}>New region</Button>}
                    />
                </Card>
            ) : (
                <div className="grid items-start gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    {list.map((region) => {
                        const status = statusOf(region);
                        const rules = region.plans || [];
                        const shown = (region.countries || []).slice(0, 12);
                        const extra = (region.countries || []).length - shown.length;

                        return (
                            <Card
                                key={region.id}
                                className={region.archived_at ? 'opacity-70' : ''}
                                title={region.name}
                                description={region.description}
                                actions={<Badge tone={status.tone}>{status.label}</Badge>}
                            >
                                <div className="space-y-4">
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
                                        <Badge tone="neutral" className="gap-1">
                                            <Globe className="h-3 w-3" /> {(region.countries || []).length} {(region.countries || []).length === 1 ? 'country' : 'countries'}
                                        </Badge>
                                        <Badge tone="neutral" className="gap-1">
                                            <Users className="h-3 w-3" /> {region.tenants ?? 0} {region.tenants === 1 ? 'company' : 'companies'}
                                        </Badge>
                                        <Badge tone="accent">{region.currency}</Badge>
                                        <span className="text-subtle">priority {region.priority}</span>
                                        <code className="text-subtle">{region.key}</code>
                                    </div>

                                    <div className="flex flex-wrap gap-1">
                                        {shown.length === 0 ? (
                                            <span className="text-sm text-warning">No country — this rule matches nobody.</span>
                                        ) : (
                                            <>
                                                {shown.map((cc) => (
                                                    <span key={cc} className="rounded border border-line bg-surface-2 px-1.5 py-0.5 text-[11px] font-medium text-body">
                                                        {cc}
                                                    </span>
                                                ))}
                                                {extra > 0 && <span className="px-1 py-0.5 text-[11px] text-subtle">+{extra} more</span>}
                                            </>
                                        )}
                                    </div>

                                    <div>
                                        <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-subtle">
                                            {OFFER_MODE_META[region.offer_mode]?.label || region.offer_mode}
                                        </div>
                                        {rules.length === 0 ? (
                                            <p className="text-sm text-muted">
                                                {region.offer_mode === 'only_listed'
                                                    ? 'No plan listed — this rule would offer nothing, so its countries fall back to the public catalogue.'
                                                    : 'No exceptions — the full public catalogue at list prices.'}
                                            </p>
                                        ) : (
                                            <ul className="space-y-1 text-sm">
                                                {rules.map((rule) => {
                                                    const plan = planById[rule.plan_id];
                                                    return (
                                                        <li key={rule.plan_id} className="flex items-center justify-between gap-3">
                                                            <span className={rule.is_offered ? 'text-body' : 'text-subtle line-through'}>
                                                                {plan?.name || `Plan #${rule.plan_id}`}
                                                            </span>
                                                            <span className="text-muted">
                                                                {!rule.is_offered
                                                                    ? 'Not sold here'
                                                                    : rule.price_cents === null || rule.price_cents === undefined
                                                                        ? 'List price'
                                                                        : `${money(rule.price_cents, region.currency)} /mo`}
                                                            </span>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        )}
                                    </div>

                                    <div className="flex flex-wrap gap-2 pt-1">
                                        <Button size="sm" onClick={() => setEditing(region)}>Edit</Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className={region.archived_at ? '' : 'text-danger'}
                                            icon={region.archived_at ? <ArchiveRestore className="h-4 w-4" /> : <Archive className="h-4 w-4" />}
                                            onClick={() => toggleArchive(region)}
                                        >
                                            {region.archived_at ? 'Restore' : 'Archive'}
                                        </Button>
                                    </div>
                                </div>
                            </Card>
                        );
                    })}
                </div>
            )}

            {editing && (
                <RegionModal
                    region={editing === 'new' ? null : editing}
                    plans={planList}
                    offerModes={modes}
                    maxCountries={limit}
                    preview={preview}
                    onClose={() => setEditing(null)}
                />
            )}
        </PlatformLayout>
    );
}
