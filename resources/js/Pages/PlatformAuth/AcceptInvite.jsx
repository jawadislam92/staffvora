import { Head, useForm } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

const input = 'w-full rounded-lg border-line bg-surface-2 text-sm text-body focus:border-accent focus:ring-accent';
const label = 'mb-1 block text-xs font-semibold text-muted';

// Guest accept page for an operator invitation — tokenized to match the console.
// Email + role come from the token; the invitee sets name + password.
export default function AcceptInvite({ token, email, role }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '', password: '', password_confirmation: '',
    });
    const submit = (e) => { e.preventDefault(); post(`/invite/${token}`); };

    return (
        <div className="flex min-h-screen items-center justify-center bg-app px-4">
            <Head title="Accept invitation" />
            <div className="w-full max-w-sm">
                <div className="mb-6 text-center">
                    <span className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-accent/10 ring-1 ring-accent/20">
                        <ApplicationLogo size="32" />
                    </span>
                    <h1 className="text-lg font-bold text-body">Join Staffvora Operations</h1>
                    <p className="text-sm text-muted">You're invited as <span className="font-semibold capitalize text-body">{role}</span></p>
                </div>
                <form onSubmit={submit} className="space-y-4 rounded-2xl border border-line bg-surface p-6">
                    <div>
                        <label htmlFor="inv-email" className={label}>Email</label>
                        <input id="inv-email" name="email" type="email" autoComplete="email" value={email} disabled
                            className={input + ' opacity-70'} />
                    </div>
                    <div>
                        <label htmlFor="inv-name" className={label}>Your name</label>
                        <input id="inv-name" name="name" type="text" autoComplete="name" value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus
                            className={input} aria-invalid={errors.name ? 'true' : undefined} aria-describedby={errors.name ? 'inv-name-error' : undefined} />
                        {errors.name && <p id="inv-name-error" role="alert" className="mt-1 text-xs text-danger">{errors.name}</p>}
                    </div>
                    <div>
                        <label htmlFor="inv-password" className={label}>Password (min 12 chars)</label>
                        <input id="inv-password" name="password" type="password" autoComplete="new-password" value={data.password} onChange={(e) => setData('password', e.target.value)}
                            className={input} aria-invalid={errors.password ? 'true' : undefined} aria-describedby={errors.password ? 'inv-password-error' : undefined} />
                        {errors.password && <p id="inv-password-error" role="alert" className="mt-1 text-xs text-danger">{errors.password}</p>}
                    </div>
                    <div>
                        <label htmlFor="inv-password-confirm" className={label}>Confirm password</label>
                        <input id="inv-password-confirm" name="password_confirmation" type="password" autoComplete="new-password" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)}
                            className={input} />
                    </div>
                    <button type="submit" disabled={processing}
                        className="w-full rounded-lg bg-accent py-2.5 text-sm font-semibold text-white transition hover:brightness-110 disabled:opacity-50">
                        {processing ? 'Creating account…' : 'Accept & create account'}
                    </button>
                </form>
            </div>
        </div>
    );
}
