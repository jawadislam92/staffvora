import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { ArrowLeft } from 'lucide-react';

const money = (cents, currency) => new Intl.NumberFormat('en-US', { style: 'currency', currency: currency || 'USD' }).format((cents || 0) / 100);

export default function PlatformShow({ organization, subscription, plans, payments, entitlements }) {
    const role = usePage().props.platformUser?.role;
    const canManage = role === 'owner' || role === 'admin';
    const form = useForm({ amount: '', currency: 'USD', months: 1, plan: subscription?.plan_key || '', reference: '' });

    const record = (e) => {
        e.preventDefault();
        form.post(route('platform.payment', organization.id), { preserveScroll: true, onSuccess: () => form.setData({ ...form.data, amount: '', reference: '' }) });
    };
    const setStatus = (action) => router.post(route(`platform.${action}`, organization.id), {}, { preserveScroll: true });
    const impersonate = () => {
        if (confirm(`Sign in as ${organization.name}'s owner?`)) router.post(route('platform.impersonate', organization.id));
    };

    return (
        <PlatformLayout title={organization.name} header={<h2 className="text-xl font-semibold text-white">{organization.name}</h2>}>
            <Head title={organization.name} />
            <div className="mx-auto max-w-4xl space-y-6">
                    <Link href={route('platform.index')} className="inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-200">
                        <ArrowLeft className="h-4 w-4" /> All tenants
                    </Link>

                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="rounded-lg border border-slate-800 bg-slate-900 p-5 text-sm">
                            <h3 className="mb-3 font-semibold text-slate-100">Organization</h3>
                            <dl className="space-y-1.5 text-slate-300">
                                <div className="flex justify-between"><dt className="text-slate-400">Slug</dt><dd>{organization.slug}</dd></div>
                                <div className="flex justify-between"><dt className="text-slate-400">Status</dt><dd className="capitalize">{organization.status}</dd></div>
                                <div className="flex justify-between"><dt className="text-slate-400">Users</dt><dd>{organization.users}</dd></div>
                                <div className="flex justify-between"><dt className="text-slate-400">Owner</dt><dd>{organization.owner?.email || '—'}</dd></div>
                                <div className="flex justify-between"><dt className="text-slate-400">Created</dt><dd>{organization.created_at}</dd></div>
                            </dl>
                            <div className="mt-4 flex gap-2">
                                <button onClick={impersonate} className="rounded-md border border-slate-700 px-3 py-1.5 text-xs text-slate-300 hover:bg-slate-800">View as owner</button>
                                {organization.status === 'suspended'
                                    ? <button onClick={() => setStatus('resume')} className="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Resume</button>
                                    : canManage && <button onClick={() => setStatus('suspend')} className="rounded-md border border-rose-500/40 px-3 py-1.5 text-xs text-rose-300 hover:bg-rose-500/10">Suspend</button>}
                            </div>
                        </div>

                        <div className="rounded-lg border border-slate-800 bg-slate-900 p-5 text-sm">
                            <h3 className="mb-3 font-semibold text-slate-100">Subscription</h3>
                            {subscription ? (
                                <dl className="space-y-1.5 text-slate-300">
                                    <div className="flex justify-between"><dt className="text-slate-400">Plan</dt><dd>{subscription.plan_name}</dd></div>
                                    <div className="flex justify-between"><dt className="text-slate-400">Status</dt><dd className="capitalize">{subscription.status}</dd></div>
                                    <div className="flex justify-between"><dt className="text-slate-400">Trial ends</dt><dd>{subscription.trial_ends_at || '—'}</dd></div>
                                    <div className="flex justify-between"><dt className="text-slate-400">Period ends</dt><dd>{subscription.current_period_end || '—'}</dd></div>
                                </dl>
                            ) : <p className="text-slate-400">No subscription.</p>}
                        </div>
                    </div>

                    {canManage && (
                    <form onSubmit={record} className="rounded-lg border border-slate-800 bg-slate-900 p-5">
                        <h3 className="mb-3 font-semibold text-slate-100">Record a bank-transfer payment</h3>
                        <div className="grid gap-3 sm:grid-cols-5">
                            <label className="text-xs text-slate-400">Amount
                                <input type="number" step="0.01" min="0" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} className="mt-1 w-full rounded-md border-slate-700 bg-slate-950 text-sm text-slate-100" placeholder="80.00" />
                            </label>
                            <label className="text-xs text-slate-400">Currency
                                <input value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value.toUpperCase())} maxLength={3} className="mt-1 w-full rounded-md border-slate-700 bg-slate-950 text-sm text-slate-100" />
                            </label>
                            <label className="text-xs text-slate-400">Months
                                <input type="number" min="1" max="36" value={form.data.months} onChange={(e) => form.setData('months', Number(e.target.value))} className="mt-1 w-full rounded-md border-slate-700 bg-slate-950 text-sm text-slate-100" />
                            </label>
                            <label className="text-xs text-slate-400">Plan
                                <select value={form.data.plan} onChange={(e) => form.setData('plan', e.target.value)} className="mt-1 w-full rounded-md border-slate-700 bg-slate-950 text-sm text-slate-100">
                                    <option value="">Keep current</option>
                                    {plans.map((p) => <option key={p.key} value={p.key}>{p.name}</option>)}
                                </select>
                            </label>
                            <label className="text-xs text-slate-400">Reference
                                <input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} className="mt-1 w-full rounded-md border-slate-700 bg-slate-950 text-sm text-slate-100" />
                            </label>
                        </div>
                        {form.errors.amount && <p className="mt-2 text-xs text-rose-400">{form.errors.amount}</p>}
                        <button type="submit" disabled={form.processing || !form.data.amount} className="mt-3 rounded-md bg-orange-500 px-4 py-2 text-sm font-bold text-slate-950 hover:bg-orange-400 disabled:opacity-50">
                            {form.processing ? 'Recording…' : 'Record payment & activate'}
                        </button>
                    </form>
                    )}

                    {canManage && entitlements && (
                        <TenantControls organization={organization} plans={plans} entitlements={entitlements} />
                    )}

                    {payments.length > 0 && (
                        <div className="overflow-hidden rounded-lg border border-slate-800 bg-slate-900 divide-y divide-slate-800 text-sm">
                            {payments.map((p, i) => (
                                <div key={i} className="flex items-center justify-between px-5 py-3">
                                    <span className="text-slate-300">{p.paid_at}{p.reference ? ` · ${p.reference}` : ''}</span>
                                    <span className="text-slate-100">{money(p.amount_cents, p.currency)}{p.period_end ? <span className="text-slate-500"> · through {p.period_end}</span> : null}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
        </PlatformLayout>
    );
}

// Human-readable plan default for an entitlement (the value shown as "Inherit").
const fmtDefault = (registry, key, planDefaults) => {
    const def = registry[key];
    const v = planDefaults[key];
    if (def.kind === 'flag') return v ? 'On' : 'Off';
    if (v === null || v === undefined) return 'Unlimited';
    return `${v}${def.unit ? ' ' + def.unit : ''}`;
};

const normOverride = (raw) =>
    raw === undefined || raw === null ? '' : raw === true ? '1' : raw === false ? '0' : String(raw);

/**
 * Per-tenant control: override any entitlement (blank = inherit the plan),
 * switch plan, and set a negotiated custom price — for this one customer only.
 */
function TenantControls({ organization, plans, entitlements }) {
    const { registry, plan_defaults: planDefaults } = entitlements;

    const initOverrides = {};
    Object.keys(registry).forEach((k) => { initOverrides[k] = normOverride(entitlements.overrides?.[k]); });

    const form = useForm({
        overrides: initOverrides,
        custom_price_cents: entitlements.custom_price_cents ?? '',
        plan: '',
    });

    const setOv = (key, value) => form.setData('overrides', { ...form.data.overrides, [key]: value });

    const save = (e) => {
        e.preventDefault();
        form
            .transform((data) => ({ ...data, custom_price_cents: data.custom_price_cents === '' ? null : Number(data.custom_price_cents) }))
            .put(route('platform.tenant.update', organization.id), { preserveScroll: true });
    };

    const groups = {};
    Object.entries(registry).forEach(([k, def]) => { (groups[def.group] ||= []).push(k); });

    return (
        <form onSubmit={save} className="rounded-lg border border-line bg-surface p-5">
            <h3 className="font-semibold text-body">Tenant controls</h3>
            <p className="mt-1 text-xs text-muted">
                Grant or limit this one customer beyond their plan — the &ldquo;contact us for a bit more&rdquo; case. Blank = inherit the plan. The server enforces every value.
            </p>

            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <label className="text-xs text-muted">Custom price (cents / month)
                    <input
                        type="number" min="0"
                        value={form.data.custom_price_cents}
                        onChange={(e) => form.setData('custom_price_cents', e.target.value)}
                        placeholder="inherit plan price"
                        className="mt-1 w-full rounded-md border-line bg-app text-sm text-body"
                    />
                </label>
                <label className="text-xs text-muted">Switch plan
                    <select value={form.data.plan} onChange={(e) => form.setData('plan', e.target.value)} className="mt-1 w-full rounded-md border-line bg-app text-sm text-body">
                        <option value="">Keep current</option>
                        {plans.map((p) => <option key={p.key} value={p.key}>{p.name}</option>)}
                    </select>
                </label>
            </div>

            {Object.entries(groups).map(([group, keys]) => (
                <div key={group} className="mt-4">
                    <div className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-subtle">{group}</div>
                    <div className="space-y-1.5">
                        {keys.map((key) => {
                            const def = registry[key];
                            return (
                                <div key={key} className="flex items-center justify-between gap-3">
                                    <span className="text-sm text-body" title={def.help}>{def.label}</span>
                                    {def.kind === 'flag' ? (
                                        <select value={form.data.overrides[key]} onChange={(e) => setOv(key, e.target.value)} className="w-40 rounded-md border-line bg-app text-sm text-body">
                                            <option value="">Inherit ({fmtDefault(registry, key, planDefaults)})</option>
                                            <option value="1">On</option>
                                            <option value="0">Off</option>
                                        </select>
                                    ) : (
                                        <input
                                            type="number" min="0"
                                            value={form.data.overrides[key]}
                                            onChange={(e) => setOv(key, e.target.value)}
                                            placeholder={`Inherit (${fmtDefault(registry, key, planDefaults)})`}
                                            className="w-40 rounded-md border-line bg-app text-right text-sm text-body"
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            ))}

            <button type="submit" disabled={form.processing} className="mt-4 rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
                {form.recentlySuccessful ? 'Saved' : form.processing ? 'Saving…' : 'Save controls'}
            </button>
        </form>
    );
}
