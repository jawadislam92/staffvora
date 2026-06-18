import React from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PageHeader from '../../Components/Layout/PageHeader';
import PageShell from '../../Components/Layout/PageShell';

export default function Index({ auth, profiles }) {
    const canManage = auth.user?.is_super_admin || auth.user?.permissions?.includes('profiles.manage');
    const { delete: destroy } = useForm();
    const handleDelete = (profile) => {
        if (confirm(`Are you sure you want to delete the profile "${profile.name}"? This action cannot be undone.`)) {
            destroy(route('upwork-profiles.destroy', profile.id), {
                onSuccess: () => {
                    // Handle success
                },
                onError: (errors) => {
                    // Delete failed
                }
            });
        }
    };

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Upwork Profiles" />
            <PageShell>
                <PageHeader
                    title="Upwork Profiles"
                    description="Manage profile names used for client assignments and time tracking."
                    actions={canManage && (
                                <Link
                                    href={route('upwork-profiles.create')}
                                    className="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"
                                >
                                    <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                    Add Profile
                                </Link>
                    )}
                />

                    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                            <div>
                                <h2 className="text-sm font-semibold text-slate-900">Profile directory</h2>
                                <p className="text-xs text-slate-500">{profiles.length} {profiles.length === 1 ? 'profile' : 'profiles'}</p>
                            </div>
                        </div>

                        {profiles.length === 0 ? (
                            <div className="px-4 py-10 text-center">
                                <svg className="mx-auto mb-3 h-10 w-10 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                <h3 className="text-base font-semibold text-slate-900">No profiles found</h3>
                                <p className="mt-1 text-sm text-slate-600">Create a profile to assign it to clients and work entries.</p>
                                {canManage && (
                                    <Link
                                        href={route('upwork-profiles.create')}
                                        className="mt-4 inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                    >
                                        Add first profile
                                    </Link>
                                )}
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-slate-200">
                                    <thead className="bg-slate-50">
                                        <tr>
                                            <th className="sticky left-0 z-10 bg-slate-50 px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Profile</th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Description</th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Status</th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Created</th>
                                            {canManage && <th className="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-500">Actions</th>}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-200 bg-white">
                                        {profiles.map((profile) => (
                                            <tr key={profile.id} className="bg-white hover:bg-slate-50">
                                                <td className="sticky left-0 z-[1] whitespace-nowrap bg-inherit px-4 py-3">
                                                    <div className="font-semibold text-slate-900">{profile.name}</div>
                                                    <div className="text-sm text-slate-500">{profile.email || 'No email'}</div>
                                                </td>
                                                <td className="max-w-md px-4 py-3 text-sm text-slate-600">
                                                    <span className="line-clamp-2">{profile.description || 'No description'}</span>
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3">
                                                    <span className={`inline-flex rounded-md px-2 py-1 text-xs font-semibold ${
                                                        profile.is_active
                                                            ? 'bg-emerald-50 text-emerald-700'
                                                            : 'bg-slate-100 text-slate-600'
                                                    }`}>
                                                        {profile.is_active ? 'Active' : 'Inactive'}
                                                    </span>
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-600">
                                                    {new Date(profile.created_at).toLocaleDateString()}
                                                </td>
                                                {canManage && (
                                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                                        <div className="flex justify-end gap-2">
                                                            <Link
                                                                href={route('upwork-profiles.edit', profile.id)}
                                                                className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                                            >
                                                                Edit
                                                            </Link>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleDelete(profile)}
                                                                className="rounded-lg px-3 py-1.5 text-sm font-semibold text-red-600 hover:bg-red-50"
                                                            >
                                                                Delete
                                                            </button>
                                                        </div>
                                                    </td>
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
            </PageShell>
        </AuthenticatedLayout>
    );
}
