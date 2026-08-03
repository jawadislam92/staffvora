import React, { useState } from 'react';
import LineChart from './Charts/LineChart';
import BarChart from './Charts/BarChart';
import DoughnutChart from './Charts/DoughnutChart';
import { Card, KpiCard, StatCard, Tabs } from '@/Components/ui';
import { useThemeName, textTokens, vizColor, vizPalette, cssVar, withAlpha } from './Charts/theme';
import { Clock, CalendarDays, CalendarRange } from 'lucide-react';

/**
 * EmployeeAnalytics — the personal analytics block (today/week/month hours,
 * client distribution, personal metrics).
 *
 * Visual language follows the dashboard rebuild: KpiCards on the cross-page
 * color contract (hours/duration → viz slot 1), token-driven surfaces, and
 * Chart.js series painted from the --viz tokens — never raw palette colors.
 * Data derivations are untouched.
 */

const PERIOD_TABS = [
    { key: 'today', label: 'Today' },
    { key: 'week', label: 'This Week' },
    { key: 'month', label: 'This Month' },
];

export default function EmployeeAnalytics({ analytics, user }) {
    const [activeTab, setActiveTab] = useState('today');

    // Re-render (and re-read the token values below) when the theme flips —
    // Chart.js rasterizes colors at draw time.
    const theme = useThemeName();
    const tokens = textTokens();
    const gridColor = withAlpha(cssVar('--border', '#1e2b45'), 0.8);
    const subtle = cssVar('--text-subtle', '#6b7793');

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
        plugins: { legend: { display: false } },
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }), [theme]);

    // Prepare chart data based on active tab. All of these series are HOURS,
    // so they all wear viz slot 1 (time/duration) per the color contract.
    const getChartData = () => {
        const today = safeAnalytics.today || {};
        const week = safeAnalytics.week || {};
        const month = safeAnalytics.month || {};

        switch (activeTab) {
            case 'today': {
                const todayData = today.hourly || { labels: [], data: [] };
                if (!todayData.labels || todayData.labels.length === 0) {
                    return {
                        labels: ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00'],
                        datasets: [{
                            label: 'No Data Available',
                            data: new Array(11).fill(0),
                            borderColor: withAlpha(subtle, 0.5),
                            backgroundColor: withAlpha(subtle, 0.1),
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
                            borderColor: vizColor(1),
                            borderWidth: 2.5,
                            tension: 0.4,
                            pointRadius: 3,
                            pointBackgroundColor: vizColor(1),
                        }
                    ]
                };
            }
            case 'week': {
                const weekData = week.daily || { labels: [], data: [] };
                if (!weekData.labels || weekData.labels.length === 0) {
                    return {
                        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                        datasets: [{
                            label: 'No Data Available',
                            data: new Array(7).fill(0),
                            backgroundColor: withAlpha(subtle, 0.3),
                        }]
                    };
                }
                // Weekday bars are TIME BUCKETS of one concept (hours), not
                // categorical entities — one hue (slot 1), never a rainbow.
                return {
                    labels: weekData.labels,
                    datasets: [
                        {
                            label: 'Daily Hours',
                            data: weekData.data,
                            backgroundColor: withAlpha(vizColor(1), 0.85),
                        }
                    ]
                };
            }
            case 'month': {
                const monthData = month.weekly || { labels: [], data: [] };
                if (!monthData.labels || monthData.labels.length === 0) {
                    return {
                        labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                        datasets: [{
                            label: 'No Data Available',
                            data: new Array(4).fill(0),
                            borderColor: withAlpha(subtle, 0.5),
                            backgroundColor: withAlpha(subtle, 0.1),
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
                            borderColor: vizColor(1),
                            borderWidth: 2.5,
                            tension: 0.4,
                            pointRadius: 3,
                            pointBackgroundColor: vizColor(1),
                        }
                    ]
                };
            }
        }
    };

    // Clients are ENTITIES → arcs wear the fixed categorical --viz slots in
    // order (never cycled); any client past slot 5 wears the subtle token.
    const hasClientData = (safeAnalytics.clientDistribution?.labels || []).length > 0;

    const getClientDistributionData = () => {
        const clientDist = safeAnalytics.clientDistribution || { labels: [], data: [] };

        if (!hasClientData) {
            return {
                labels: ['No Data'],
                datasets: [{
                    data: [1],
                    backgroundColor: [withAlpha(subtle, 0.3)],
                    borderWidth: 0,
                }]
            };
        }

        const palette = vizPalette();
        return {
            labels: clientDist.labels,
            datasets: [
                {
                    data: clientDist.data,
                    backgroundColor: clientDist.labels.map((_, i) => (i < palette.length ? palette[i] : subtle)),
                    borderWidth: 0,
                }
            ]
        };
    };

    // Natural-height donut (same pattern as the dashboard's time-breakdown
    // card): the canvas keeps its own aspect ratio and the HTML dots legend
    // flows below it instead of overflowing a fixed-height box.
    const donutOptions = React.useMemo(() => ({
        maintainAspectRatio: true,
        aspectRatio: 1.6,
        // canvas legend off in every case: the dots legend (or the caption
        // below, in the no-data case) carries the labels in HTML text tokens
        plugins: { legend: { display: false } },
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }), [theme]);

    return (
        <div className="space-y-6">
            {/* Header */}
            <div>
                <h2 className="text-xl font-semibold text-body mb-1">Your Work Analytics</h2>
                <p className="text-sm text-muted">Track your productivity and work patterns</p>
            </div>

            {/* Summary cards — all three are hours/duration, so all three wear
                viz slot 1 (the same concept wears the same accent everywhere). */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <KpiCard
                    accent={1}
                    icon={<Clock size={18} />}
                    label="Today"
                    value={safeAnalytics.summary?.today || defaultSummary.today}
                />
                <KpiCard
                    accent={1}
                    icon={<CalendarDays size={18} />}
                    label="This Week"
                    value={safeAnalytics.summary?.week || defaultSummary.week}
                />
                <KpiCard
                    accent={1}
                    icon={<CalendarRange size={18} />}
                    label="This Month"
                    value={safeAnalytics.summary?.month || defaultSummary.month}
                />
            </div>

            {/* Chart Section */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Main Chart */}
                <Card className="lg:col-span-2">
                    {/* Tab Navigation */}
                    <div className="mb-6">
                        <Tabs
                            tabs={PERIOD_TABS}
                            value={activeTab}
                            onChange={setActiveTab}
                            size="sm"
                            aria-label="Analytics period"
                        />
                    </div>

                    <div className="h-80">
                        {activeTab === 'week' ? (
                            <BarChart
                                data={getChartData()}
                                options={axisOptions}
                                roundedTops
                                valueLabels
                                className="h-full"
                            />
                        ) : (
                            <LineChart
                                data={getChartData()}
                                options={axisOptions}
                                gradientFill
                                glow
                                className="h-full"
                            />
                        )}
                    </div>
                </Card>

                {/* Client Distribution */}
                <Card title="Work Distribution" description="Time by client">
                    <DoughnutChart
                        data={getClientDistributionData()}
                        options={donutOptions}
                        legend={hasClientData ? 'dots' : undefined}
                    />
                    {!hasClientData ? (
                        <p className="mt-3 text-xs text-subtle text-center">
                            No client data yet — the split appears once time is logged.
                        </p>
                    ) : null}
                </Card>
            </div>

            {/* Performance Metrics — dense secondary tiles stay StatCard. */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <StatCard
                    label="Avg Daily Hours"
                    value={safeAnalytics.metrics?.avgDailyHours || defaultMetrics.avgDailyHours}
                />
                <StatCard
                    label="Active Clients"
                    value={safeAnalytics.metrics?.totalClients || defaultMetrics.totalClients}
                />
                <StatCard
                    label="Consistency"
                    value={`${safeAnalytics.metrics?.consistency || defaultMetrics.consistency}%`}
                />
                <StatCard
                    label="Productivity"
                    value={`${safeAnalytics.metrics?.productivity || defaultMetrics.productivity}%`}
                />
            </div>
        </div>
    );
}
