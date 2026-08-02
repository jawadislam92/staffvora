import { Head, useForm, usePage } from '@inertiajs/react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import { Card, CardHeader, Field, Input, Button, Badge } from '@/Components/ui';

// Offline preview sample — a mock operator account so `preview` routes render
// with no authenticated platform user / no server round-trip.
const DEV_SAMPLE = {
    account: { name: 'Jawad', email: 'jawad@staffvora.test', role: 'owner' },
};

/**
 * Account — the signed-in operator's own profile + password form.
 * @param {object} props
 * @param {{name:string,email:string,role:string}} [props.account] Operator profile (server-provided).
 * @param {boolean} [props.preview] Render fully offline from DEV_SAMPLE (mock data).
 */
export default function Account({ account, preview = false }) {
    const account_ = account || (preview ? DEV_SAMPLE.account : { name: '', email: '', role: '' });

    // In preview there is no Inertia page context; guard usePage().
    const page = preview ? { props: {} } : usePage();
    const flash = page.props.flash || {};

    const form = useForm({
        name: account_.name || '',
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        if (preview) return;
        form.put(route('platform.account.update'), {
            preserveScroll: true,
            onSuccess: () => form.reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <PlatformLayout
            title="Account"
            preview={preview}
            header={<PlatformPageHeader title="Your account" description="Manage your operator profile and sign-in password." />}
        >
            <Head title="Account" />

            <div className="mx-auto max-w-2xl space-y-5">
                {flash.success && (
                    <div className="rounded-lg border border-line bg-success-soft px-3 py-2 text-sm text-success">
                        {flash.success}
                    </div>
                )}

                {/* Identity summary */}
                <Card padded={false}>
                    <div className="flex items-center gap-4 p-5">
                        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-accent/15 text-lg font-bold text-accent">
                            {(account_.name || 'Operator').trim().charAt(0).toUpperCase() || 'O'}
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-base font-semibold text-body">{account_.name || 'Operator'}</div>
                            <div className="truncate text-sm text-muted">{account_.email}</div>
                        </div>
                        {account_.role && (
                            <Badge tone="accent" className="capitalize">{account_.role}</Badge>
                        )}
                    </div>
                </Card>

                <form onSubmit={submit} className="space-y-5">
                    {/* Profile */}
                    <Card padded={false}>
                        <CardHeader title="Profile" description="Your display name across the operator portal." />
                        <div className="p-5">
                            <Field label="Name" htmlFor="account-name" error={form.errors.name}>
                                <Input
                                    id="account-name"
                                    type="text"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    autoComplete="name"
                                />
                            </Field>
                        </div>
                    </Card>

                    {/* Password */}
                    <Card padded={false}>
                        <CardHeader title="Password" description="Enter your current password to confirm a change." />
                        <div className="space-y-4 p-5">
                            <Field label="Current password" htmlFor="account-current-password" error={form.errors.current_password}>
                                <Input
                                    id="account-current-password"
                                    type="password"
                                    value={form.data.current_password}
                                    onChange={(e) => form.setData('current_password', e.target.value)}
                                    autoComplete="current-password"
                                />
                            </Field>
                            <Field
                                label="New password"
                                htmlFor="account-password"
                                hint="Minimum 12 characters."
                                error={form.errors.password}
                            >
                                <Input
                                    id="account-password"
                                    type="password"
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                    autoComplete="new-password"
                                />
                            </Field>
                            <Field label="Confirm new password" htmlFor="account-password-confirmation" error={form.errors.password_confirmation}>
                                <Input
                                    id="account-password-confirmation"
                                    type="password"
                                    value={form.data.password_confirmation}
                                    onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                    autoComplete="new-password"
                                />
                            </Field>
                        </div>
                    </Card>

                    <div className="flex justify-end">
                        <Button type="submit" loading={form.processing} disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </PlatformLayout>
    );
}
