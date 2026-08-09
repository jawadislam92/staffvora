import { Head, Link, useForm, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

const input = 'w-full rounded-lg border-line bg-surface-2 text-sm text-body focus:border-accent focus:ring-accent';
const label = 'mb-1 block text-xs font-semibold text-muted';

// Operator portal sign-in — tokenized so it matches the console (light default,
// dark + black both work) and shares its electric-blue accent.
export default function Login() {
    const { data, setData, post, processing, errors } = useForm({ email: '', password: '', remember: false });
    const flash = usePage().props?.flash || {};
    const submit = (e) => { e.preventDefault(); post('/login'); };

    return (
        <div className="flex min-h-screen items-center justify-center bg-app px-4">
            <Head title="Operator sign in" />
            <div className="w-full max-w-sm">
                <div className="mb-6 text-center">
                    <span className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-accent/10 ring-1 ring-accent/20">
                        <ApplicationLogo size="32" />
                    </span>
                    <h1 className="text-lg font-bold text-body">Staffvora Operations</h1>
                    <p className="text-sm text-muted">Operator portal — staff only</p>
                </div>

                {flash.success && (
                    <div role="status" className="mb-4 rounded-lg border border-line bg-success-soft px-3 py-2 text-sm text-success">{flash.success}</div>
                )}

                <form onSubmit={submit} className="space-y-4 rounded-2xl border border-line bg-surface p-6">
                    <div>
                        <label htmlFor="op-email" className={label}>Email</label>
                        <input id="op-email" name="email" type="email" autoComplete="email" value={data.email} onChange={(e) => setData('email', e.target.value)} autoFocus
                            className={input} aria-invalid={errors.email ? 'true' : undefined} aria-describedby={errors.email ? 'op-email-error' : undefined} />
                        {errors.email && <p id="op-email-error" role="alert" className="mt-1 text-xs text-danger">{errors.email}</p>}
                    </div>
                    <div>
                        <div className="mb-1 flex items-center justify-between">
                            <label htmlFor="op-password" className={label + ' mb-0'}>Password</label>
                            <Link href={route('platform.password.request')} className="text-xs font-medium text-accent hover:underline">Forgot password?</Link>
                        </div>
                        <input id="op-password" name="password" type="password" autoComplete="current-password" value={data.password} onChange={(e) => setData('password', e.target.value)}
                            className={input} />
                    </div>
                    <label className="flex items-center gap-2 text-sm text-muted">
                        <input type="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)}
                            className="rounded border-line bg-surface-2 text-accent focus:ring-accent" />
                        Remember me
                    </label>
                    <button type="submit" disabled={processing}
                        className="w-full rounded-lg bg-accent py-2.5 text-sm font-semibold text-white transition hover:brightness-110 disabled:opacity-50">
                        {processing ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>
            </div>
        </div>
    );
}
