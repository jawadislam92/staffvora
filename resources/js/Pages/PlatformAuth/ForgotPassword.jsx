import { Head, Link, useForm, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

const input = 'w-full rounded-lg border-line bg-surface-2 text-sm text-body focus:border-accent focus:ring-accent';
const label = 'mb-1 block text-xs font-semibold text-muted';

// Operator password-reset request. The response is always neutral — it never
// reveals whether an operator account exists for the entered email.
export default function ForgotPassword() {
    const { data, setData, post, processing, errors } = useForm({ email: '' });
    const flash = usePage().props?.flash || {};
    const submit = (e) => { e.preventDefault(); post(route('platform.password.email')); };

    return (
        <div className="flex min-h-screen items-center justify-center bg-app px-4">
            <Head title="Reset operator password" />
            <div className="w-full max-w-sm">
                <div className="mb-6 text-center">
                    <span className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-accent/10 ring-1 ring-accent/20">
                        <ApplicationLogo size="32" />
                    </span>
                    <h1 className="text-lg font-bold text-body">Reset your password</h1>
                    <p className="text-sm text-muted">We'll email you a secure reset link.</p>
                </div>

                {flash.success && (
                    <div role="status" className="mb-4 rounded-lg border border-line bg-success-soft px-3 py-2 text-sm text-success">{flash.success}</div>
                )}

                <form onSubmit={submit} className="space-y-4 rounded-2xl border border-line bg-surface p-6">
                    <div>
                        <label htmlFor="fp-email" className={label}>Email</label>
                        <input id="fp-email" name="email" type="email" autoComplete="email" value={data.email} onChange={(e) => setData('email', e.target.value)} autoFocus
                            className={input} aria-invalid={errors.email ? 'true' : undefined} aria-describedby={errors.email ? 'fp-email-error' : undefined} />
                        {errors.email && <p id="fp-email-error" role="alert" className="mt-1 text-xs text-danger">{errors.email}</p>}
                    </div>
                    <button type="submit" disabled={processing}
                        className="w-full rounded-lg bg-accent py-2.5 text-sm font-semibold text-white transition hover:brightness-110 disabled:opacity-50">
                        {processing ? 'Sending…' : 'Email reset link'}
                    </button>
                    <div className="text-center">
                        <Link href={route('platform.login')} className="text-xs font-medium text-muted hover:text-body">← Back to sign in</Link>
                    </div>
                </form>
            </div>
        </div>
    );
}
