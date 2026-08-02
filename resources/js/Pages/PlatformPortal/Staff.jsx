import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import Modal from '@/Components/Modal';
import {
    Button,
    Card,
    Badge,
    Table,
    THead,
    TBody,
    Tr,
    Th,
    Td,
    Field,
    Input,
    Select,
    EmptyState,
} from '@/Components/ui';

// Ziggy route() helper — absent in offline preview render.
const hasRoute = () => typeof route === 'function';

// Role -> semantic Badge tone (was light-only orange/sky/zinc chips).
const ROLE_TONE = {
    owner: 'warning',
    admin: 'info',
    support: 'neutral',
};

// ---- Offline preview sample (renders with no server / no auth) --------------
// Mirrors the shape the PlatformStaffController provides.
const DEV_SAMPLE = {
    roles: ['owner', 'admin', 'support'],
    staff: [
        { id: 1, name: 'Jawad', email: 'jawad@staffvora.test', role: 'owner', last_login_at: '2 hours ago', is_self: true },
        { id: 2, name: 'Amelia Cortez', email: 'amelia@staffvora.test', role: 'admin', last_login_at: 'Yesterday', is_self: false },
        { id: 3, name: 'Ravi Shah', email: 'ravi@staffvora.test', role: 'admin', last_login_at: '3 days ago', is_self: false },
        { id: 4, name: 'Noor Ahmed', email: 'noor@staffvora.test', role: 'support', last_login_at: 'Last week', is_self: false },
        { id: 5, name: 'Tomas Berg', email: 'tomas@staffvora.test', role: 'support', last_login_at: '—', is_self: false },
    ],
    invitations: [
        { id: 11, email: 'newhire@staffvora.test', role: 'support', expires_at: 'in 6 days', accept_url: 'https://platform.staffvora.com/staff/accept/8f3a2c9d' },
    ],
};

function IconUserPlus(props) {
    return (
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>
            <path d="M15 19a5 5 0 0 0-10 0" />
            <circle cx="10" cy="8" r="3.25" />
            <path d="M18 8v5M20.5 10.5h-5" />
        </svg>
    );
}

function CopyLink({ url }) {
    const [copied, setCopied] = useState(false);
    const copy = () => {
        navigator.clipboard?.writeText(url).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    };
    return (
        <div className="mt-2 flex items-center gap-2">
            <code className="min-w-0 flex-1 truncate rounded-md bg-surface-2 px-2 py-1 text-[11px] text-muted">{url}</code>
            <Button type="button" variant="secondary" size="sm" onClick={copy} className="shrink-0">
                {copied ? 'Copied' : 'Copy link'}
            </Button>
        </div>
    );
}

function InviteModal({ show, onClose, roles, preview }) {
    const invite = useForm({ email: '', role: roles[roles.length - 1] || 'support' });

    const submit = (e) => {
        e.preventDefault();
        if (preview || !hasRoute()) return; // offline render: no network
        invite.post(route('platform.staff.invite'), {
            preserveScroll: true,
            onSuccess: () => {
                invite.reset('email');
                onClose();
            },
        });
    };

    const close = () => {
        if (invite.processing) return;
        invite.clearErrors();
        onClose();
    };

    return (
        <Modal show={show} onClose={close} maxWidth="lg">
            <form onSubmit={submit}>
                <div className="border-b border-line px-5 py-4">
                    <h3 className="text-base font-semibold text-body">Invite a teammate</h3>
                    <p className="mt-1 text-sm text-muted">Send an invitation to help run the platform.</p>
                </div>

                <div className="space-y-4 p-5">
                    <Field label="Email" htmlFor="invite-email" error={invite.errors.email}>
                        <Input
                            id="invite-email"
                            type="email"
                            autoFocus
                            value={invite.data.email}
                            onChange={(e) => invite.setData('email', e.target.value)}
                            placeholder="teammate@example.com"
                        />
                    </Field>

                    <Field label="Role" htmlFor="invite-role" error={invite.errors.role} hint="Admin manages the platform; Support can view and assist customers.">
                        <Select
                            id="invite-role"
                            className="capitalize"
                            value={invite.data.role}
                            onChange={(e) => invite.setData('role', e.target.value)}
                        >
                            {roles.map((r) => (
                                <option key={r} value={r}>{r}</option>
                            ))}
                        </Select>
                    </Field>
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-line px-5 py-4">
                    <Button type="button" variant="ghost" onClick={close} disabled={invite.processing}>
                        Cancel
                    </Button>
                    <Button type="submit" icon={<IconUserPlus />} loading={invite.processing}>
                        Send invite
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

export default function Staff({ staff, invitations, roles, preview = false }) {
    const data = preview
        ? DEV_SAMPLE
        : { staff: staff || [], invitations: invitations || [], roles: roles || [] };

    const [inviteOpen, setInviteOpen] = useState(false);

    const revoke = (u) => {
        if (preview || !hasRoute()) return;
        if (confirm(`Remove ${u.name} from the operator portal?`)) {
            router.delete(route('platform.staff.revoke', u.id), { preserveScroll: true });
        }
    };
    const revokeInvite = (i) => {
        if (preview || !hasRoute()) return;
        if (confirm(`Revoke the invitation for ${i.email}?`)) {
            router.delete(route('platform.staff.invitation.revoke', i.id), { preserveScroll: true });
        }
    };

    const header = (
        <PlatformPageHeader
            title="Staff"
            description="Invite people to help run the platform. Only an Owner manages staff."
            actions={
                <Button icon={<IconUserPlus />} onClick={() => setInviteOpen(true)}>
                    Invite teammate
                </Button>
            }
        />
    );

    return (
        <PlatformLayout title="Staff" header={header}>
            <Head title="Staff" />

            <div className="space-y-6">
                {/* Role guide */}
                <Card padded>
                    <p className="text-sm text-muted">
                        <span className="font-medium text-body">Admin</span> can manage tenants, plans, the website and developer tools.{' '}
                        <span className="font-medium text-body">Support</span> can view tenants and resume/impersonate to help a customer.{' '}
                        Only an <span className="font-medium text-body">Owner</span> manages staff.
                    </p>
                </Card>

                {/* Pending invitations */}
                {data.invitations.length > 0 && (
                    <Card title="Pending invitations" description="Share the link with the invitee — email delivery turns on automatically once SMTP is configured.">
                        <div className="space-y-3">
                            {data.invitations.map((i) => (
                                <div key={i.id} className="rounded-lg border border-line bg-surface-2/40 p-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-medium text-body">{i.email}</span>
                                                <Badge tone={ROLE_TONE[i.role] || 'neutral'} className="capitalize">{i.role}</Badge>
                                            </div>
                                            <div className="mt-0.5 text-[11px] text-subtle">Expires {i.expires_at}</div>
                                        </div>
                                        <Button type="button" variant="ghost" size="sm" onClick={() => revokeInvite(i)} className="shrink-0 text-danger hover:text-danger">
                                            Revoke
                                        </Button>
                                    </div>
                                    <CopyLink url={i.accept_url} />
                                </div>
                            ))}
                        </div>
                    </Card>
                )}

                {/* Current staff */}
                <Card title="Current staff" padded={false}>
                    {data.staff.length === 0 ? (
                        <EmptyState
                            title="No staff yet"
                            description="Invite a teammate to help run the operator portal."
                            action={
                                <Button icon={<IconUserPlus />} onClick={() => setInviteOpen(true)}>
                                    Invite teammate
                                </Button>
                            }
                        />
                    ) : (
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>Name</Th>
                                    <Th>Role</Th>
                                    <Th>Last login</Th>
                                    <Th className="text-right">Actions</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {data.staff.map((u) => (
                                    <Tr key={u.id}>
                                        <Td>
                                            <div className="font-medium text-body">
                                                {u.name}
                                                {u.is_self && <span className="ml-1 text-xs text-subtle">(you)</span>}
                                            </div>
                                            <div className="text-xs text-subtle">{u.email}</div>
                                        </Td>
                                        <Td>
                                            <Badge tone={ROLE_TONE[u.role] || 'neutral'} className="capitalize">{u.role}</Badge>
                                        </Td>
                                        <Td className="text-muted">{u.last_login_at || '—'}</Td>
                                        <Td className="text-right">
                                            {!u.is_self && u.role !== 'owner' ? (
                                                <Button type="button" variant="ghost" size="sm" onClick={() => revoke(u)} className="text-danger hover:text-danger">
                                                    Remove
                                                </Button>
                                            ) : (
                                                <span className="text-xs text-subtle">—</span>
                                            )}
                                        </Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>
            </div>

            <InviteModal show={inviteOpen} onClose={() => setInviteOpen(false)} roles={data.roles} preview={preview} />
        </PlatformLayout>
    );
}
