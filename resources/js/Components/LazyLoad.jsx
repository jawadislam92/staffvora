import React, { Suspense, lazy } from 'react';

// Loading fallback component
const LoadingFallback = ({ message = "Loading..." }) => (
    <div className="flex items-center justify-center p-8">
        <div className="flex flex-col items-center gap-3">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <p className="text-sm text-gray-600 dark:text-gray-400">{message}</p>
        </div>
    </div>
);

// Lazy load chart components for better performance
export const LazyLineChart = lazy(() => import('./Charts/LineChart'));
export const LazyBarChart = lazy(() => import('./Charts/BarChart'));
export const LazyDoughnutChart = lazy(() => import('./Charts/DoughnutChart'));

// Wrapper component for charts with suspense
export const ChartWrapper = ({ component: Component, ...props }) => (
    <Suspense fallback={<LoadingFallback message="Loading chart..." />}>
        <Component {...props} />
    </Suspense>
);

// Lazy load analytics components
export const LazyEmployeeAnalytics = lazy(() => import('./EmployeeAnalytics'));
export const LazyAdminAnalytics = lazy(() => import('./AdminAnalytics'));

// Wrapper for analytics
export const AnalyticsWrapper = ({ component: Component, ...props }) => (
    <Suspense fallback={<LoadingFallback message="Loading analytics..." />}>
        <Component {...props} />
    </Suspense>
);

// Export a helper to lazy load any component
export const lazyLoad = (importFunc, fallbackMessage) => {
    const LazyComponent = lazy(importFunc);
    
    return (props) => (
        <Suspense fallback={<LoadingFallback message={fallbackMessage || "Loading..."} />}>
            <LazyComponent {...props} />
        </Suspense>
    );
};

export default {
    LazyLineChart,
    LazyBarChart,
    LazyDoughnutChart,
    LazyEmployeeAnalytics,
    LazyAdminAnalytics,
    ChartWrapper,
    AnalyticsWrapper,
    lazyLoad,
    LoadingFallback
};
