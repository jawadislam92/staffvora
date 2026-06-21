import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';

const ROLE_TONE = {
    owner: 'bg-orange-500/15 text-orange-300',
    admin: 'bg-sky-500/15 text-sky-300',
    support: 'bg-zinc-700 text-zinc-300',
};

function CopyLink({ url }) {
    const [copied, setCopied] = useState(false);
    const copy = () => {
        navigator.clipboard?.writeText(url).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    };
    return (
        <div className="mt-1 flex items-center gap-2">
            <code className="truncate rounded bg-zinc-950 px-2 py-1 text-[11px] text-zinc-400">{url}</code>
            <button type="button" onClick={copy} className="shrink-0 rounded-md border border-zinc-700 px-2 py-1 text-xs text-zinc-300 hover:bg-zinc-800">
                {copied ? 'Copied' : 'Copy link'}
            </button>
        </div>
    );
}

export default function Staff({ staff, invitations, roles }) {
    const invite = useForm({ email: '', role: 'support' });
    const submit = (e) => {
        e.preventDefault();
        invite.post(route('platform.staff.invite'), { preserveScroll: true, onSuccess: () => invite.reset('email') });
    };
    const revoke = (u) => {
        if (confirm(`Remove ${u.name} from the operator portal?`)) router.delete(route('platform.staff.revoke', u.id), { preserveScroll: true });
    };
    const revokeInvite = (i) => {
        if (confirm(`Revoke the invitation for ${i.email}?`)) router.delete(route('platform.staff.invitation.revoke', i.id), { preserveScroll: true });
    };

    return (
        <PlatformLayout title="Staff" header={<h2 className="text-xl font-semibold text-white">Staff</h2>}>
            <Head title="Staff" />
            <div className="mx-auto max-w-3xl space-y-6">
                <p className="text-sm text-zinc-400">
                    Invite people to help run the platform. <span className="text-zinc-300">Admin</span> can manage tenants, plans, the website and developer tools;
                    <span className="text-zinc-300"> Support</span> can view tenants and resume/impersonate to help a customer. Only an Owner manages staff.
                </p>

                {/* Invite */}
                <form onSubmit={submit} className="rounded-xl border border-zinc-800 bg-zinc-900 p-5">
                    <h3 className="mb-3 text-sm font-semibold text-white">Invite a teammate</h3>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="flex-1">
                            <label className="mb-1 block text-xs font-semibold text-zinc-400">Email</label>
                            <input type="email" value={invite.data.email} onChange={(e) => invite.setData('email', e.target.value)}
                                placeholder="teammate@example.com"
                                className="w-full rounded-lg border-zinc-700 bg-zinc-800 text-sm text-white focus:border-orange-500 focus:ring-orange-500" />
                            {invite.errors.email && <p className="mt-1 text-xs text-rose-400">{invite.errors.email}</p>}
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-semibold text-zinc-400">Role</label>
                            <select value={invite.data.role} onChange={(e) => invite.setData('role', e.target.value)}
                                className="rounded-lg border-zinc-700 bg-zinc-800 text-sm capitalize text-white focus:border-orange-500 focus:ring-orange-500">
                                {roles.map((r) => <option key={r} value={r}>{r}</option>)}
                            </select>
                        </div>
                        <button type="submit" disabled={invite.processing}
                            className="rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600 disabled:opacity-50">
                            {invite.processing ? 'Inviting…' : 'Send invite'}
                        </button>
                    </div>
                </form>

                {/* Pending invitations */}
                {invitations.length > 0 && (
                    <div className="rounded-xl border border-zinc-800 bg-zinc-900 p-5">
                        <h3 className="mb-3 text-sm font-semibold text-white">Pending invitations</h3>
                        <div className="space-y-3">
                            {invitations.map((i) => (
                                <div key={i.id} className="rounded-lg border border-zinc-800 bg-zinc-950/40 p-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="min-w-0">
                                            <span className="text-sm text-zinc-200">{i.email}</span>
                                            <span className={`ml-2 rounded-full px-2 py-0.5 text-[11px] font-medium capitalize ${ROLE_TONE[i.role] || ''}`}>{i.role}</span>
                                            <div className="text-[11px] text-zinc-500">Expires {i.expires_at}</div>
                                        </div>
                                        <button type="button" onClick={() => revokeInvite(i)} className="shrink-0 text-xs text-rose-400 hover:text-rose-300">Revoke</button>
                                    </div>
                                    <CopyLink url={i.accept_url} />
                                </div>
                            ))}
                        </div>
                        <p className="mt-3 text-[11px] text-zinc-500">Share the link with the invitee — email delivery turns on automatically once SMTP is configured.</p>
                    </div>
                )}

                {/* Current staff */}
                <div className="overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="border-b border-zinc-800 text-left text-xs uppercase tracking-wide text-zinc-500">
                                <th className="px-4 py-3">Name</th>
                                <th className="px-4 py-3">Role</th>
                                <th className="px-4 py-3">Last login</th>
                                <th className="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-800">
                            {staff.map((u) => (
                                <tr key={u.id} className="text-zinc-200">
                                    <td className="px-4 py-3">
                                        <div className="font-medium text-white">{u.name}{u.is_self && <span className="ml-1 text-xs text-zinc-500">(you)</span>}</div>
                                        <div className="text-xs text-zinc-500">{u.email}</div>
                                    </td>
                                    <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${ROLE_TONE[u.role] || ''}`}>{u.role}</span></td>
                                    <td className="px-4 py-3 text-zinc-400">{u.last_login_at || '—'}</td>
                                    <td className="px-4 py-3 text-right">
                                        {!u.is_self && u.role !== 'owner'
                                            ? <button onClick={() => revoke(u)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button>
                                            : <span className="text-xs text-zinc-600">—</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </PlatformLayout>
    );
}
