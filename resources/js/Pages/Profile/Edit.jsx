import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import { Head } from '@inertiajs/react';
import PageHeader from '@/Components/Layout/PageHeader';
import PageShell from '@/Components/Layout/PageShell';

export default function Edit({ auth, mustVerifyEmail, status }) {
    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Profile" />

            <PageShell width="max-w-5xl">
                <PageHeader
                    eyebrow="Account"
                    title="Profile Settings"
                    description="Manage your account details, password, and account status."
                />
                    <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl"
                        />
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <UpdatePasswordForm className="max-w-xl" />
                    </div>

                    <div className="rounded-lg border border-red-200 bg-white p-5 shadow-sm sm:p-6">
                        <DeleteUserForm className="max-w-xl" />
                    </div>
            </PageShell>
        </AuthenticatedLayout>
    );
}
