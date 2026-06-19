import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowLeft } from 'lucide-react';

const money = (cents, currency) => new Intl.NumberFormat('en-US', { style: 'currency', currency: currency || 'USD' }).format((cents || 0) / 100);

export default function PlatformShow({ auth, organization, subscription, plans, payments }) {
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
        <AuthenticatedLayout user={auth.user} header={<h2 className="text-xl font-semibold text-slate-100">{organization.name}</h2>}>
            <Head title={organization.name} />
            <div className="min-h-screen bg-slate-950">
                <div className="mx-auto max-w-4xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
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
                                    : <button onClick={() => setStatus('suspend')} className="rounded-md border border-rose-500/40 px-3 py-1.5 text-xs text-rose-300 hover:bg-rose-500/10">Suspend</button>}
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
            </div>
        </AuthenticatedLayout>
    );
}
