import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowLeft, Check } from 'lucide-react';

// Group registry keys by their `group` field, preserving registry order.
function groupKeys(registry) {
    const groups = {};
    Object.entries(registry).forEach(([key, def]) => {
        (groups[def.group] ||= []).push(key);
    });
    return groups;
}

function PlanEditor({ plan, registry }) {
    const form = useForm({
        name: plan.name,
        price_cents: plan.price_cents,
        is_public: plan.is_public,
        entitlements: { ...plan.entitlements },
    });

    const setEnt = (key, value) => form.setData('entitlements', { ...form.data.entitlements, [key]: value });
    const save = (e) => {
        e.preventDefault();
        form.put(route('platform.plans.update', plan.id), { preserveScroll: true });
    };

    const groups = groupKeys(registry);

    return (
        <form onSubmit={save} className="rounded-lg border border-line bg-surface p-5">
            <div className="flex items-center justify-between gap-3">
                <input
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    className="w-40 rounded-md border-line bg-app text-base font-bold text-body"
                />
                <span className="rounded-full bg-surface-2 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-subtle">{plan.key}</span>
            </div>

            <div className="mt-3 grid grid-cols-2 gap-3">
                <label className="text-xs text-muted">Price ($/seat/mo)
                    <input
                        type="number" step="0.01" min="0"
                        value={(form.data.price_cents / 100).toString()}
                        onChange={(e) => form.setData('price_cents', Math.round(parseFloat(e.target.value || '0') * 100))}
                        className="mt-1 w-full rounded-md border-line bg-app text-sm text-body"
                    />
                </label>
                <label className="flex items-end gap-2 text-xs text-muted">
                    <input type="checkbox" checked={form.data.is_public} onChange={(e) => form.setData('is_public', e.target.checked)} className="rounded border-line bg-app text-accent" />
                    <span className="pb-2">Public (shown on pricing)</span>
                </label>
            </div>

            {Object.entries(groups).map(([group, keys]) => (
                <div key={group} className="mt-4">
                    <div className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-subtle">{group}</div>
                    <div className="space-y-1.5">
                        {keys.map((key) => {
                            const def = registry[key];
                            const value = form.data.entitlements[key];
                            return (
                                <div key={key} className="flex items-center justify-between gap-3">
                                    <span className="text-sm text-body" title={def.help}>{def.label}</span>
                                    {def.kind === 'flag' ? (
                                        <input
                                            type="checkbox"
                                            checked={!!value}
                                            onChange={(e) => setEnt(key, e.target.checked)}
                                            className="rounded border-line bg-app text-accent"
                                        />
                                    ) : (
                                        <input
                                            type="number" min="0"
                                            value={value ?? ''}
                                            placeholder={key === 'max_users' ? '∞' : '0'}
                                            onChange={(e) => setEnt(key, e.target.value === '' ? null : Number(e.target.value))}
                                            className="w-24 rounded-md border-line bg-app text-right text-sm text-body"
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            ))}

            <button
                type="submit"
                disabled={form.processing}
                className="mt-4 inline-flex items-center gap-1.5 rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50"
            >
                <Check className="h-4 w-4" />
                {form.recentlySuccessful ? 'Saved' : form.processing ? 'Saving…' : 'Save plan'}
            </button>
        </form>
    );
}

export default function Plans({ auth, plans, registry }) {
    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="text-xl font-semibold text-body">Plans &amp; pricing</h2>}>
            <Head title="Plans" />
            <div className="min-h-screen bg-app">
                <div className="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                    <Link href={route('platform.index')} className="inline-flex items-center gap-1.5 text-sm text-muted hover:text-body">
                        <ArrowLeft className="h-4 w-4" /> Platform
                    </Link>
                    <p className="text-sm text-muted">
                        Tune what each plan unlocks and its price. Changes apply to every tenant on that plan; per-tenant exceptions live on each tenant&apos;s page.
                    </p>
                    <div className="grid gap-4 lg:grid-cols-2">
                        {plans.map((plan) => (
                            <PlanEditor key={plan.id} plan={plan} registry={registry} />
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
