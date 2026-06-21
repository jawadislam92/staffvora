import { Head, useForm } from '@inertiajs/react';

// Operator portal sign-in. Fixed dark (zinc isn't remapped) — a deliberately
// plain, internal-tool look, distinct from the customer glass login.
export default function Login() {
    const { data, setData, post, processing, errors } = useForm({ email: '', password: '', remember: false });
    const submit = (e) => { e.preventDefault(); post('/login'); };

    return (
        <div className="flex min-h-screen items-center justify-center bg-zinc-950 px-4">
            <Head title="Operator sign in" />
            <div className="w-full max-w-sm">
                <div className="mb-6 text-center">
                    <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-orange-500 text-xl font-black text-white">T</div>
                    <h1 className="text-lg font-bold text-white">Tracksuite Operations</h1>
                    <p className="text-sm text-zinc-500">Operator portal — staff only</p>
                </div>
                <form onSubmit={submit} className="space-y-4 rounded-2xl border border-zinc-800 bg-zinc-900 p-6">
                    <div>
                        <label className="mb-1 block text-xs font-semibold text-zinc-400">Email</label>
                        <input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} autoFocus
                            className="w-full rounded-lg border-zinc-700 bg-zinc-800 text-sm text-white focus:border-orange-500 focus:ring-orange-500" />
                        {errors.email && <p className="mt-1 text-xs text-rose-400">{errors.email}</p>}
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-semibold text-zinc-400">Password</label>
                        <input type="password" value={data.password} onChange={(e) => setData('password', e.target.value)}
                            className="w-full rounded-lg border-zinc-700 bg-zinc-800 text-sm text-white focus:border-orange-500 focus:ring-orange-500" />
                    </div>
                    <label className="flex items-center gap-2 text-sm text-zinc-400">
                        <input type="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)}
                            className="rounded border-zinc-700 bg-zinc-800 text-orange-500 focus:ring-orange-500" />
                        Remember me
                    </label>
                    <button type="submit" disabled={processing}
                        className="w-full rounded-lg bg-orange-500 py-2.5 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:opacity-50">
                        {processing ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>
            </div>
        </div>
    );
}
