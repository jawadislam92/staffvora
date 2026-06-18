import React, { useState } from 'react';
import LineChart from './Charts/LineChart';
import BarChart from './Charts/BarChart';
import DoughnutChart from './Charts/DoughnutChart';

export default function AdminAnalytics({ analytics, employees }) {
    const [activeTab, setActiveTab] = useState('daily');
    const [selectedEmployee, setSelectedEmployee] = useState('all');

    // Handle case where analytics might be undefined or empty
    const safeAnalytics = analytics || {};
    const safeEmployees = employees || [];

    // Default empty data structure
    const defaultData = { labels: [], data: [] };
    const defaultSummary = {
        totalToday: '0h',
        activeEmployees: 0,
        averageHours: '0h',
        teamEfficiency: 0
    };

    // Prepare chart data based on active tab and selected employee
    const getChartData = () => {
        const teamData = safeAnalytics.teamData || {};
        const employeeData = safeAnalytics.employeeData || {};
        
        const data = selectedEmployee === 'all' 
            ? teamData[activeTab] || defaultData
            : employeeData[selectedEmployee]?.[activeTab] || defaultData;

        // If no data available, show sample/empty data
        if (!data.labels || data.labels.length === 0) {
            const sampleLabels = activeTab === 'daily' 
                ? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
                : activeTab === 'weekly' 
                ? ['Week 1', 'Week 2', 'Week 3', 'Week 4']
                : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
            
            return {
                labels: sampleLabels,
                datasets: [{
                    label: 'No Data Available',
                    data: new Array(sampleLabels.length).fill(0),
                    borderColor: 'rgba(156, 163, 175, 0.5)',
                    backgroundColor: 'rgba(156, 163, 175, 0.1)',
                    borderWidth: 2,
                }]
            };
        }

        switch (activeTab) {
            case 'daily':
                return {
                    labels: data.labels,
                    datasets: selectedEmployee === 'all' ? [
                        {
                            label: 'Team Total Hours',
                            data: data.totalHours,
                            borderColor: 'rgb(34, 197, 94)',
                            backgroundColor: 'rgba(34, 197, 94, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                        },
                        {
                            label: 'Average per Employee',
                            data: data.averageHours,
                            borderColor: 'rgb(59, 130, 246)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                        }
                    ] : [
                        {
                            label: 'Daily Hours',
                            data: data.data,
                            borderColor: 'rgb(168, 85, 247)',
                            backgroundColor: 'rgba(168, 85, 247, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                        }
                    ]
                };
            case 'weekly':
                return {
                    labels: data.labels,
                    datasets: selectedEmployee === 'all' ? [
                        {
                            label: 'Team Weekly Hours',
                            data: data.data,
                            backgroundColor: data.data.map((_, index) => {
                                const colors = [
                                    'rgba(34, 197, 94, 0.8)',
                                    'rgba(59, 130, 246, 0.8)',
                                    'rgba(168, 85, 247, 0.8)',
                                    'rgba(245, 158, 11, 0.8)'
                                ];
                                return colors[index % colors.length];
                            }),
                            borderColor: data.data.map((_, index) => {
                                const colors = [
                                    'rgb(34, 197, 94)',
                                    'rgb(59, 130, 246)',
                                    'rgb(168, 85, 247)',
                                    'rgb(245, 158, 11)'
                                ];
                                return colors[index % colors.length];
                            }),
                            borderWidth: 2,
                            borderRadius: 8,
                        }
                    ] : [
                        {
                            label: 'Weekly Hours',
                            data: data.data,
                            backgroundColor: 'rgba(168, 85, 247, 0.8)',
                            borderColor: 'rgb(168, 85, 247)',
                            borderWidth: 2,
                            borderRadius: 8,
                        }
                    ]
                };
            case 'monthly':
                return {
                    labels: data.labels,
                    datasets: selectedEmployee === 'all' ? [
                        {
                            label: 'Team Monthly Hours',
                            data: data.data,
                            borderColor: 'rgb(245, 158, 11)',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                        }
                    ] : [
                        {
                            label: 'Monthly Hours',
                            data: data.data,
                            borderColor: 'rgb(239, 68, 68)',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                        }
                    ]
                };
        }
    };

    const getEmployeeComparisonData = () => {
        const comparison = safeAnalytics.employeeComparison || { labels: [], data: [] };
        
        if (!comparison.labels || comparison.labels.length === 0) {
            return {
                labels: safeEmployees.length > 0 ? safeEmployees.map(emp => emp.name) : ['No Data'],
                datasets: [{
                    label: 'Hours This Month',
                    data: safeEmployees.length > 0 ? new Array(safeEmployees.length).fill(0) : [0],
                    backgroundColor: ['rgba(156, 163, 175, 0.3)'],
                    borderColor: ['rgba(156, 163, 175, 0.5)'],
                    borderWidth: 2,
                }]
            };
        }

        return {
            labels: comparison.labels,
            datasets: [
                {
                    label: 'Hours This Month',
                    data: comparison.data,
                    backgroundColor: comparison.data.map((_, index) => {
                        const colors = [
                            'rgba(34, 197, 94, 0.8)',
                            'rgba(59, 130, 246, 0.8)',
                            'rgba(168, 85, 247, 0.8)',
                            'rgba(245, 158, 11, 0.8)',
                            'rgba(239, 68, 68, 0.8)',
                            'rgba(16, 185, 129, 0.8)',
                        ];
                        return colors[index % colors.length];
                    }),
                    borderColor: comparison.data.map((_, index) => {
                        const colors = [
                            'rgb(34, 197, 94)',
                            'rgb(59, 130, 246)',
                            'rgb(168, 85, 247)',
                            'rgb(245, 158, 11)',
                            'rgb(239, 68, 68)',
                            'rgb(16, 185, 129)',
                        ];
                        return colors[index % colors.length];
                    }),
                    borderWidth: 2,
                    borderRadius: 8,
                }
            ]
        };
    };

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="text-center">
                <h2 className="text-2xl font-bold text-white mb-2">Team Analytics Dashboard</h2>
                <p className="text-white/70">Monitor team productivity and individual performance</p>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div className="bg-gradient-to-br from-green-500/20 to-green-600/20 backdrop-blur-md p-6 rounded-xl border border-green-400/30">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-green-200 text-sm">Total Hours Today</p>
                            <p className="text-2xl font-bold text-white">{safeAnalytics.summary?.totalToday || defaultSummary.totalToday}</p>
                        </div>
                        <div className="p-3 bg-green-500/30 rounded-lg">
                            <svg className="w-6 h-6 text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div className="bg-gradient-to-br from-blue-500/20 to-blue-600/20 backdrop-blur-md p-6 rounded-xl border border-blue-400/30">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-blue-200 text-sm">Active Employees</p>
                            <p className="text-2xl font-bold text-white">{safeAnalytics.summary?.activeEmployees || defaultSummary.activeEmployees}</p>
                        </div>
                        <div className="p-3 bg-blue-500/30 rounded-lg">
                            <svg className="w-6 h-6 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div className="bg-gradient-to-br from-purple-500/20 to-purple-600/20 backdrop-blur-md p-6 rounded-xl border border-purple-400/30">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-purple-200 text-sm">Avg. Hours/Employee</p>
                            <p className="text-2xl font-bold text-white">{safeAnalytics.summary?.averageHours || defaultSummary.averageHours}</p>
                        </div>
                        <div className="p-3 bg-purple-500/30 rounded-lg">
                            <svg className="w-6 h-6 text-purple-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div className="bg-gradient-to-br from-yellow-500/20 to-yellow-600/20 backdrop-blur-md p-6 rounded-xl border border-yellow-400/30">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-yellow-200 text-sm">Team Efficiency</p>
                            <p className="text-2xl font-bold text-white">{safeAnalytics.summary?.teamEfficiency || defaultSummary.teamEfficiency}%</p>
                        </div>
                        <div className="p-3 bg-yellow-500/30 rounded-lg">
                            <svg className="w-6 h-6 text-yellow-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            {/* Controls */}
            <div className="bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl p-4 rounded-xl border border-white/10">
                <div className="flex flex-col md:flex-row gap-4 items-center justify-between">
                    {/* Time Period Tabs */}
                    <div className="flex space-x-1 p-1 bg-white/5 rounded-lg">
                        <button
                            onClick={() => setActiveTab('daily')}
                            className={`py-2 px-4 rounded-md text-sm font-medium transition-all duration-200 ${
                                activeTab === 'daily'
                                    ? 'bg-green-500 text-white shadow-lg'
                                    : 'text-white/70 hover:text-white hover:bg-white/10'
                            }`}
                        >
                            Daily
                        </button>
                        <button
                            onClick={() => setActiveTab('weekly')}
                            className={`py-2 px-4 rounded-md text-sm font-medium transition-all duration-200 ${
                                activeTab === 'weekly'
                                    ? 'bg-blue-500 text-white shadow-lg'
                                    : 'text-white/70 hover:text-white hover:bg-white/10'
                            }`}
                        >
                            Weekly
                        </button>
                        <button
                            onClick={() => setActiveTab('monthly')}
                            className={`py-2 px-4 rounded-md text-sm font-medium transition-all duration-200 ${
                                activeTab === 'monthly'
                                    ? 'bg-purple-500 text-white shadow-lg'
                                    : 'text-white/70 hover:text-white hover:bg-white/10'
                            }`}
                        >
                            Monthly
                        </button>
                    </div>

                    {/* Employee Filter */}
                    <div className="flex items-center space-x-3">
                        <label className="text-white/70 text-sm">View:</label>
                        <select
                            value={selectedEmployee}
                            onChange={(e) => setSelectedEmployee(e.target.value)}
                            className="bg-white/10 border border-white/20 rounded-lg px-3 py-2 text-white text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        >
                            <option value="all">All Employees</option>
                            {safeEmployees.map(employee => (
                                <option key={employee.id} value={employee.id}>
                                    {employee.name}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            {/* Charts Section */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Main Chart */}
                <div className="lg:col-span-2">
                    <div className="bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl p-6 rounded-2xl border border-white/10">
                        <h3 className="text-lg font-semibold text-white mb-4">
                            {selectedEmployee === 'all' ? 'Team Performance' : `${safeEmployees.find(e => e.id == selectedEmployee)?.name || 'Employee'} Performance`}
                        </h3>
                        <div className="h-80">
                            {activeTab === 'weekly' ? (
                                <BarChart data={getChartData()} />
                            ) : (
                                <LineChart data={getChartData()} />
                            )}
                        </div>
                    </div>
                </div>

                {/* Employee Comparison */}
                <div className="bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl p-6 rounded-2xl border border-white/10">
                    <h3 className="text-lg font-semibold text-white mb-4">Employee Comparison</h3>
                    <div className="h-80">
                        <BarChart 
                            data={getEmployeeComparisonData()} 
                            options={{
                                indexAxis: 'y',
                                scales: {
                                    x: {
                                        grid: {
                                            color: 'rgba(255, 255, 255, 0.1)',
                                        },
                                        ticks: {
                                            color: 'rgba(255, 255, 255, 0.7)',
                                        }
                                    },
                                    y: {
                                        grid: {
                                            color: 'rgba(255, 255, 255, 0.1)',
                                        },
                                        ticks: {
                                            color: 'rgba(255, 255, 255, 0.7)',
                                        }
                                    }
                                }
                            }}
                        />
                    </div>
                </div>
            </div>

            {/* Employee Performance Cards */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {(safeAnalytics.topPerformers || []).length > 0 ? (
                    safeAnalytics.topPerformers.map((employee, index) => (
                        <div key={employee.id} className="bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl p-4 rounded-xl border border-white/10">
                            <div className="flex items-center justify-between mb-3">
                                <div className="flex items-center space-x-3">
                                    <div className={`w-10 h-10 rounded-full flex items-center justify-center ${
                                        index === 0 ? 'bg-yellow-500' : 
                                        index === 1 ? 'bg-gray-400' : 
                                        index === 2 ? 'bg-orange-600' : 'bg-blue-500'
                                    }`}>
                                        <span className="text-white font-bold text-sm">#{index + 1}</span>
                                    </div>
                                    <div>
                                        <p className="text-white font-medium">{employee.name}</p>
                                        <p className="text-white/60 text-sm">{employee.designation}</p>
                                    </div>
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-4 text-center">
                                <div>
                                    <p className="text-lg font-bold text-white">{employee.hoursThisMonth}h</p>
                                    <p className="text-xs text-white/60">This Month</p>
                                </div>
                                <div>
                                    <p className="text-lg font-bold text-white">{employee.efficiency}%</p>
                                    <p className="text-xs text-white/60">Efficiency</p>
                                </div>
                            </div>
                        </div>
                    ))
                ) : (
                    <div className="col-span-full bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl p-8 rounded-xl border border-white/10 text-center">
                        <div className="text-white/70 text-lg mb-2">No Performance Data Available</div>
                        <div className="text-white/50 text-sm">Work hours data will appear here once employees start tracking time.</div>
                    </div>
                )}
            </div>
        </div>
    );
}
