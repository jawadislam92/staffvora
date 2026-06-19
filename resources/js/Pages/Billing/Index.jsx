import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Check, Minus, CreditCard } from 'lucide-react';

const FEATURE_LABELS = {
    monitoring: 'Screenshots & monitoring',
    app_url: 'App & URL tracking',
    ai: 'AI summaries & assistant',
    white_label: 'White-label branding',
    api: 'REST API access',
    integrations: 'Integrations',
};
const FEATURE_ORDER = ['monitoring', 'app_url', 'ai', 'white_label', 'api', 'integrations'];

const money = (cents, currency) =>
    cents === 0 ? null : new Intl.NumberFormat('en-US', { style: 'currency', currency: currency || 'USD' }).format(cents / 100);

function StatusBanner({ subscription }) {
    if (!subscription) {
        return <div className="rounded-lg border border-slate-800 bg-slate-900 px-5 py-4 text-sm text-slate-300">No subscription on file.</div>;
    }
    const { status, plan_name, on_trial, trial_ends_at, current_period_end, usable } = subscription;
    const tone = !usable ? 'border-rose-500/40 bg-rose-500/10 text-rose-200'
        : on_trial ? 'border-amber-500/40 bg-amber-500/10 text-amber-200'
        : 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200';
    const label = on_trial ? `Pro trial — ends ${trial_ends_at}`
        : status === 'active' ? `${plan_name} — active${current_period_end ? ` until ${current_period_end}` : ''}`
        : status === 'past_due' ? 'Payment past due — please renew to keep full access'
        : `${plan_name} — ${status}`;
    return <div className={`rounded-lg border px-5 py-4 text-sm font-medium ${tone}`}>{label}</div>;
}

function PlanCard({ plan, currentKey }) {
    const isCurrent = plan.key === currentKey;
    const price = money(plan.price_cents, plan.currency);
    return (
        <div className={`rounded-lg border p-5 ${isCurrent ? 'border-orange-500 bg-slate-900' : 'border-slate-800 bg-slate-900/60'}`}>
            <div className="flex items-center justify-between">
                <h3 className="font-bold text-slate-100">{plan.name}</h3>
                {isCurrent && <span className="rounded-full bg-orange-500 px-2 py-0.5 text-xs font-semibold text-slate-950">Current</span>}
            </div>
            <p className="mt-1 text-2xl font-bold text-body">
                {price ? <>{price}<span className="text-sm font-normal text-slate-400">/user·mo</span></> : <span className="text-base text-slate-300">{plan.key === 'enterprise' ? 'Contact us' : 'Free'}</span>}
            </p>
            <p className="mt-1 text-xs text-slate-400">{plan.max_users ? `Up to ${plan.max_users} users` : 'Unlimited users'}</p>
            <ul className="mt-4 space-y-1.5 text-sm">
                {FEATURE_ORDER.map((f) => {
                    const on = !!plan.features?.[f];
                    return (
                        <li key={f} className={`flex items-center gap-2 ${on ? 'text-slate-200' : 'text-slate-500'}`}>
                            {on ? <Check className="h-4 w-4 text-emerald-400" /> : <Minus className="h-4 w-4 text-slate-600" />}
                            {FEATURE_LABELS[f]}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

export default function BillingIndex({ auth, subscription, seatsUsed, seatLimit, plans, payments, bankDetails, supportEmail }) {
    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="text-xl font-semibold text-slate-100">Billing &amp; plan</h2>}>
            <Head title="Billing" />
            <div className="min-h-screen bg-slate-950">
                <div className="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                    <StatusBanner subscription={subscription} />

                    <div className="flex flex-wrap gap-4 text-sm text-slate-300">
                        <span className="rounded-lg border border-slate-800 bg-slate-900 px-4 py-2">
                            Seats: <strong className="text-slate-100">{seatsUsed}</strong>{seatLimit ? ` / ${seatLimit}` : ' (unlimited)'}
                        </span>
                    </div>

                    <div>
                        <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-400">Plans</h3>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {plans.map((p) => <PlanCard key={p.key} plan={p} currentKey={subscription?.plan_key} />)}
                        </div>
                    </div>

                    <div className="rounded-lg border border-slate-800 bg-slate-900 p-5">
                        <div className="mb-2 flex items-center gap-2 text-slate-100">
                            <CreditCard className="h-5 w-5 text-orange-400" />
                            <h3 className="font-semibold">How to upgrade or renew</h3>
                        </div>
                        <p className="text-sm leading-6 text-slate-300">{bankDetails}</p>
                        {supportEmail && (
                            <p className="mt-2 text-sm text-slate-400">Questions? <a href={`mailto:${supportEmail}`} className="font-medium text-orange-300 hover:underline">{supportEmail}</a></p>
                        )}
                    </div>

                    {payments.length > 0 && (
                        <div>
                            <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-400">Payment history</h3>
                            <div className="overflow-hidden rounded-lg border border-slate-800 bg-slate-900 divide-y divide-slate-800 text-sm">
                                {payments.map((p, i) => (
                                    <div key={i} className="flex items-center justify-between px-5 py-3">
                                        <span className="text-slate-300">{p.paid_at}{p.reference ? ` · ${p.reference}` : ''}</span>
                                        <span className="text-slate-100">{money(p.amount_cents, p.currency)}{p.period_end ? <span className="text-slate-500"> · through {p.period_end}</span> : null}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
