import React from 'react';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler
} from 'chart.js';
import { Line } from 'react-chartjs-2';
import { useThemeName, themeAllowsGlow, withAlpha } from './theme';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler
);

// Per-chart glow plugin — passed via the <Line plugins> prop (NEVER registered
// globally, so it can't leak into other chart instances). Reads the theme at
// draw time off <html data-theme>: glow is a dark/black-only treatment.
const lineGlowPlugin = {
    id: 'staffvoraLineGlow',
    beforeDatasetDraw(chart, args) {
        if (!themeAllowsGlow()) return;
        const { ctx } = chart;
        ctx.save(); // always paired with the restore below (same synchronous draw)
        const dataset = chart.data.datasets[args.index];
        const color = dataset && typeof dataset.borderColor === 'string' ? dataset.borderColor : null;
        if (!color) return;
        ctx.shadowColor = color;
        ctx.shadowBlur = 10;
    },
    afterDatasetDraw(chart) {
        if (!themeAllowsGlow()) return;
        chart.ctx.restore();
    },
};

// Scriptable background: a vertical gradient from the dataset's line color
// (soft, at the top) fading to transparent at the bottom of the plot area.
function gradientFillFor(datasetColor) {
    return (context) => {
        const { ctx, chartArea } = context.chart;
        if (!chartArea) return 'transparent'; // first pass, before layout
        const color = typeof datasetColor === 'string' ? datasetColor : '#2563EB';
        const g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        g.addColorStop(0, withAlpha(color, 0.35));
        g.addColorStop(0.6, withAlpha(color, 0.10));
        g.addColorStop(1, withAlpha(color, 0));
        return g;
    };
}

/**
 * LineChart — Chart.js line chart wrapper.
 *
 * Additive props (existing `data`/`options`/`className` call sites unchanged):
 * - gradientFill {bool} Vertical line-color → transparent underfill per dataset.
 * - glow         {bool} Soft halo around each line in its own color.
 *                       Dark/black themes only — light stays crisp.
 */
export default function LineChart({ data, options = {}, className = "", gradientFill = false, glow = false }) {
    const theme = useThemeName(); // re-render (re-rasterize canvas paint) on theme flips

    const chartData = React.useMemo(() => {
        if (!gradientFill || !data || !Array.isArray(data.datasets)) return data;
        return {
            ...data,
            datasets: data.datasets.map((ds) => ({
                ...ds,
                fill: ds.fill ?? 'origin',
                backgroundColor: ds.backgroundColor ?? gradientFillFor(ds.borderColor),
            })),
        };
    }, [data, gradientFill, theme]);

    const plugins = React.useMemo(() => (glow ? [lineGlowPlugin] : []), [glow]);

    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    color: 'rgba(255, 255, 255, 0.8)',
                    font: {
                        family: 'Inter, sans-serif',
                        size: 12
                    }
                }
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                titleColor: 'white',
                bodyColor: 'white',
                borderColor: 'rgba(255, 255, 255, 0.1)',
                borderWidth: 1,
                cornerRadius: 8,
                titleFont: {
                    family: 'Inter, sans-serif',
                    size: 14,
                    weight: 'bold'
                },
                bodyFont: {
                    family: 'Inter, sans-serif',
                    size: 12
                }
            }
        },
        scales: {
            x: {
                grid: {
                    color: 'rgba(255, 255, 255, 0.1)',
                    borderColor: 'rgba(255, 255, 255, 0.2)'
                },
                ticks: {
                    color: 'rgba(255, 255, 255, 0.7)',
                    font: {
                        family: 'Inter, sans-serif',
                        size: 11
                    }
                }
            },
            y: {
                grid: {
                    color: 'rgba(255, 255, 255, 0.1)',
                    borderColor: 'rgba(255, 255, 255, 0.2)'
                },
                ticks: {
                    color: 'rgba(255, 255, 255, 0.7)',
                    font: {
                        family: 'Inter, sans-serif',
                        size: 11
                    }
                }
            }
        },
        ...options
    };

    return (
        <div className={`relative ${className}`}>
            <Line data={chartData} options={defaultOptions} plugins={plugins} />
        </div>
    );
}
