import React from 'react';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout';
import TagInput from '../Components/TagInput';
import { Head, useForm, Link } from '@inertiajs/react';
import PageHeader from '../Components/Layout/PageHeader';
import PageShell from '../Components/Layout/PageShell';

export default function ClientCreate({ auth, upworkProfiles, workTypes }) {
    const form = useForm({
        name: '',
        tags: [],
        work_type: '',
        upwork_profile_ids: [], // Changed to array for multiple profiles
    });

    // Search and dropdown states
    const [workTypeSearch, setWorkTypeSearch] = React.useState('');
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
        form.post(route('clients.store'));
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
            <Head title="Add Client" />
            
            <PageShell width="max-w-5xl">
                <PageHeader
                    eyebrow="Clients"
                    title="Add Client"
                    description="Create a client and connect the relevant work type and profiles."
                />

                    {/* Form Card */}
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
                                            {isProfileRequired && <span className="text-red-500 ml-1">*</span>}
                                            {!isProfileRequired && <span className="text-slate-400 font-normal ml-2">(optional)</span>}
                                        </label>

                                        {/* Selected Profiles Display */}
                                        {selectedProfiles.length > 0 && (
                                            <div className="mb-3 flex flex-wrap gap-2">
                                                {selectedProfiles.map((profile) => (
                                                    <div
                                                        key={profile.id}
                                                        className="inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-purple-100 to-pink-100 text-purple-700 rounded-lg border-2 border-purple-200 font-medium text-sm shadow-sm"
                                                    >
                                                        <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                            <path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" />
                                                        </svg>
                                                        <div>
                                                            <span className="font-semibold">{profile.name}</span>
                                                            {profile.email && <span className="text-xs text-purple-600 ml-1">({profile.email})</span>}
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleProfileRemove(profile.id)}
                                                            className="hover:bg-purple-200 rounded-full p-1 transition-colors ml-1"
                                                            title="Remove profile"
                                                        >
                                                            <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        )}

                                        {/* Search Input */}
                                        <div ref={profileRef} className="relative">
                                            <div className="relative">
                                                <div className="absolute left-0 inset-y-0 flex items-center pl-3 pointer-events-none text-purple-500">
                                                    <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z" />
                                                    </svg>
                                                </div>
                                                <input
                                                    type="text"
                                                    placeholder={isProfileRequired ? 'Search and select upwork profiles...' : 'Search and select upwork profiles (optional)...'}
                                                    value={profileSearch}
                                                    onChange={(e) => {
                                                        setProfileSearch(e.target.value);
                                                        setShowProfileDropdown(true);
                                                    }}
                                                    onFocus={() => setShowProfileDropdown(true)}
                                                    className="w-full pl-10 pr-4 py-3 border-2 border-slate-200 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all text-slate-900 placeholder-slate-400"
                                                />
                                            </div>
                                            {showProfileDropdown && filteredProfiles.length > 0 && (
                                                <div ref={profileDropdownRef} className="absolute z-50 w-full mt-2 bg-white border-2 border-slate-200 rounded-xl shadow-xl max-h-64 overflow-y-auto">
                                                    {filteredProfiles.map((profile) => (
                                                        <button
                                                            key={profile.id}
                                                            type="button"
                                                            onClick={() => handleProfileSelect(profile)}
                                                            className="w-full px-4 py-3 text-left hover:bg-gradient-to-r hover:from-purple-50 hover:to-pink-50 transition-all border-b border-slate-100 last:border-0 flex items-center gap-3 group"
                                                        >
                                                            <div className="w-10 h-10 rounded-full bg-gradient-to-r from-purple-500 to-pink-500 flex items-center justify-center text-white font-semibold shadow-md">
                                                                {profile.name.charAt(0).toUpperCase()}
                                                            </div>
                                                            <div className="flex-1">
                                                                <div className="font-semibold text-slate-700">{profile.name}</div>
                                                                {profile.email && <div className="text-sm text-slate-500">{profile.email}</div>}
                                                            </div>
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                            {filteredProfiles.length === 0 && profileSearch && showProfileDropdown && (
                                                <div className="absolute z-50 w-full mt-2 bg-white border-2 border-slate-200 rounded-xl shadow-lg">
                                                    <div className="px-4 py-3 text-slate-500 text-sm text-center">
                                                        <svg className="w-12 h-12 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                        No available profiles found
                                                    </div>
                                                </div>
                                            )}
                                            {form.errors.upwork_profile_ids && (
                                                <div className="flex items-center mt-2 text-red-600 text-sm">
                                                    <svg className="w-4 h-4 mr-1.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                                    </svg>
                                                    {form.errors.upwork_profile_ids}
                                                </div>
                                            )}
                                        </div>
                                        
                                        <p className="text-slate-500 text-sm mt-3 flex items-start gap-2">
                                            <svg className="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                                            </svg>
                                            <span>
                                                {isProfileRequired 
                                                    ? 'Select one or more Upwork profiles associated with this client'
                                                    : 'Optionally select Upwork profiles for this client'
                                                }
                                            </span>
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
                                    {form.errors.tags && (
                                        <div className="flex items-center mt-2 text-red-600 text-sm">
                                            <svg className="w-4 h-4 mr-1.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                            </svg>
                                            {form.errors.tags}
                                        </div>
                                    )}
                                    <p className="text-slate-500 text-sm mt-3 flex items-start gap-2">
                                        <svg className="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                            <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                                        </svg>
                                        <span>Add tags to help organize and search for clients (e.g., "Enterprise", "Startup", "Local")</span>
                                    </p>
                                </div>

                                {/* Preview Card - only show if name is entered */}
                                {form.data.name && (
                                    <div className="rounded-lg border border-blue-200 bg-blue-50 p-5">
                                        <div className="flex items-center mb-5">
                                            <div className="w-14 h-14 bg-gradient-to-r from-purple-500 to-pink-500 rounded-2xl flex items-center justify-center text-white font-bold text-xl mr-4 shadow-lg">
                                                {form.data.name.charAt(0).toUpperCase()}
                                            </div>
                                            <div>
                                                <p className="text-sm text-purple-600 font-medium mb-1">Client Preview</p>
                                                <p className="font-bold text-slate-900 text-xl">{form.data.name}</p>
                                            </div>
                                        </div>
                                        
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                            {form.data.work_type && (
                                                <div>
                                                    <p className="text-sm text-slate-600 font-medium mb-2">Work Type</p>
                                                    <span className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold bg-white text-purple-700 rounded-lg border-2 border-purple-200 shadow-sm">
                                                        <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fillRule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clipRule="evenodd" />
                                                            <path d="M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z" />
                                                        </svg>
                                                        {workTypes?.[form.data.work_type] || form.data.work_type}
                                                    </span>
                                                </div>
                                            )}
                                            
                                            {selectedProfiles.length > 0 && (
                                                <div>
                                                    <p className="text-sm text-slate-600 font-medium mb-2">Upwork Profiles ({selectedProfiles.length})</p>
                                                    <div className="flex flex-wrap gap-2">
                                                        {selectedProfiles.map((profile) => (
                                                            <span
                                                                key={profile.id}
                                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold bg-white text-pink-700 rounded-lg border-2 border-pink-200 shadow-sm"
                                                            >
                                                                <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" />
                                                                </svg>
                                                                {profile.name}
                                                            </span>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                        
                                        {form.data.tags && form.data.tags.length > 0 && (
                                            <div className="pt-4 border-t border-purple-200">
                                                <p className="text-sm text-slate-600 font-medium mb-2">Tags ({form.data.tags.length})</p>
                                                <div className="flex flex-wrap gap-2">
                                                    {form.data.tags.map((tag, index) => (
                                                        <span
                                                            key={index}
                                                            className="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-semibold bg-white text-slate-700 rounded-lg border-2 border-slate-200 shadow-sm"
                                                        >
                                                            <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fillRule="evenodd" d="M17.707 9.293a1 1 0 010 1.414l-7 7a1 1 0 01-1.414 0l-7-7A.997.997 0 012 10V5a3 3 0 013-3h5c.256 0 .512.098.707.293l7 7zM5 6a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                                            </svg>
                                                            {tag}
                                                        </span>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* Submit Buttons */}
                                <div className="flex flex-col sm:flex-row gap-4 pt-6 border-t border-slate-100">
                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-blue-600 px-6 py-3 font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {form.processing ? (
                                            <>
                                                <svg className="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                Creating Client...
                                            </>
                                        ) : (
                                            <>
                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                </svg>
                                                Create Client
                                            </>
                                        )}
                                    </button>
                                    <Link
                                        href={route('clients.index')}
                                        className="flex-1 inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-white hover:bg-slate-50 text-slate-700 rounded-lg font-semibold transition-all shadow-md hover:shadow-lg border-2 border-slate-200"
                                    >
                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                        </svg>
                                        Cancel
                                    </Link>
                                </div>
                            </form>
                        </div>
                    </div>
            </PageShell>
        </AuthenticatedLayout>
    );
}
