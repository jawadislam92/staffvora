import React from 'react';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js';
import { Bar } from 'react-chartjs-2';
import { useThemeName, textTokens, vizPalette, cssVar } from './theme';

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    Title,
    Tooltip,
    Legend
);

// Per-chart value-label plugin (passed via the <Bar plugins> prop, never
// registered globally). Selective by design: labels print ONLY when the chart
// has <= 8 bars — beyond that they collide and the tooltip carries the value.
const MAX_LABELED_BARS = 8;
const valueLabelsPlugin = {
    id: 'staffvoraBarValueLabels',
    afterDatasetsDraw(chart) {
        const { ctx } = chart;
        const horizontal = chart.options.indexAxis === 'y';
        const tokens = textTokens();
        chart.data.datasets.forEach((dataset, di) => {
            const meta = chart.getDatasetMeta(di);
            if (meta.hidden || !meta.data || meta.data.length > MAX_LABELED_BARS) return;
            ctx.save();
            ctx.fillStyle = tokens.muted; // values wear text tokens, never the series color
            ctx.font = "600 11px Inter, sans-serif";
            meta.data.forEach((bar, i) => {
                const raw = dataset.data[i];
                const v = typeof raw === 'number' ? raw : raw && typeof raw === 'object' ? raw.y ?? raw.x : null;
                if (v == null || Number.isNaN(v)) return;
                const label = Number.isInteger(v) ? String(v) : v.toFixed(1);
                if (horizontal) {
                    ctx.textAlign = 'left';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(label, bar.x + 6, bar.y);
                } else {
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(label, bar.x, bar.y - 4);
                }
            });
            ctx.restore();
        });
    },
};

/**
 * BarChart — Chart.js bar chart wrapper.
 *
 * Additive props (existing `data`/`options`/`className` call sites unchanged):
 * - valueLabels {bool} Print each bar's value above it (text-muted token) —
 *                      selective: only when the series has <= 8 bars.
 * - roundedTops {bool} Round the outer end of each bar.
 * - vizColors   {bool} Per-bar fills from the categorical --viz slots, assigned
 *                      in fixed slot order (never cycled); bars past slot 5
 *                      wear the neutral subtle token.
 */
export default function BarChart({ data, options = {}, className = "", valueLabels = false, roundedTops = false, vizColors = false }) {
    const theme = useThemeName(); // re-rasterize canvas paint on theme flips

    const chartData = React.useMemo(() => {
        if ((!roundedTops && !vizColors) || !data || !Array.isArray(data.datasets)) return data;
        const palette = vizColors ? vizPalette() : null;
        const neutral = vizColors ? cssVar('--text-subtle', '#6b7793') : null;
        return {
            ...data,
            datasets: data.datasets.map((ds) => ({
                ...ds,
                ...(roundedTops ? { borderRadius: ds.borderRadius ?? 6, borderSkipped: ds.borderSkipped ?? 'start' } : {}),
                ...(vizColors
                    ? {
                          backgroundColor: (Array.isArray(ds.data) ? ds.data : []).map(
                              (_, i) => (i < palette.length ? palette[i] : neutral)
                          ),
                      }
                    : {}),
            })),
        };
    }, [data, roundedTops, vizColors, theme]);

    const plugins = React.useMemo(() => (valueLabels ? [valueLabelsPlugin] : []), [valueLabels]);

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

    if (valueLabels && !defaultOptions.layout) {
        // headroom so labels above the tallest bar don't clip at the canvas edge
        defaultOptions.layout = { padding: { top: 18 } };
    }

    return (
        <div className={`relative ${className}`}>
            <Bar data={chartData} options={defaultOptions} plugins={plugins} />
        </div>
    );
}
