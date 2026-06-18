// SAMPLE: Beautiful Users List Page Header
// This shows how the updated design will look

<div className="bg-gradient-to-br from-slate-50 to-blue-50/30">
    <div className="px-6 lg:px-12 xl:px-16 py-8 space-y-8">
        
        {/* Header Section - Beautiful Card */}
        <div className="bg-white rounded-3xl shadow-xl p-6 md:p-8 border border-slate-100">
            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                {/* Title with Icon */}
                <div className="flex items-center gap-4">
                    <div className="p-4 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-2xl shadow-lg">
                        <Users className="w-8 h-8 text-white" />
                    </div>
                    <div>
                        <h1 className="text-3xl font-bold text-slate-800">Users</h1>
                        <p className="text-slate-600 mt-1">
                            Manage user accounts and permissions
                        </p>
                    </div>
                </div>
                
                {/* Add User Button */}
                <Link
                    href={route('users.create')}
                    className="flex items-center justify-center gap-2 px-6 py-2.5 bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white rounded-xl font-medium transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105"
                >
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add User
                </Link>
            </div>

            {/* Search and Filters */}
            <div className="mt-6 flex flex-col sm:flex-row gap-3">
                <div className="relative flex-1">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" />
                    <input
                        type="text"
                        placeholder="Search by name or email..."
                        value={searchTerm}
                        onChange={(e) => handleSearchChange(e.target.value)}
                        className="w-full pl-10 pr-4 py-2.5 rounded-xl border-2 border-slate-200 focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all duration-300"
                    />
                </div>
                <div className="relative">
                    <Filter className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" />
                    <select
                        value={selectedDesignation}
                        onChange={(e) => applyFilters({ designation: e.target.value })}
                        className="pl-10 pr-8 py-2.5 rounded-xl border-2 border-slate-200 focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all duration-300 appearance-none bg-white cursor-pointer"
                    >
                        <option value="all">All Designations</option>
                        <option value="developer">Developer</option>
                        <option value="designer">Designer</option>
                    </select>
                </div>
            </div>
        </div>

        {/* Content Section - Beautiful Table */}
        <div className="bg-white rounded-3xl shadow-xl p-6 md:p-8 border border-slate-100">
            <div className="overflow-x-auto">
                <table className="w-full">
                    <thead>
                        <tr className="border-b-2 border-slate-200">
                            <th className="text-left py-4 px-4 text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                ID
                            </th>
                            <th className="text-left py-4 px-4 text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                Avatar
                            </th>
                            <th className="text-left py-4 px-4 text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                Name
                            </th>
                            <th className="text-center py-4 px-3 text-xs font-semibold text-slate-600 uppercase tracking-wider bg-blue-50">
                                Email
                            </th>
                            <th className="text-center py-4 px-3 text-xs font-semibold text-slate-600 uppercase tracking-wider bg-emerald-50">
                                Designation
                            </th>
                            <th className="text-center py-4 px-3 text-xs font-semibold text-slate-600 uppercase tracking-wider bg-purple-50">
                                Role
                            </th>
                            <th className="text-center py-4 px-3 text-xs font-semibold text-slate-600 uppercase tracking-wider bg-amber-50">
                                Weekly Hours
                            </th>
                            <th className="text-center py-4 px-3 text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        <tr className="hover:bg-slate-50 transition-colors">
                            <td className="py-4 px-4 text-center">
                                <span className="text-blue-600 font-semibold">1</span>
                            </td>
                            <td className="py-4 px-4">
                                <div className="w-10 h-10 bg-gradient-to-br from-blue-500 to-cyan-500 rounded-full flex items-center justify-center shadow">
                                    <span className="text-white font-bold text-sm">A</span>
                                </div>
                            </td>
                            <td className="py-4 px-4">
                                <div className="font-semibold text-slate-800">Admin User</div>
                            </td>
                            <td className="py-4 px-3 text-center bg-blue-50/50">
                                <span className="text-slate-600">admin@example.com</span>
                            </td>
                            <td className="py-4 px-3 text-center bg-emerald-50/50">
                                <span className="inline-flex px-3 py-1 text-xs font-medium rounded-full bg-emerald-100 text-emerald-700">
                                    No Designation
                                </span>
                            </td>
                            <td className="py-4 px-3 text-center bg-purple-50/50">
                                <span className="inline-flex px-3 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-700">
                                    Admin
                                </span>
                            </td>
                            <td className="py-4 px-3 text-center bg-amber-50/50">
                                <span className="font-bold text-amber-700 text-lg">40.00</span>
                            </td>
                            <td className="py-4 px-3">
                                <div className="flex items-center justify-center gap-2">
                                    <button className="px-4 py-1.5 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium transition-all">
                                        Edit
                                    </button>
                                    <button className="px-4 py-1.5 bg-red-500 hover:bg-red-600 text-white rounded-lg text-sm font-medium transition-all">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
