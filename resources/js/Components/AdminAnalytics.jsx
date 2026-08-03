import React, { useState } from 'react';
import LineChart from './Charts/LineChart';
import BarChart from './Charts/BarChart';
import { Card, KpiCard, Tabs, Select, EmptyState } from '@/Components/ui';
import { useThemeName, textTokens, vizColor, cssVar, withAlpha } from './Charts/theme';
import { Clock, Users, BarChart3, Zap } from 'lucide-react';

/**
 * AdminAnalytics — the team analytics block (daily/weekly/monthly hours,
 * employee comparison, top performers).
 *
 * Visual language follows the dashboard rebuild: KpiCards on the cross-page
 * color contract (hours → viz slot 1, people → slot 3, rates → slot 2),
 * token-driven surfaces, and Chart.js series painted from the --viz tokens —
 * never raw palette colors. Data derivations are untouched.
 */

const PERIOD_TABS = [
    { key: 'daily', label: 'Daily' },
    { key: 'weekly', label: 'Weekly' },
    { key: 'monthly', label: 'Monthly' },
];

export default function AdminAnalytics({ analytics, employees }) {
    const [activeTab, setActiveTab] = useState('daily');
    const [selectedEmployee, setSelectedEmployee] = useState('all');

    // Re-render (and re-read the token values below) when the theme flips —
    // Chart.js rasterizes colors at draw time.
    const theme = useThemeName();
    const tokens = textTokens();
    const gridColor = withAlpha(cssVar('--border', '#1e2b45'), 0.8);
    const subtle = cssVar('--text-subtle', '#6b7793');

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

    // Shared scale/tooltip options — text wears the theme's text tokens, grid
    // wears the border token. Recomputed on theme flips via `theme` above.
    const axisOptions = React.useMemo(() => ({
        scales: {
            x: {
                grid: { color: gridColor },
                ticks: { color: tokens.muted, font: { family: 'Inter, sans-serif', size: 11 } },
            },
            y: {
                grid: { color: gridColor },
                ticks: { color: tokens.muted, font: { family: 'Inter, sans-serif', size: 11 } },
            },
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }), [theme]);

    const lineOptions = React.useMemo(() => ({
        ...axisOptions,
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    color: tokens.muted,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    boxWidth: 6,
                    font: { family: 'Inter, sans-serif', size: 12 },
                },
            },
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }), [axisOptions]);

    const barOptions = React.useMemo(() => ({
        ...axisOptions,
        plugins: { legend: { display: false } },
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }), [axisOptions]);

    const comparisonOptions = React.useMemo(() => ({
        indexAxis: 'y',
        ...axisOptions,
        plugins: { legend: { display: false } },
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }), [axisOptions]);

    // Prepare chart data based on active tab and selected employee.
    // Series colors follow the contract: hours/duration wear viz slot 1
    // (violet); the derived average-per-employee rate wears slot 2 (green).
    const getChartData = () => {
        const teamData = safeAnalytics.teamData || {};
        const employeeData = safeAnalytics.employeeData || {};

        const data = selectedEmployee === 'all'
            ? teamData[activeTab] || defaultData
            : employeeData[selectedEmployee]?.[activeTab] || defaultData;

        // If no data available, show sample/empty data (neutral subtle token —
        // an empty series must not wear a real series color)
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
                    borderColor: withAlpha(subtle, 0.5),
                    backgroundColor: withAlpha(subtle, 0.1),
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
                            borderColor: vizColor(1),
                            borderWidth: 2.5,
                            tension: 0.4,
                            pointRadius: 2.5,
                            pointBackgroundColor: vizColor(1),
                        },
                        {
                            label: 'Average per Employee',
                            data: data.averageHours,
                            borderColor: vizColor(2),
                            borderWidth: 2.5,
                            tension: 0.4,
                            pointRadius: 2.5,
                            pointBackgroundColor: vizColor(2),
                        }
                    ] : [
                        {
                            label: 'Daily Hours',
                            data: data.data,
                            borderColor: vizColor(1),
                            borderWidth: 2.5,
                            tension: 0.4,
                            pointRadius: 2.5,
                            pointBackgroundColor: vizColor(1),
                        }
                    ]
                };
            case 'weekly':
                // Weekly bars are TIME BUCKETS of one concept (hours), not
                // categorical entities — one hue (slot 1), never a rainbow.
                return {
                    labels: data.labels,
                    datasets: [
                        {
                            label: selectedEmployee === 'all' ? 'Team Weekly Hours' : 'Weekly Hours',
                            data: data.data,
                            backgroundColor: withAlpha(vizColor(1), 0.85),
                        }
                    ]
                };
            case 'monthly':
                return {
                    labels: data.labels,
                    datasets: [
                        {
                            label: selectedEmployee === 'all' ? 'Team Monthly Hours' : 'Monthly Hours',
                            data: data.data,
                            borderColor: vizColor(1),
                            borderWidth: 2.5,
                            tension: 0.4,
                            pointRadius: 2.5,
                            pointBackgroundColor: vizColor(1),
                        }
                    ]
                };
        }
    };

    // People are entities → the BarChart `vizColors` prop assigns the fixed
    // categorical slots per bar (extras past slot 5 wear the subtle token).
    const getEmployeeComparisonData = () => {
        const comparison = safeAnalytics.employeeComparison || { labels: [], data: [] };

        if (!comparison.labels || comparison.labels.length === 0) {
            return {
                labels: safeEmployees.length > 0 ? safeEmployees.map(emp => emp.name) : ['No Data'],
                datasets: [{
                    label: 'Hours This Month',
                    data: safeEmployees.length > 0 ? new Array(safeEmployees.length).fill(0) : [0],
                    backgroundColor: withAlpha(subtle, 0.3),
                }]
            };
        }

        return {
            labels: comparison.labels,
            datasets: [
                {
                    label: 'Hours This Month',
                    data: comparison.data,
                }
            ]
        };
    };

    const hasComparisonData = (safeAnalytics.employeeComparison?.labels || []).length > 0;

    return (
        <div className="space-y-6">
            {/* Header */}
            <div>
                <h2 className="text-xl font-semibold text-body mb-1">Team Analytics</h2>
                <p className="text-sm text-muted">Monitor team productivity and individual performance</p>
            </div>

            {/* Summary cards — the color contract: hours → 1, people → 3,
                avg hours (still a duration) → 1, efficiency rate → 2. */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <KpiCard
                    accent={1}
                    icon={<Clock size={18} />}
                    label="Total Hours Today"
                    value={safeAnalytics.summary?.totalToday || defaultSummary.totalToday}
                />
                <KpiCard
                    accent={3}
                    icon={<Users size={18} />}
                    label="Active Employees"
                    value={safeAnalytics.summary?.activeEmployees || defaultSummary.activeEmployees}
                />
                <KpiCard
                    accent={1}
                    icon={<BarChart3 size={18} />}
                    label="Avg. Hours/Employee"
                    value={safeAnalytics.summary?.averageHours || defaultSummary.averageHours}
                />
                <KpiCard
                    accent={2}
                    icon={<Zap size={18} />}
                    label="Team Efficiency"
                    value={`${safeAnalytics.summary?.teamEfficiency || defaultSummary.teamEfficiency}%`}
                />
            </div>

            {/* Controls */}
            <Card padded={false}>
                <div className="flex flex-col md:flex-row gap-4 items-center justify-between p-4">
                    <Tabs
                        tabs={PERIOD_TABS}
                        value={activeTab}
                        onChange={setActiveTab}
                        size="sm"
                        aria-label="Analytics period"
                    />

                    {/* Employee Filter */}
                    <div className="flex items-center gap-3">
                        <label htmlFor="admin-analytics-employee" className="text-sm text-muted shrink-0">View:</label>
                        <Select
                            id="admin-analytics-employee"
                            value={selectedEmployee}
                            onChange={(e) => setSelectedEmployee(e.target.value)}
                        >
                            <option value="all">All Employees</option>
                            {safeEmployees.map(employee => (
                                <option key={employee.id} value={employee.id}>
                                    {employee.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>
            </Card>

            {/* Charts Section */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Main Chart */}
                <Card
                    className="lg:col-span-2"
                    title={selectedEmployee === 'all' ? 'Team Performance' : `${safeEmployees.find(e => e.id == selectedEmployee)?.name || 'Employee'} Performance`}
                >
                    <div className="h-80">
                        {activeTab === 'weekly' ? (
                            <BarChart
                                data={getChartData()}
                                options={barOptions}
                                roundedTops
                                valueLabels
                                className="h-full"
                            />
                        ) : (
                            <LineChart
                                data={getChartData()}
                                options={lineOptions}
                                gradientFill
                                glow
                                className="h-full"
                            />
                        )}
                    </div>
                </Card>

                {/* Employee Comparison */}
                <Card title="Employee Comparison" description="Hours this month">
                    <div className="h-80">
                        <BarChart
                            data={getEmployeeComparisonData()}
                            options={comparisonOptions}
                            roundedTops
                            valueLabels
                            vizColors={hasComparisonData}
                            className="h-full"
                        />
                    </div>
                </Card>
            </div>

            {/* Employee Performance Cards */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {(safeAnalytics.topPerformers || []).length > 0 ? (
                    safeAnalytics.topPerformers.map((employee, index) => (
                        <div key={employee.id} className="bg-surface border border-line rounded-xl p-4">
                            <div className="flex items-center justify-between mb-3">
                                <div className="flex items-center gap-3">
                                    <div className="w-10 h-10 rounded-full flex items-center justify-center bg-surface-2">
                                        <span className="text-body font-bold text-sm tabular-nums">#{index + 1}</span>
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-body font-medium truncate">{employee.name}</p>
                                        <p className="text-muted text-sm truncate">{employee.designation}</p>
                                    </div>
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-4 text-center">
                                <div>
                                    <p className="text-lg font-bold text-body tabular-nums">{employee.hoursThisMonth}h</p>
                                    <p className="text-xs text-muted">This Month</p>
                                </div>
                                <div>
                                    <p className="text-lg font-bold text-body tabular-nums">{employee.efficiency}%</p>
                                    <p className="text-xs text-muted">Efficiency</p>
                                </div>
                            </div>
                        </div>
                    ))
                ) : (
                    <div className="col-span-full">
                        <Card>
                            <EmptyState
                                title="No Performance Data Available"
                                description="Work hours data will appear here once employees start tracking time."
                            />
                        </Card>
                    </div>
                )}
            </div>
        </div>
    );
}
