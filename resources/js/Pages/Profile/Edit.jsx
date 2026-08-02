import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdateDisplayPreferencesForm from './Partials/UpdateDisplayPreferencesForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import { Head } from '@inertiajs/react';
import { Card, Badge } from '@/Components/ui';
import Avatar from '@/Components/Avatar';

// ── DEV_SAMPLE ─────────────────────────────────────────────────────────────
// Offline fixture for `preview` mode: a mock super-admin so the page (and the
// shell avatar) render fully without any server round-trip.
const DEV_SAMPLE = {
    user: {
        id: 1,
        name: 'Jawad',
        email: 'jawad@staffvora.com',
        designation: 'Founder & Super Admin',
        avatar: null,
        avatar_url: null,
        email_verified_at: '2026-01-01T00:00:00Z',
        is_super_admin: true,
        permissions: [],
    },
    mustVerifyEmail: false,
    status: null,
    // No personal override yet — the form shows the workspace default selected.
    displayPreferences: { override: false, timezone: null, format: null },
};

export default function Edit({
    auth,
    mustVerifyEmail,
    status,
    displayPreferences,
    preview = false,
}) {
    // In preview we short-circuit every prop-dependent value with DEV_SAMPLE so
    // the page renders identically offline; real behavior is untouched.
    const user = preview ? DEV_SAMPLE.user : auth.user;
    const verifyEmail = preview ? DEV_SAMPLE.mustVerifyEmail : mustVerifyEmail;
    const flashStatus = preview ? DEV_SAMPLE.status : status;
    const displayPrefs = preview ? DEV_SAMPLE.displayPreferences : displayPreferences;

    return (
        <AuthenticatedLayout user={user} header="Profile Settings">
            <Head title="Profile" />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                {/* Identity summary */}
                <Card>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-4">
                            <Avatar user={user} size="lg" />
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <h1 className="truncate text-lg font-semibold text-body">
                                        {user?.name}
                                    </h1>
                                    {user?.is_super_admin && (
                                        <Badge tone="accent">Super Admin</Badge>
                                    )}
                                </div>
                                <p className="truncate text-sm text-muted">{user?.email}</p>
                                {user?.designation && (
                                    <p className="mt-0.5 truncate text-sm text-subtle">
                                        {user.designation}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                </Card>

                <Card
                    title="Profile Information"
                    description="Update your account's profile information and email address."
                >
                    <UpdateProfileInformationForm
                        user={user}
                        mustVerifyEmail={verifyEmail}
                        status={flashStatus}
                        preview={preview}
                    />
                </Card>

                <Card
                    title="Display preferences"
                    description="Choose the time zone and clock format you see times in. This only changes how times display to you — it never changes when anything actually happened, or which day your work counts towards."
                >
                    <UpdateDisplayPreferencesForm
                        preferences={displayPrefs}
                        preview={preview}
                    />
                </Card>

                <Card
                    title="Update Password"
                    description="Ensure your account is using a long, random password to stay secure."
                >
                    <UpdatePasswordForm preview={preview} />
                </Card>

                <Card
                    title="Delete Account"
                    description="Permanently delete your account and all of its data."
                    className="border-danger/40"
                >
                    <DeleteUserForm preview={preview} />
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
