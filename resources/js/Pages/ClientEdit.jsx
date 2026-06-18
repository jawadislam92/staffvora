import React from 'react';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout';
import TagInput from '../Components/TagInput';
import { Head, useForm, Link } from '@inertiajs/react';
import PageHeader from '../Components/Layout/PageHeader';
import PageShell from '../Components/Layout/PageShell';

export default function ClientEdit({ auth, client, upworkProfiles, workTypes, returnTo = '' }) {
    // Initialize with existing multiple profiles or fallback to single profile
    const initialProfileIds = client.upwork_profiles && client.upwork_profiles.length > 0 
        ? client.upwork_profiles.map(p => p.id)
        : (client.upwork_profile_id ? [client.upwork_profile_id] : []);

    const form = useForm({
        name: client.name || '',
        tags: client.tags || [],
        work_type: client.work_type || '',
        upwork_profile_ids: initialProfileIds, // Changed to support multiple profiles
        return_to: returnTo || '',
    });

    // Search and dropdown states
    const [workTypeSearch, setWorkTypeSearch] = React.useState(workTypes?.[client.work_type] || '');
    const [profileSearch, setProfileSearch] = React.useState('');
    const [showWorkTypeDropdown, setShowWorkTypeDropdown] = React.useState(false);
    const [showProfileDropdown, setShowProfileDropdown] = React.useState(false);
    
    // Refs for dropdown management
    const workTypeRef = React.useRef(null);
    const profileRef = React.useRef(null);
    const workTypeDropdownRef = React.useRef(null);
    const profileDropdownRef = React.useRef(null);

    const handleSubmit = (e) => {
        e.preventDefault();
        form.put(route('clients.update', client.id));
    };

    // Check if profile is required for the selected work type
    const isProfileRequired = form.data.work_type && ['tracker_manual', 'fixed'].includes(form.data.work_type);
    
    // Reset profiles when work type changes and profile is not required
    React.useEffect(() => {
        if (!isProfileRequired && form.data.upwork_profile_ids.length > 0) {
            form.setData('upwork_profile_ids', []);
            setProfileSearch('');
        }
    }, [form.data.work_type]);

    // Close dropdowns when clicking outside
    React.useEffect(() => {
        function handleClickOutside(event) {
            if (workTypeRef.current && workTypeDropdownRef.current && 
                !workTypeRef.current.contains(event.target) && 
                !workTypeDropdownRef.current.contains(event.target)) {
                setShowWorkTypeDropdown(false);
            }
            if (profileRef.current && profileDropdownRef.current && 
                !profileRef.current.contains(event.target) && 
                !profileDropdownRef.current.contains(event.target)) {
                setShowProfileDropdown(false);
            }
        }

        document.addEventListener('mousedown', handleClickOutside);
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, []);

    // Filter work types based on search
    const filteredWorkTypes = Object.entries(workTypes || {}).filter(([key, value]) =>
        value.toLowerCase().includes(workTypeSearch.toLowerCase())
    );

    // Filter profiles based on search and exclude already selected ones
    const filteredProfiles = upworkProfiles?.filter(profile =>
        profile.name.toLowerCase().includes(profileSearch.toLowerCase()) &&
        !form.data.upwork_profile_ids.includes(profile.id)
    ) || [];

    // Handle work type selection
    const handleWorkTypeSelect = (key, value) => {
        form.setData('work_type', key);
        setWorkTypeSearch(value);
        setShowWorkTypeDropdown(false);
    };

    // Handle profile selection
    const handleProfileSelect = (profile) => {
        const currentProfiles = [...form.data.upwork_profile_ids];
        if (!currentProfiles.includes(profile.id)) {
            currentProfiles.push(profile.id);
            form.setData('upwork_profile_ids', currentProfiles);
        }
        setProfileSearch('');
        setShowProfileDropdown(false);
    };

    // Handle profile removal
    const handleProfileRemove = (profileId) => {
        const updatedProfiles = form.data.upwork_profile_ids.filter(id => id !== profileId);
        form.setData('upwork_profile_ids', updatedProfiles);
    };

    // Get selected profiles for display
    const selectedProfiles = upworkProfiles?.filter(profile => 
        form.data.upwork_profile_ids.includes(profile.id)
    ) || [];

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Edit Client" />
            
            <PageShell width="max-w-5xl">
                <PageHeader
                    eyebrow="Clients"
                    title="Edit Client"
                    description={`Update information and assignments for ${client.name}.`}
                />
                    
                    {/* Form Section */}
                    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="p-5 sm:p-6">
                            
                            <form onSubmit={handleSubmit} className="space-y-6">
                                {/* Client Name */}
                                <div>
                                    <label className="block text-sm font-semibold text-slate-700 mb-3">
                                        Client Name
                                        <span className="text-red-500 ml-1">*</span>
                                    </label>
                                    <div className="relative">
                                        <div className="absolute left-0 inset-y-0 flex items-center pl-3 pointer-events-none text-purple-500">
                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                            </svg>
                                        </div>
                                        <input 
                                            type="text" 
                                            placeholder="Enter client name..." 
                                            value={form.data.name} 
                                            onChange={e => form.setData('name', e.target.value)} 
                                            className="w-full pl-10 pr-4 py-3 border-2 border-slate-200 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all text-slate-900 placeholder-slate-400"
                                            required 
                                        />
                                    </div>
                                    {form.errors.name && (
                                        <div className="flex items-center mt-2 text-red-600 text-sm">
                                            <svg className="w-4 h-4 mr-1.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                            </svg>
                                            {form.errors.name}
                                        </div>
                                    )}
                                </div>

                                {/* Work Type - Searchable Dropdown */}
                                <div ref={workTypeRef} className="relative">
                                    <label className="block text-sm font-semibold text-slate-700 mb-3">
                                        Work Type
                                        <span className="text-red-500 ml-1">*</span>
                                    </label>
                                    <div className="relative">
                                        <div className="absolute left-0 inset-y-0 flex items-center pl-3 pointer-events-none text-pink-500">
                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                            </svg>
                                        </div>
                                        <input
                                            type="text"
                                            placeholder="Search and select work type..."
                                        value={workTypeSearch}
                                        onChange={(e) => {
                                            setWorkTypeSearch(e.target.value);
                                            setShowWorkTypeDropdown(true);
                                        }}
                                        onFocus={() => setShowWorkTypeDropdown(true)}
                                        className="w-full pl-10 pr-4 py-3 border-2 border-slate-200 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all text-slate-900 placeholder-slate-400"
                                        required
                                    />
                                    </div>
                                    {showWorkTypeDropdown && filteredWorkTypes.length > 0 && (
                                        <div ref={workTypeDropdownRef} className="absolute z-50 w-full mt-2 bg-white border-2 border-slate-200 rounded-xl shadow-xl max-h-64 overflow-y-auto">
                                            {filteredWorkTypes.map(([key, value]) => (
                                                <button
                                                    key={key}
                                                    type="button"
                                                    onClick={() => handleWorkTypeSelect(key, value)}
                                                    className="w-full px-4 py-3 text-left hover:bg-gradient-to-r hover:from-purple-50 hover:to-pink-50 transition-all text-slate-700 border-b border-slate-100 last:border-0 flex items-center justify-between group"
                                                >
                                                    <span className="font-medium">{value}</span>
                                                    {form.data.work_type === key && (
                                                        <svg className="w-5 h-5 text-purple-500" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                        </svg>
                                                    )}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                    {form.errors.work_type && (
                                        <div className="flex items-center mt-2 text-red-600 text-sm">
                                            <svg className="w-4 h-4 mr-1.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                            </svg>
                                            {form.errors.work_type}
                                        </div>
                                    )}
                                </div>

                                {/* Upwork Profiles - conditionally shown with search and multiple selection */}
                                {(isProfileRequired) && (
                                    <div>
                                        <label className="block text-sm font-semibold text-slate-700 mb-3">
                                            Upwork Profiles 
                                            {isProfileRequired && <span className="text-red-500">*</span>}
                                            {!isProfileRequired && <span className="text-slate-400 font-normal ml-2">(optional)</span>}
                                        </label>

                                        {/* Selected Profiles Display */}
                                        {selectedProfiles.length > 0 && (
                                            <div className="mb-3">
                                                <p className="text-sm text-slate-600 mb-2">Selected Profiles:</p>
                                                <div className="space-y-2">
                                                    {selectedProfiles.map((profile) => (
                                                        <div
                                                            key={profile.id}
                                                            className="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-3"
                                                        >
                                                            <div>
                                                                <div className="font-medium text-slate-900">{profile.name}</div>
                                                                {profile.email && <div className="text-sm text-slate-600">{profile.email}</div>}
                                                            </div>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleProfileRemove(profile.id)}
                                                                className="p-1 text-red-500 hover:text-red-600 transition-colors"
                                                                title="Remove profile"
                                                            >
                                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {/* Search Input */}
                                        <div ref={profileRef} className="relative">
                                            <input
                                                type="text"
                                                placeholder={isProfileRequired ? 'Search and select upwork profiles...' : 'Search and select upwork profiles (optional)...'}
                                                value={profileSearch}
                                                onChange={(e) => {
                                                    setProfileSearch(e.target.value);
                                                    setShowProfileDropdown(true);
                                                }}
                                                onFocus={() => setShowProfileDropdown(true)}
                                                className="w-full px-4 py-3 border-2 border-slate-200 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all text-slate-900 placeholder-slate-400"
                                            />
                                            {showProfileDropdown && filteredProfiles.length > 0 && (
                                                <div ref={profileDropdownRef} className="absolute z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-xl">
                                                    {filteredProfiles.map((profile) => (
                                                        <button
                                                            key={profile.id}
                                                            type="button"
                                                            onClick={() => handleProfileSelect(profile)}
                                                            className="w-full px-4 py-3 text-left text-slate-900 hover:bg-gradient-to-r hover:from-purple-50 hover:to-pink-50 transition-all first:rounded-t-xl last:rounded-b-xl"
                                                        >
                                                            <div>
                                                                <div className="font-medium">{profile.name}</div>
                                                                {profile.email && <div className="text-sm text-slate-600">{profile.email}</div>}
                                                            </div>
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                            {filteredProfiles.length === 0 && profileSearch && showProfileDropdown && (
                                                <div className="absolute z-50 w-full mt-1 bg-white border-2 border-slate-200 rounded-xl shadow-2xl backdrop-blur-xl">
                                                    <div className="px-4 py-3 text-slate-600 text-sm">
                                                        No available profiles found
                                                    </div>
                                                </div>
                                            )}
                                            {form.errors.upwork_profile_ids && <div className="text-red-500 text-sm mt-2">{form.errors.upwork_profile_ids}</div>}
                                        </div>
                                        
                                        <p className="text-slate-500 text-sm mt-2">
                                            {isProfileRequired 
                                                ? 'Select one or more Upwork profiles associated with this client'
                                                : 'Optionally select Upwork profiles for this client'
                                            }
                                        </p>
                                    </div>
                                )}

                                {/* Tags */}
                                <div>
                                    <label className="block text-sm font-semibold text-slate-700 mb-3">
                                        Tags
                                        <span className="text-slate-400 font-normal ml-2">(optional)</span>
                                    </label>
                                    <TagInput
                                        tags={form.data.tags}
                                        onChange={(newTags) => form.setData('tags', newTags)}
                                        placeholder="Add tags to categorize this client..."
                                    />
                                    {form.errors.tags && <div className="text-red-500 text-sm mt-2">{form.errors.tags}</div>}
                                    <p className="text-slate-500 text-sm mt-2">
                                        Add tags to help organize and search for clients (e.g., "Enterprise", "Startup", "Local")
                                    </p>
                                </div>

                                {/* Current Client Display */}
                                <div className="rounded-lg border border-blue-200 bg-blue-50 p-5">
                                    <div className="flex items-center mb-4">
                                        <div className="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-500 rounded-xl flex items-center justify-center text-slate-900 font-bold text-xl mr-4 shadow-lg">
                                            {client.name.charAt(0).toUpperCase()}
                                        </div>
                                        <div>
                                            <p className="text-sm text-slate-500 mb-1">Editing Client:</p>
                                            <p className="font-semibold text-slate-900 text-lg">{client.name}</p>
                                        </div>
                                    </div>
                                    
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div>
                                            <p className="text-sm text-slate-500 mb-1">Current Work Type:</p>
                                            <span className="inline-flex px-3 py-1 text-xs font-semibold bg-blue-50 text-blue-800 rounded-md border border-blue-200">
                                                {workTypes?.[client.work_type] || client.work_type || 'Not set'}
                                            </span>
                                        </div>
                                        
                                        <div>
                                            <p className="text-sm text-slate-500 mb-1">Current Upwork Profiles:</p>
                                            <div className="flex flex-wrap gap-2">
                                                {/* Show multiple profiles if they exist */}
                                                {client.upwork_profiles && client.upwork_profiles.length > 0 ? (
                                                    client.upwork_profiles.map((profile) => (
                                                        <span key={profile.id} className="inline-flex px-3 py-1 text-xs font-semibold bg-purple-50 text-purple-800 rounded-md border border-purple-200">
                                                            {profile.name}
                                                        </span>
                                                    ))
                                                ) : client.upwork_profile ? (
                                                    <span className="inline-flex px-3 py-1 text-xs font-semibold bg-purple-50 text-purple-800 rounded-md border border-purple-200">
                                                        {client.upwork_profile.name}
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex px-3 py-1 text-xs font-medium bg-slate-100 text-slate-700 rounded-md border border-slate-200">
                                                        Not set
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    
                                    {client.tags && client.tags.length > 0 && (
                                        <div>
                                            <p className="text-sm text-slate-500 mb-2">Current Tags:</p>
                                            <div className="flex flex-wrap gap-2">
                                                {client.tags.map((tag, index) => (
                                                    <span
                                                        key={index}
                                                        className="inline-flex px-2 py-1 text-xs font-semibold bg-emerald-50 text-emerald-800 rounded-md border border-emerald-300"
                                                    >
                                                        {tag}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {/* Submit Buttons */}
                                <div className="flex flex-col sm:flex-row gap-4 pt-6">
                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="inline-flex flex-1 items-center justify-center rounded-lg bg-blue-600 px-6 py-3 font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
                                    >
                                        <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                        </svg>
                                        {form.processing ? 'Updating...' : 'Update Client'}
                                    </button>
                                    <Link
                                        href={returnTo || route('clients.index')}
                                        className="inline-flex flex-1 items-center justify-center rounded-lg border border-slate-300 bg-white px-6 py-3 font-semibold text-slate-700 hover:bg-slate-50"
                                    >
                                        <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                        </svg>
                                        Back to Clients
                                    </Link>
                                </div>
                            </form>
                        </div>
                    </div>
            </PageShell>
        </AuthenticatedLayout>
    );
}
