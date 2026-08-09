import { Head, Link, useForm } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

const input = 'w-full rounded-lg border-line bg-surface-2 text-sm text-body focus:border-accent focus:ring-accent';
const label = 'mb-1 block text-xs font-semibold text-muted';

// Set a new operator password from an emailed token.
export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors } = useForm({
        token: token || '',
        email: email || '',
        password: '',
        password_confirmation: '',
    });
    const submit = (e) => { e.preventDefault(); post(route('platform.password.update')); };

    return (
        <div className="flex min-h-screen items-center justify-center bg-app px-4">
            <Head title="Choose a new password" />
            <div className="w-full max-w-sm">
                <div className="mb-6 text-center">
                    <span className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-accent/10 ring-1 ring-accent/20">
                        <ApplicationLogo size="32" />
                    </span>
                    <h1 className="text-lg font-bold text-body">Choose a new password</h1>
                    <p className="text-sm text-muted">Operator portal — staff only</p>
                </div>

                <form onSubmit={submit} className="space-y-4 rounded-2xl border border-line bg-surface p-6">
                    <div>
                        <label htmlFor="rp-email" className={label}>Email</label>
                        <input id="rp-email" name="email" type="email" autoComplete="email" value={data.email} onChange={(e) => setData('email', e.target.value)}
                            className={input} aria-invalid={errors.email ? 'true' : undefined} aria-describedby={errors.email ? 'rp-email-error' : undefined} />
                        {errors.email && <p id="rp-email-error" role="alert" className="mt-1 text-xs text-danger">{errors.email}</p>}
                    </div>
                    <div>
                        <label htmlFor="rp-password" className={label}>New password (min 12 chars)</label>
                        <input id="rp-password" name="password" type="password" autoComplete="new-password" value={data.password} onChange={(e) => setData('password', e.target.value)} autoFocus
                            className={input} aria-invalid={errors.password ? 'true' : undefined} aria-describedby={errors.password ? 'rp-password-error' : undefined} />
                        {errors.password && <p id="rp-password-error" role="alert" className="mt-1 text-xs text-danger">{errors.password}</p>}
                    </div>
                    <div>
                        <label htmlFor="rp-password-confirm" className={label}>Confirm new password</label>
                        <input id="rp-password-confirm" name="password_confirmation" type="password" autoComplete="new-password" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)}
                            className={input} />
                    </div>
                    <button type="submit" disabled={processing}
                        className="w-full rounded-lg bg-accent py-2.5 text-sm font-semibold text-white transition hover:brightness-110 disabled:opacity-50">
                        {processing ? 'Saving…' : 'Reset password'}
                    </button>
                    <div className="text-center">
                        <Link href={route('platform.login')} className="text-xs font-medium text-muted hover:text-body">← Back to sign in</Link>
                    </div>
                </form>
            </div>
        </div>
    );
}
