import React, { useState } from 'react';
import LineChart from './Charts/LineChart';
import BarChart from './Charts/BarChart';
import DoughnutChart from './Charts/DoughnutChart';

export default function EmployeeAnalytics({ analytics, user }) {
    const [activeTab, setActiveTab] = useState('today');

    // Handle case where analytics might be undefined or empty
    const safeAnalytics = analytics || {};
    
    // Default data for when no analytics are available
    const defaultSummary = {
        today: '0h',
        week: '0h',
        month: '0h'
    };

    const defaultMetrics = {
        avgDailyHours: '0',
        totalClients: 0,
        consistency: 0,
        productivity: 0
    };

    // Prepare chart data based on active tab
    const getChartData = () => {
        const today = safeAnalytics.today || {};
        const week = safeAnalytics.week || {};
        const month = safeAnalytics.month || {};

        switch (activeTab) {
            case 'today':
                const todayData = today.hourly || { labels: [], data: [] };
                if (!todayData.labels || todayData.labels.length === 0) {
                    return {
                        labels: ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00'],
                        datasets: [{
                            label: 'No Data Available',
                            data: new Array(11).fill(0),
                            borderColor: 'rgba(156, 163, 175, 0.5)',
                            backgroundColor: 'rgba(156, 163, 175, 0.1)',
                            borderWidth: 2,
                        }]
                    };
                }
                return {
                    labels: todayData.labels,
                    datasets: [
                        {
                            label: 'Hours Worked',
                            data: todayData.data,
                            borderColor: 'rgb(34, 197, 94)',
                            backgroundColor: 'rgba(34, 197, 94, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: 'rgb(34, 197, 94)',
                            pointBorderColor: 'white',
                            pointBorderWidth: 2,
                            pointRadius: 6,
                        }
                    ]
                };
            case 'week':
                const weekData = week.daily || { labels: [], data: [] };
                if (!weekData.labels || weekData.labels.length === 0) {
                    return {
                        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                        datasets: [{
                            label: 'No Data Available',
                            data: new Array(7).fill(0),
                            backgroundColor: ['rgba(156, 163, 175, 0.3)'],
                            borderColor: ['rgba(156, 163, 175, 0.5)'],
                            borderWidth: 2,
                        }]
                    };
                }
                return {
                    labels: weekData.labels,
                    datasets: [
                        {
                            label: 'Daily Hours',
                            data: weekData.data,
                            backgroundColor: weekData.data.map((_, index) => {
                                const colors = [
                                    'rgba(34, 197, 94, 0.8)',
                                    'rgba(59, 130, 246, 0.8)',
                                    'rgba(168, 85, 247, 0.8)',
                                    'rgba(245, 158, 11, 0.8)',
                                    'rgba(239, 68, 68, 0.8)',
                                    'rgba(16, 185, 129, 0.8)',
                                    'rgba(99, 102, 241, 0.8)'
                                ];
                                return colors[index % colors.length];
                            }),
                            borderColor: weekData.data.map((_, index) => {
                                const colors = [
                                    'rgb(34, 197, 94)',
                                    'rgb(59, 130, 246)',
                                    'rgb(168, 85, 247)',
                                    'rgb(245, 158, 11)',
                                    'rgb(239, 68, 68)',
                                    'rgb(16, 185, 129)',
                                    'rgb(99, 102, 241)'
                                ];
                                return colors[index % colors.length];
                            }),
                            borderWidth: 2,
                            borderRadius: 8,
                        }
                    ]
                };
            case 'month':
                const monthData = month.weekly || { labels: [], data: [] };
                if (!monthData.labels || monthData.labels.length === 0) {
                    return {
                        labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                        datasets: [{
                            label: 'No Data Available',
                            data: new Array(4).fill(0),
                            borderColor: 'rgba(156, 163, 175, 0.5)',
                            backgroundColor: 'rgba(156, 163, 175, 0.1)',
                            borderWidth: 2,
                        }]
                    };
                }
                return {
                    labels: monthData.labels,
                    datasets: [
                        {
                            label: 'Weekly Hours',
                            data: monthData.data,
                            borderColor: 'rgb(168, 85, 247)',
                            backgroundColor: 'rgba(168, 85, 247, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: 'rgb(168, 85, 247)',
                            pointBorderColor: 'white',
                            pointBorderWidth: 2,
                            pointRadius: 6,
                        }
                    ]
                };
        }
    };

    const getClientDistributionData = () => {
        const clientDist = safeAnalytics.clientDistribution || { labels: [], data: [] };
        
        if (!clientDist.labels || clientDist.labels.length === 0) {
            return {
                labels: ['No Data'],
                datasets: [{
                    data: [1],
                    backgroundColor: ['rgba(156, 163, 175, 0.3)'],
                    borderColor: ['rgba(156, 163, 175, 0.5)'],
                    borderWidth: 2,
                }]
            };
        }

        return {
            labels: clientDist.labels,
            datasets: [
                {
                    data: clientDist.data,
                    backgroundColor: [
                        'rgba(34, 197, 94, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(168, 85, 247, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(16, 185, 129, 0.8)',
                    ],
                    borderColor: [
                        'rgb(34, 197, 94)',
                        'rgb(59, 130, 246)',
                        'rgb(168, 85, 247)',
                        'rgb(245, 158, 11)',
                        'rgb(239, 68, 68)',
                        'rgb(16, 185, 129)',
                    ],
                    borderWidth: 2,
                }
            ]
        };
    };

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="text-center">
                <h2 className="text-2xl font-bold text-white mb-2">Your Work Analytics</h2>
                <p className="text-white/70">Track your productivity and work patterns</p>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="bg-gradient-to-br from-green-500/20 to-green-600/20 backdrop-blur-md p-6 rounded-xl border border-green-400/30">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-green-200 text-sm">Today</p>
                            <p className="text-2xl font-bold text-white">{safeAnalytics.summary?.today || defaultSummary.today}</p>
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
                            <p className="text-blue-200 text-sm">This Week</p>
                            <p className="text-2xl font-bold text-white">{safeAnalytics.summary?.week || defaultSummary.week}</p>
                        </div>
                        <div className="p-3 bg-blue-500/30 rounded-lg">
                            <svg className="w-6 h-6 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div className="bg-gradient-to-br from-purple-500/20 to-purple-600/20 backdrop-blur-md p-6 rounded-xl border border-purple-400/30">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-purple-200 text-sm">This Month</p>
                            <p className="text-2xl font-bold text-white">{safeAnalytics.summary?.month || defaultSummary.month}</p>
                        </div>
                        <div className="p-3 bg-purple-500/30 rounded-lg">
                            <svg className="w-6 h-6 text-purple-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            {/* Chart Section */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Main Chart */}
                <div className="lg:col-span-2">
                    <div className="bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl p-6 rounded-2xl border border-white/10">
                        {/* Tab Navigation */}
                        <div className="flex space-x-1 mb-6 p-1 bg-white/5 rounded-lg">
                            <button
                                onClick={() => setActiveTab('today')}
                                className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-all duration-200 ${
                                    activeTab === 'today'
                                        ? 'bg-green-500 text-white shadow-lg'
                                        : 'text-white/70 hover:text-white hover:bg-white/10'
                                }`}
                            >
                                Today
                            </button>
                            <button
                                onClick={() => setActiveTab('week')}
                                className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-all duration-200 ${
                                    activeTab === 'week'
                                        ? 'bg-blue-500 text-white shadow-lg'
                                        : 'text-white/70 hover:text-white hover:bg-white/10'
                                }`}
                            >
                                This Week
                            </button>
                            <button
                                onClick={() => setActiveTab('month')}
                                className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-all duration-200 ${
                                    activeTab === 'month'
                                        ? 'bg-purple-500 text-white shadow-lg'
                                        : 'text-white/70 hover:text-white hover:bg-white/10'
                                }`}
                            >
                                This Month
                            </button>
                        </div>

                        <div className="h-80">
                            {activeTab === 'week' ? (
                                <BarChart data={getChartData()} />
                            ) : (
                                <LineChart data={getChartData()} />
                            )}
                        </div>
                    </div>
                </div>

                {/* Client Distribution */}
                <div className="bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl p-6 rounded-2xl border border-white/10">
                    <h3 className="text-lg font-semibold text-white mb-4">Work Distribution</h3>
                    <div className="h-80">
                        <DoughnutChart data={getClientDistributionData()} />
                    </div>
                </div>
            </div>

            {/* Performance Metrics */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div className="bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl p-4 rounded-xl border border-white/10">
                    <div className="text-center">
                        <div className="text-2xl font-bold text-white">{safeAnalytics.metrics?.avgDailyHours || defaultMetrics.avgDailyHours}</div>
                        <div className="text-sm text-white/70">Avg Daily Hours</div>
                    </div>
                </div>
                <div className="bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl p-4 rounded-xl border border-white/10">
                    <div className="text-center">
                        <div className="text-2xl font-bold text-white">{safeAnalytics.metrics?.totalClients || defaultMetrics.totalClients}</div>
                        <div className="text-sm text-white/70">Active Clients</div>
                    </div>
                </div>
                <div className="bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl p-4 rounded-xl border border-white/10">
                    <div className="text-center">
                        <div className="text-2xl font-bold text-white">{safeAnalytics.metrics?.consistency || defaultMetrics.consistency}%</div>
                        <div className="text-sm text-white/70">Consistency</div>
                    </div>
                </div>
                <div className="bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl p-4 rounded-xl border border-white/10">
                    <div className="text-center">
                        <div className="text-2xl font-bold text-white">{safeAnalytics.metrics?.productivity || defaultMetrics.productivity}%</div>
                        <div className="text-sm text-white/70">Productivity</div>
                    </div>
                </div>
            </div>
        </div>
    );
}
