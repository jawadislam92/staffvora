import React, { useState, useRef, useEffect } from 'react';
import AuthenticatedLayout from '../Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import PageHeader from '../Components/Layout/PageHeader';
import PageShell from '../Components/Layout/PageShell';

export default function WorkHourCreate({ auth, clients = [], trackers = [] }) {
    const form = useForm({
        date: (() => {
            // Get current date in Pakistan timezone (Asia/Karachi)
            const pakistanDate = new Date().toLocaleDateString('en-CA', {
                timeZone: 'Asia/Karachi'
            });
            return pakistanDate; // Returns YYYY-MM-DD format
        })(),
        hours: '0',
        minutes: '0',
        description: '',
        work_type: '', // Explicitly empty - no default selection
        client_id: '',
        tracker: '',
        trackerSearch: '',
        clientSearch: '',
    });
    
    // Get filtered clients based on work type
    const getFilteredClients = () => {
        if (!form.data.work_type) return clients;
        
        // Map work types to client work types
        const workTypeMapping = {
            'tracker': ['tracker_manual'],
            'manual': ['tracker_manual'], 
            'fixed': ['fixed'],
            'outside_of_upwork': ['outside_of_upwork']
        };
        
        const possibleClientWorkTypes = workTypeMapping[form.data.work_type];
        if (!possibleClientWorkTypes) return clients;
        
        const filtered = clients.filter(client => {
            return possibleClientWorkTypes.includes(client.work_type);
        });
        
        return filtered;
    };

    // Get filtered trackers based on selected client
    const getFilteredTrackers = () => {
        if (!form.data.client_id) return trackers;
        
        // Find the selected client
        const selectedClient = clients.find(client => client.id == form.data.client_id);
        if (!selectedClient) return trackers;
        
        // Collect all associated profile names
        let availableProfiles = [];
        
        // Check for multiple profiles first (new many-to-many relationship)
        if (selectedClient.upwork_profiles && selectedClient.upwork_profiles.length > 0) {
            availableProfiles = selectedClient.upwork_profiles.map(profile => profile.name);
        }
        // Fallback to single profile (old relationship) for backward compatibility
        else if (selectedClient.upwork_profile && selectedClient.upwork_profile.name) {
            availableProfiles = [selectedClient.upwork_profile.name];
        }
        
        // If client has associated profiles, filter trackers to show only those profiles
        if (availableProfiles.length > 0) {
            const filteredTrackers = trackers.filter(tracker => availableProfiles.includes(tracker));
            return filteredTrackers;
        }
        
        // If no specific profiles assigned to client, show all trackers
        return trackers;
    };
    
    const [showTrackerOptions, setShowTrackerOptions] = useState(false);
    const [showClientOptions, setShowClientOptions] = useState(false);
    const [clientValidationError, setClientValidationError] = useState('');
    const [trackerValidationError, setTrackerValidationError] = useState('');
    const trackerRef = useRef(null);
    const clientRef = useRef(null);
    const trackerDropdownRef = useRef(null);
    const clientDropdownRef = useRef(null);

    // Close dropdowns when clicking outside
    useEffect(() => {
        function handleClickOutside(event) {
            if (trackerRef.current && trackerDropdownRef.current && 
                !trackerRef.current.contains(event.target) && 
                !trackerDropdownRef.current.contains(event.target)) {
                setShowTrackerOptions(false);
            }
            if (clientRef.current && clientDropdownRef.current && 
                !clientRef.current.contains(event.target) && 
                !clientDropdownRef.current.contains(event.target)) {
                setShowClientOptions(false);
            }
        }

        document.addEventListener('mousedown', handleClickOutside);
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, []);

    // Helper function to check if time is entered
    const hasTimeEntered = () => {
        const hours = Number(form.data.hours) || 0;
        const minutes = Number(form.data.minutes) || 0;
        return hours > 0 || minutes > 0;
    };

    // Validate client selection
    const validateClient = (searchValue) => {
        if (!isClientRequired()) {
            setClientValidationError('');
            return true;
        }

        if (!searchValue || searchValue.trim() === '') {
            setClientValidationError('Please select a client from the dropdown');
            return false;
        }

        // Check if the entered value exactly matches a filtered client name
        const filteredClients = getFilteredClients();
        const matchingClient = filteredClients.find(client => client.name === searchValue);
        if (!matchingClient) {
            setClientValidationError('Please select a valid client from the dropdown');
            return false;
        }

        // Ensure client_id is set correctly
        if (form.data.client_id != matchingClient.id) {
            form.setData('client_id', matchingClient.id);
        }

        setClientValidationError('');
        return true;
    };

    // Validate tracker selection
    const validateTracker = (searchValue) => {
        if (!isTrackerRequired()) {
            setTrackerValidationError('');
            return true;
        }

        if (!searchValue || searchValue.trim() === '') {
            setTrackerValidationError('Please select a profile from the dropdown');
            return false;
        }

        // Check if the entered value exactly matches a filtered tracker
        const filteredTrackers = getFilteredTrackers();
        const matchingTracker = filteredTrackers.find(tracker => tracker === searchValue);
        if (!matchingTracker) {
            setTrackerValidationError('Please select a valid profile from the dropdown');
            return false;
        }

        // Ensure tracker is set correctly
        if (form.data.tracker !== matchingTracker) {
            form.setData('tracker', matchingTracker);
        }

        setTrackerValidationError('');
        return true;
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        
        // Check if work type is selected
        if (!form.data.work_type) {
            alert('Please select a work type.');
            return;
        }
        
        // Check if at least some time is entered
        const hours = Number(form.data.hours) || 0;
        const minutes = Number(form.data.minutes) || 0;
        
        if (hours === 0 && minutes === 0) {
            alert('Please enter at least some time (hours or minutes).');
            return;
        }

        // Validate client selection
        const isClientValid = validateClient(form.data.clientSearch);
        const isTrackerValid = validateTracker(form.data.trackerSearch || form.data.tracker);

        if (!isClientValid || !isTrackerValid) {
            return; // Don't submit if validation fails
        }
        
        // Calculate total hours as decimal (hours + minutes/60)
        const totalHours = hours + (minutes / 60);
        
        // Round to 2 decimal places for precision
        const roundedTotal = Math.round(totalHours * 100) / 100;
        
        form.post(route('work-hours.store', { hours: roundedTotal }));
    };

    const workTypes = [
        { label: 'Tracker', value: 'tracker' },
        { label: 'Manual Time', value: 'manual' },
        { label: 'Fixed Client', value: 'fixed' },
        { label: 'Outside of Upwork', value: 'outside_of_upwork' },
        { label: 'Office Work', value: 'office_work' },
        { label: 'Test Task', value: 'test_task' },
        { label: 'Upwork Bidding', value: 'upwork_bidding' },
    ];

    const handleTrackerFocus = () => {
        setShowTrackerOptions(true);
        setShowClientOptions(false);
    };

    const handleClientFocus = () => {
        setShowClientOptions(true);
        setShowTrackerOptions(false);
    };

    const handleWorkTypeChange = (workType) => {
        // Clear fields that might not be needed for the new work type
        const newData = {
            ...form.data,
            work_type: workType,
        };

        // Clear profile name if not needed for this work type
        if (!['tracker', 'manual', 'fixed'].includes(workType)) {
            newData.tracker = '';
            newData.trackerSearch = '';
            setTrackerValidationError('');
        }

        // Clear client if not needed for this work type
        if (!['tracker', 'manual', 'fixed', 'outside_of_upwork'].includes(workType)) {
            newData.client_id = '';
            newData.clientSearch = '';
            setClientValidationError('');
        } else {
            // Always clear client selection when work type changes to force reselection from filtered list
            newData.client_id = '';
            newData.clientSearch = '';
            setClientValidationError('');
        }

        form.setData(newData);
    };

    const isTrackerRequired = () => {
        return ['tracker', 'manual', 'fixed'].includes(form.data.work_type);
    };

    const isClientRequired = () => {
        return ['tracker', 'manual', 'fixed', 'outside_of_upwork'].includes(form.data.work_type);
    };

    // Handle client search change
    const handleClientSearchChange = (e) => {
        const val = e.target.value;
        form.setData('clientSearch', val);
        
        // Clear client_id if the search doesn't match any client exactly
        const filteredClients = getFilteredClients();
        const matchingClient = filteredClients.find(client => client.name === val);
        if (!matchingClient) {
            form.setData('client_id', '');
        } else {
            form.setData('client_id', matchingClient.id);
        }
        
        // Clear validation error when user starts typing
        if (clientValidationError) {
            setClientValidationError('');
        }
        
        setShowClientOptions(true);
    };

    // Handle tracker search change
    const handleTrackerSearchChange = (e) => {
        const val = e.target.value;
        form.setData('trackerSearch', val);
        
        // Clear tracker if the search doesn't match any filtered tracker exactly
        const filteredTrackers = getFilteredTrackers();
        const matchingTracker = filteredTrackers.find(tracker => tracker === val);
        if (!matchingTracker) {
            form.setData('tracker', '');
        } else {
            form.setData('tracker', matchingTracker);
        }
        
        // Clear validation error when user starts typing
        if (trackerValidationError) {
            setTrackerValidationError('');
        }
        
        setShowTrackerOptions(true);
    };

    // Handle client selection from dropdown
    const handleClientSelect = (client) => {
        form.setData('client_id', client.id);
        form.setData('clientSearch', client.name);
        setClientValidationError(''); // Clear any validation errors immediately
        setShowClientOptions(false);
        
        // Clear tracker selection when client changes to force reselection from filtered list
        form.setData('tracker', '');
        form.setData('trackerSearch', '');
        setTrackerValidationError('');
        
        // Ensure validation passes for this selection
        setTimeout(() => {
            validateClient(client.name);
        }, 50);
    };

    // Handle tracker selection from dropdown
    const handleTrackerSelect = (tracker) => {
        form.setData('tracker', tracker);
        form.setData('trackerSearch', tracker);
        setTrackerValidationError(''); // Clear any validation errors immediately
        setShowTrackerOptions(false);
        
        // Ensure validation passes for this selection
        setTimeout(() => {
            validateTracker(tracker);
        }, 50);
    };

    // Handle client field blur - validate when user leaves the field
    const handleClientBlur = () => {
        setTimeout(() => {
            setShowClientOptions(false);
            if (isClientRequired()) {
                validateClient(form.data.clientSearch);
            }
        }, 300); // Increased timeout to allow dropdown click to complete
    };

    // Handle tracker field blur - validate when user leaves the field
    const handleTrackerBlur = () => {
        setTimeout(() => {
            setShowTrackerOptions(false);
            if (isTrackerRequired()) {
                validateTracker(form.data.trackerSearch || form.data.tracker);
            }
        }, 300); // Increased timeout to allow dropdown click to complete
    };

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Add Work Entry" />
            
            <PageShell width="max-w-6xl">
                <PageHeader
                    eyebrow="Work diary"
                    title="Add Work Entry"
                    description="Log time against the correct work type, client, and profile."
                />

                    {/* Form Section */}
                    <div className="overflow-visible rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="p-5 sm:p-6">
                            <form onSubmit={handleSubmit}>
                                <div className="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                                    <div className="mb-4">
                                        <div className="flex items-center gap-3 mb-3">
                                            <div className="p-2 bg-gradient-to-r from-emerald-500 to-teal-500 rounded-lg">
                                                <svg className="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                                </svg>
                                            </div>
                                            <h3 className="text-lg font-bold text-slate-900">Work Type</h3>
                                        </div>
                                        <p className="text-sm text-slate-700 mb-4 pl-14">
                                            {form.data.work_type ? (
                                                <>
                                                    <span className="font-semibold text-emerald-700">Required fields:</span> {' '}
                                                    {form.data.work_type === 'tracker' && "Client Name, Profile Name, Description, Hours/Minutes, Tracking Date"}
                                                    {form.data.work_type === 'manual' && "Client Name, Profile Name, Description, Hours/Minutes, Tracking Date"}
                                                    {form.data.work_type === 'fixed' && "Client Name, Profile Name, Description, Hours/Minutes, Tracking Date"}
                                                    {form.data.work_type === 'outside_of_upwork' && "Client Name, Description, Hours/Minutes, Tracking Date"}
                                                    {form.data.work_type === 'office_work' && "Description, Hours/Minutes, Tracking Date"}
                                                    {form.data.work_type === 'test_task' && "Description, Hours/Minutes, Tracking Date"}
                                                    {form.data.work_type === 'upwork_bidding' && "Description, Hours/Minutes, Tracking Date"}
                                                </>
                                            ) : (
                                                <span className="text-slate-500 italic">Please select a work type to see required fields</span>
                                            )}
                                        </p>
                                    </div>
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                        {workTypes.map((type) => (
                                            <button
                                                key={type.value}
                                                type="button"
                                                onClick={() => handleWorkTypeChange(type.value)}
                                                className={`min-h-11 rounded-lg p-2.5 text-sm font-semibold transition ${
                                                    form.data.work_type === type.value
                                                        ? 'bg-emerald-600 text-white shadow-sm'
                                                        : 'border border-slate-300 bg-white text-slate-700 hover:border-emerald-400 hover:bg-emerald-50'
                                                }`}
                                            >
                                                {type.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div className={`grid gap-6 mb-6 ${
                                    isTrackerRequired() && isClientRequired() ? 'md:grid-cols-2' : 
                                    isClientRequired() ? 'md:grid-cols-1' : 
                                    isTrackerRequired() ? 'md:grid-cols-1' : 
                                    'md:grid-cols-1'
                                }`}>
                                    {/* Client Name */}
                                    {isClientRequired() && (
                                        <div className="relative">
                                            <label htmlFor="client" className="block text-sm font-semibold text-slate-700 mb-3">
                                                Client Name <span className="text-red-500 ml-1">*</span>
                                            </label>
                                            <div className="relative">
                                                <div className="absolute left-0 inset-y-0 flex items-center pl-3 pointer-events-none text-emerald-500">
                                                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                                    </svg>
                                                </div>
                                                <input
                                                    ref={clientRef}
                                                    type="text"
                                                    placeholder="Search or select client..."
                                                    className={`w-full pl-10 pr-4 py-3 border-2 rounded-lg focus:ring-2 focus:ring-emerald-200 text-slate-900 placeholder-slate-400 transition-all ${
                                                        clientValidationError ? 'border-red-500 focus:border-red-500' : 'border-slate-200 focus:border-emerald-500'
                                                    }`}
                                                    value={form.data.clientSearch}
                                                    onChange={handleClientSearchChange}
                                                    onFocus={handleClientFocus}
                                                    onBlur={handleClientBlur}
                                                    required={isClientRequired()}
                                                />
                                            </div>
                                            {clientValidationError && (
                                                <div className="flex items-center mt-2 text-red-600 text-sm">
                                                    <svg className="w-4 h-4 mr-1.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                                    </svg>
                                                    {clientValidationError}
                                                </div>
                                            )}
                                            {!clientValidationError && form.data.work_type && (
                                                <div className="mt-2 text-xs text-slate-600 flex items-center gap-1.5">
                                                    <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                                                    </svg>
                                                    <span>
                                                        Showing clients for: {' '}
                                                        {form.data.work_type === 'tracker' && 'Tracker/Manual Time'}
                                                        {form.data.work_type === 'manual' && 'Tracker/Manual Time'}
                                                        {form.data.work_type === 'fixed' && 'Fixed Client'}
                                                        {form.data.work_type === 'outside_of_upwork' && 'Outside of Upwork'}
                                                        {' '}({getFilteredClients().length} available)
                                                    </span>
                                                </div>
                                            )}
                                            {showClientOptions && (
                                                <div 
                                                    ref={clientDropdownRef}
                                                    className="absolute z-50 w-full mt-2 bg-white border-2 border-slate-200 rounded-xl max-h-64 overflow-y-auto shadow-xl"
                                                >
                                                    <div className="p-2">
                                                        {getFilteredClients().filter(client => {
                                                            const label = client.name;
                                                            return !form.data.clientSearch || label.toLowerCase().includes(form.data.clientSearch.toLowerCase());
                                                        }).map(client => (
                                                            <button
                                                                key={client.id}
                                                                type="button"
                                                                className="w-full text-left px-3 py-2.5 hover:bg-gradient-to-r hover:from-emerald-50 hover:to-teal-50 rounded-lg text-slate-700 transition-all border-b border-slate-100 last:border-0"
                                                                onMouseDown={(e) => e.preventDefault()} // Prevent blur when clicking
                                                                onClick={() => handleClientSelect(client)}
                                                            >
                                                                <div className="flex items-center justify-between">
                                                                    <span className="font-medium">{client.name}</span>
                                                                    <span className="text-xs px-2 py-1 bg-emerald-100 text-emerald-700 rounded-md font-semibold">
                                                                        {client.work_type === 'tracker_manual' && 'Tracker/Manual'}
                                                                        {client.work_type === 'fixed' && 'Fixed'}
                                                                        {client.work_type === 'outside_of_upwork' && 'Outside Upwork'}
                                                                    </span>
                                                                </div>
                                                            </button>
                                                        ))}
                                                        {getFilteredClients().filter(client => {
                                                            const label = client.name;
                                                            return !form.data.clientSearch || label.toLowerCase().includes(form.data.clientSearch.toLowerCase());
                                                        }).length === 0 && (
                                                            <div className="px-3 py-4 text-slate-500 text-sm text-center">
                                                                <svg className="w-12 h-12 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                                </svg>
                                                                {form.data.clientSearch ? 
                                                                    'No matching clients found for this work type.' : 
                                                                    'No clients available for this work type.'}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {/* Profile Name */}
                                    {isTrackerRequired() && (
                                        <div className="relative">
                                            <label htmlFor="tracker" className="block text-sm font-semibold text-slate-700 mb-3">
                                                Profile Name <span className="text-red-500 ml-1">*</span>
                                            </label>
                                            <div className="relative">
                                                <div className="absolute left-0 inset-y-0 flex items-center pl-3 pointer-events-none text-teal-500">
                                                    <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" />
                                                    </svg>
                                                </div>
                                                <input
                                                    ref={trackerRef}
                                                    type="text"
                                                    placeholder="Search or select profile..."
                                                    className={`w-full pl-10 pr-4 py-3 border-2 rounded-lg focus:ring-2 focus:ring-emerald-200 text-slate-900 placeholder-slate-400 transition-all ${
                                                        trackerValidationError ? 'border-red-500 focus:border-red-500' : 'border-slate-200 focus:border-emerald-500'
                                                    }`}
                                                    value={
                                                        form.data.trackerSearch ||
                                                        (form.data.tracker ? form.data.tracker : '')
                                                    }
                                                    onChange={handleTrackerSearchChange}
                                                    onFocus={handleTrackerFocus}
                                                    onBlur={handleTrackerBlur}
                                                    required={isTrackerRequired()}
                                                />
                                            </div>
                                            {trackerValidationError && (
                                                <div className="flex items-center mt-2 text-red-600 text-sm">
                                                    <svg className="w-4 h-4 mr-1.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                                    </svg>
                                                    {trackerValidationError}
                                                </div>
                                            )}
                                            {!trackerValidationError && form.data.client_id && (
                                                <div className="mt-2 text-xs text-slate-600 flex items-center gap-1.5">
                                                    <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                                                    </svg>
                                                    <span>
                                                        {(() => {
                                                            const selectedClient = clients.find(client => client.id == form.data.client_id);
                                                            if (selectedClient) {
                                                                // Count available profiles
                                                                let profileCount = 0;
                                                                if (selectedClient.upwork_profiles && selectedClient.upwork_profiles.length > 0) {
                                                                    profileCount = selectedClient.upwork_profiles.length;
                                                                } else if (selectedClient.upwork_profile) {
                                                                    profileCount = 1;
                                                                }
                                                                
                                                                if (profileCount > 0) {
                                                                    return `Showing ${profileCount} profile${profileCount > 1 ? 's' : ''} for: ${selectedClient.name} (${getFilteredTrackers().length} available)`;
                                                                } else {
                                                                    return `Showing all profiles for: ${selectedClient.name} (${getFilteredTrackers().length} available)`;
                                                                }
                                                            }
                                                            return 'Select a client to see available profiles';
                                                        })()}
                                                    </span>
                                                </div>
                                            )}
                                            {showTrackerOptions && (
                                                <div 
                                                    ref={trackerDropdownRef}
                                                    className="absolute z-50 w-full mt-2 bg-white border-2 border-slate-200 rounded-xl max-h-64 overflow-y-auto shadow-xl"
                                                >
                                                    <div className="p-2">
                                                        {getFilteredTrackers().filter(tracker => 
                                                            !form.data.trackerSearch || tracker.toLowerCase().includes(form.data.trackerSearch.toLowerCase())
                                                        ).map((tracker, index) => (
                                                            <button
                                                                key={index}
                                                                type="button"
                                                                className="w-full text-left px-3 py-2.5 hover:bg-gradient-to-r hover:from-emerald-50 hover:to-teal-50 rounded-lg text-slate-700 transition-all border-b border-slate-100 last:border-0 font-medium"
                                                                onMouseDown={(e) => e.preventDefault()} // Prevent blur when clicking
                                                                onClick={() => handleTrackerSelect(tracker)}
                                                            >
                                                                <div className="flex items-center gap-2">
                                                                    <svg className="w-4 h-4 text-teal-500" fill="currentColor" viewBox="0 0 20 20">
                                                                        <path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" />
                                                                    </svg>
                                                                    {tracker}
                                                                </div>
                                                            </button>
                                                        ))}
                                                        {getFilteredTrackers().filter(tracker => 
                                                            !form.data.trackerSearch || tracker.toLowerCase().includes(form.data.trackerSearch.toLowerCase())
                                                        ).length === 0 && (
                                                            <div className="px-3 py-4 text-slate-500 text-sm text-center">
                                                                <svg className="w-12 h-12 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                                </svg>
                                                                {form.data.trackerSearch ? 
                                                                    'No matching profiles found for this client.' : 
                                                                    'No profiles available for this client.'}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                                    {/* Date */}
                                    <div>
                                        <label htmlFor="date" className="block text-sm font-medium text-slate-700 mb-3 font-semibold">
                                            Date <span className="text-red-500">*</span>
                                        </label>
                                        <input
                                            type="date"
                                            id="date"
                                            value={form.data.date}
                                            onChange={(e) => form.setData('date', e.target.value)}
                                            className="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 text-slate-900"
                                            required
                                        />
                                    </div>

                                    {/* Hours */}
                                    <div>
                                        <label htmlFor="hours" className="block text-sm font-medium text-slate-700 mb-3 font-semibold">
                                            Hours <span className="text-red-500">*</span>
                                        </label>
                                        <input
                                            type="number"
                                            id="hours"
                                            min="0"
                                            max="24"
                                            step="1"
                                            value={form.data.hours}
                                            onChange={(e) => {
                                                let value = parseInt(e.target.value);
                                                if (isNaN(value) || value < 0) value = 0;
                                                if (value > 24) value = 24;
                                                form.setData('hours', value.toString());
                                            }}
                                            onWheel={(e) => e.target.blur()}
                                            onFocus={(e) => e.target.addEventListener('wheel', (event) => event.preventDefault(), { passive: false })}
                                            onBlur={(e) => e.target.removeEventListener('wheel', (event) => event.preventDefault())}
                                            placeholder="Enter hours (0-24)"
                                            className="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 text-slate-900 placeholder-slate-400"
                                            required
                                        />
                                    </div>

                                    {/* Minutes */}
                                    <div>
                                        <label htmlFor="minutes" className="block text-sm font-medium text-slate-700 mb-3 font-semibold">
                                            Minutes
                                        </label>
                                        <input
                                            type="number"
                                            id="minutes"
                                            min="0"
                                            max="59"
                                            step="1"
                                            value={form.data.minutes}
                                            onChange={(e) => {
                                                let value = parseInt(e.target.value);
                                                if (isNaN(value) || value < 0) value = 0;
                                                if (value > 59) value = 59;
                                                form.setData('minutes', value.toString());
                                            }}
                                            onWheel={(e) => e.target.blur()}
                                            onFocus={(e) => e.target.addEventListener('wheel', (event) => event.preventDefault(), { passive: false })}
                                            onBlur={(e) => e.target.removeEventListener('wheel', (event) => event.preventDefault())}
                                            placeholder="Enter minutes (0-59)"
                                            className="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 text-slate-900 placeholder-slate-400"
                                        />
                                    </div>
                                </div>

                                {/* Total Time Display */}
                                <div className={`mb-6 p-4 backdrop-blur-xl rounded-xl border ${
                                    hasTimeEntered() 
                                        ? 'bg-gradient-to-r from-green-500/20 to-blue-500/20 border-slate-200' 
                                        : 'bg-gradient-to-r from-red-500/20 to-orange-500/20 border-red-400/30'
                                }`}>
                                    <div className="flex items-center gap-3">
                                        <svg className={`w-6 h-6 ${hasTimeEntered() ? 'text-blue-500' : 'text-red-500'}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <div className="text-slate-900">
                                            <div className={`text-sm font-medium ${hasTimeEntered() ? 'text-slate-600' : 'text-red-600'}`}>
                                                Total Time {!hasTimeEntered() && '(Required)'}
                                            </div>
                                            <div className={`text-2xl font-bold ${!hasTimeEntered() ? 'text-red-600' : 'text-slate-900'}`}>
                                                {(() => {
                                                    const hours = parseInt(form.data.hours) || 0;
                                                    const minutes = parseInt(form.data.minutes) || 0;
                                                    if (hours === 0 && minutes === 0) {
                                                        return 'No time entered';
                                                    }
                                                    return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}`;
                                                })()}
                                            </div>
                                            {hasTimeEntered() && (
                                                <div className="text-xs text-slate-500">
                                                    {(() => {
                                                        const hours = parseInt(form.data.hours) || 0;
                                                        const minutes = parseInt(form.data.minutes) || 0;
                                                        const totalHours = hours + (minutes / 60);
                                                        return `${totalHours.toFixed(2)} decimal hours`;
                                                    })()}
                                                </div>
                                            )}
                                            {!hasTimeEntered() && (
                                                <div className="text-xs text-red-600">
                                                    Please enter at least some hours or minutes
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Description */}
                                <div className="mb-6">
                                    <label htmlFor="description" className="block text-sm font-medium text-slate-700 mb-3 font-semibold">
                                        Description <span className="text-red-500">*</span>
                                    </label>
                                    <textarea
                                        id="description"
                                        rows={4}
                                        value={form.data.description}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                        className="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl focus:ring-2 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 text-slate-900 placeholder-slate-400"
                                        placeholder="Describe the work you did..."
                                        required
                                    />
                                </div>

                                <div className="flex items-center justify-between">
                                    <Link
                                        href={route('work-hours.index')}
                                        className="inline-flex items-center px-6 py-3 bg-white hover:bg-slate-50 text-slate-700 rounded-xl font-medium transition-all border border-slate-300"
                                    >
                                        <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                        </svg>
                                        Cancel
                                    </Link>
                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="inline-flex items-center rounded-lg bg-blue-600 px-5 py-2.5 font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
                                    >
                                        {form.processing ? (
                                            <>
                                                <svg className="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                Creating...
                                            </>
                                        ) : (
                                            <>
                                                <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                </svg>
                                                Create Entry
                                            </>
                                        )}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
            </PageShell>
        </AuthenticatedLayout>
    );
}
