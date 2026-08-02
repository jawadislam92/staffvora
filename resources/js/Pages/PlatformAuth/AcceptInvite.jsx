import { Head, useForm } from '@inertiajs/react';

// Guest accept page for an operator invitation — same plain dark look as the
// operator login. Email + role come from the token; the invitee sets name + pw.
export default function AcceptInvite({ token, email, role }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '', password: '', password_confirmation: '',
    });
    const submit = (e) => { e.preventDefault(); post(`/invite/${token}`); };

    return (
        <div className="flex min-h-screen items-center justify-center bg-zinc-950 px-4">
            <Head title="Accept invitation" />
            <div className="w-full max-w-sm">
                <div className="mb-6 text-center">
                    <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-orange-500 text-xl font-black text-white">T</div>
                    <h1 className="text-lg font-bold text-white">Join Staffvora Operations</h1>
                    <p className="text-sm text-zinc-500">You're invited as <span className="font-semibold capitalize text-zinc-300">{role}</span></p>
                </div>
                <form onSubmit={submit} className="space-y-4 rounded-2xl border border-zinc-800 bg-zinc-900 p-6">
                    <div>
                        <label className="mb-1 block text-xs font-semibold text-zinc-400">Email</label>
                        <input type="email" value={email} disabled
                            className="w-full rounded-lg border-zinc-700 bg-zinc-800/60 text-sm text-zinc-400" />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-semibold text-zinc-400">Your name</label>
                        <input type="text" value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus
                            className="w-full rounded-lg border-zinc-700 bg-zinc-800 text-sm text-white focus:border-orange-500 focus:ring-orange-500" />
                        {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-semibold text-zinc-400">Password (min 12 chars)</label>
                        <input type="password" value={data.password} onChange={(e) => setData('password', e.target.value)}
                            className="w-full rounded-lg border-zinc-700 bg-zinc-800 text-sm text-white focus:border-orange-500 focus:ring-orange-500" />
                        {errors.password && <p className="mt-1 text-xs text-rose-400">{errors.password}</p>}
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-semibold text-zinc-400">Confirm password</label>
                        <input type="password" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)}
                            className="w-full rounded-lg border-zinc-700 bg-zinc-800 text-sm text-white focus:border-orange-500 focus:ring-orange-500" />
                    </div>
                    <button type="submit" disabled={processing}
                        className="w-full rounded-lg bg-orange-500 py-2.5 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:opacity-50">
                        {processing ? 'Creating account…' : 'Accept & create account'}
                    </button>
                </form>
            </div>
        </div>
    );
}
