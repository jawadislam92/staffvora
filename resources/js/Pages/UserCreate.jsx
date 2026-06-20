import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import UserForm from '@/Components/Users/UserForm';

export default function UserCreate({ auth, roles, permissionGroups, canManageAccess, designationOptions = [], teams = [] }) {
    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Add User" />
            <div className="min-h-screen bg-surface-2">
                <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
                    <div className="mb-6 border-b border-line pb-5">
                        <p className="text-sm font-semibold text-accent">Team management</p>
                        <h1 className="mt-1 text-2xl font-bold text-body">Add user</h1>
                        <p className="mt-1 text-sm text-muted">Create an account and assign only the access it needs.</p>
                    </div>
                    <UserForm
                        roles={roles}
                        permissionGroups={permissionGroups}
                        canManageAccess={canManageAccess}
                        designationOptions={designationOptions}
                        teams={teams}
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
