import { Head, useForm, usePage } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';

export default function Account({ account }) {
    const flash = usePage().props.flash || {};
    const form = useForm({
        name: account.name || '',
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const submit = (e) => {
        e.preventDefault();
        form.put(route('platform.account.update'), {
            preserveScroll: true,
            onSuccess: () => form.reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <PlatformLayout title="Account" header={<h2 className="text-xl font-semibold text-white">Your account</h2>}>
            <Head title="Account" />
            <div className="mx-auto max-w-lg space-y-4">
                <div className="rounded-xl border border-zinc-800 bg-zinc-900 p-5">
                    <div className="mb-4 flex items-center justify-between text-sm">
                        <span className="text-zinc-400">{account.email}</span>
                        <span className="rounded-full bg-zinc-800 px-2 py-0.5 text-xs capitalize text-zinc-300">{account.role}</span>
                    </div>

                    {flash.success && <div className="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-300">{flash.success}</div>}

                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="mb-1 block text-xs font-semibold text-zinc-400">Name</label>
                            <input type="text" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)}
                                className="w-full rounded-lg border-zinc-700 bg-zinc-800 text-sm text-white focus:border-orange-500 focus:ring-orange-500" />
                            {form.errors.name && <p className="mt-1 text-xs text-rose-400">{form.errors.name}</p>}
                        </div>
                        <hr className="border-zinc-800" />
                        <p className="text-xs text-zinc-500">Change your password — enter your current one to confirm.</p>
                        <div>
                            <label className="mb-1 block text-xs font-semibold text-zinc-400">Current password</label>
                            <input type="password" value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)}
                                className="w-full rounded-lg border-zinc-700 bg-zinc-800 text-sm text-white focus:border-orange-500 focus:ring-orange-500" />
                            {form.errors.current_password && <p className="mt-1 text-xs text-rose-400">{form.errors.current_password}</p>}
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-semibold text-zinc-400">New password (min 12 chars)</label>
                            <input type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)}
                                className="w-full rounded-lg border-zinc-700 bg-zinc-800 text-sm text-white focus:border-orange-500 focus:ring-orange-500" />
                            {form.errors.password && <p className="mt-1 text-xs text-rose-400">{form.errors.password}</p>}
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-semibold text-zinc-400">Confirm new password</label>
                            <input type="password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                className="w-full rounded-lg border-zinc-700 bg-zinc-800 text-sm text-white focus:border-orange-500 focus:ring-orange-500" />
                        </div>
                        <button type="submit" disabled={form.processing}
                            className="rounded-lg bg-orange-500 px-5 py-2 text-sm font-semibold text-white hover:bg-orange-600 disabled:opacity-50">
                            {form.processing ? 'Saving…' : 'Save changes'}
                        </button>
                    </form>
                </div>
            </div>
        </PlatformLayout>
    );
}
