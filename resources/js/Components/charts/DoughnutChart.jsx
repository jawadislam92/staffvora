import React from 'react';
import {
    Chart as ChartJS,
    ArcElement,
    Tooltip,
    Legend,
} from 'chart.js';
import { Doughnut } from 'react-chartjs-2';
import { useThemeName, textTokens } from './theme';

ChartJS.register(ArcElement, Tooltip, Legend);

// Per-chart centre-text plugin factory (passed via the <Doughnut plugins> prop,
// never registered globally, so it can't leak into other chart instances).
// Text wears the theme's text tokens, read at draw time.
function centerTextPlugin(centerValue, centerLabel) {
    return {
        id: 'staffvoraDoughnutCenter',
        afterDraw(chart) {
            const { ctx, chartArea } = chart;
            if (!chartArea) return;
            const x = (chartArea.left + chartArea.right) / 2;
            const y = (chartArea.top + chartArea.bottom) / 2;
            const tokens = textTokens();
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            if (centerValue != null) {
                ctx.fillStyle = tokens.body;
                ctx.font = "700 22px Inter, sans-serif";
                ctx.fillText(String(centerValue), x, centerLabel != null ? y - 8 : y);
            }
            if (centerLabel != null) {
                ctx.fillStyle = tokens.muted;
                ctx.font = "400 12px Inter, sans-serif";
                ctx.fillText(String(centerLabel), x, centerValue != null ? y + 14 : y);
            }
            ctx.restore();
        },
    };
}

const arcColor = (bg, i) => (Array.isArray(bg) ? bg[i % bg.length] : bg);

/**
 * DoughnutChart — Chart.js doughnut wrapper.
 *
 * Additive props (existing `data`/`options`/`className` call sites unchanged):
 * - centerValue {string|number} Big total drawn in the ring centre (text-body token).
 * - centerLabel {string}        Small caption under the value (text-muted token).
 * - legend      {"dots"}        Replaces the canvas legend with an HTML dot-legend:
 *                               name + % + count. Text wears text tokens; only the
 *                               dot carries the series color.
 */
export default function DoughnutChart({ data, options = {}, className = "", centerValue, centerLabel, legend }) {
    const theme = useThemeName(); // re-rasterize the canvas centre text on theme flips
    const dotsLegend = legend === 'dots';
    const hasCenter = centerValue != null || centerLabel != null;

    const plugins = React.useMemo(
        () => (hasCenter ? [centerTextPlugin(centerValue, centerLabel)] : []),
        [hasCenter, centerValue, centerLabel, theme]
    );

    // Dot-legend rows: label + percentage + raw count from the first dataset.
    const legendRows = React.useMemo(() => {
        if (!dotsLegend || !data || !Array.isArray(data.labels)) return [];
        const ds = Array.isArray(data.datasets) ? data.datasets[0] : null;
        const values = ds && Array.isArray(ds.data) ? ds.data : [];
        const total = values.reduce((s, v) => s + (typeof v === 'number' && !Number.isNaN(v) ? v : 0), 0);
        return data.labels.map((label, i) => {
            const v = typeof values[i] === 'number' && !Number.isNaN(values[i]) ? values[i] : 0;
            return {
                label,
                count: v,
                pct: total > 0 ? Math.round((v / total) * 1000) / 10 : 0,
                color: ds ? arcColor(ds.backgroundColor, i) : undefined,
            };
        });
    }, [dotsLegend, data]);

    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    color: 'rgba(255, 255, 255, 0.8)',
                    font: {
                        family: 'Inter, sans-serif',
                        size: 12
                    },
                    padding: 20,
                    usePointStyle: true,
                    pointStyle: 'circle'
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
        cutout: '60%',
        ...options
    };

    if (dotsLegend) {
        // Hide the canvas legend — the HTML dot-legend below carries it instead.
        defaultOptions.plugins = {
            ...defaultOptions.plugins,
            legend: { ...defaultOptions.plugins.legend, display: false },
        };
    }
    if (hasCenter && defaultOptions.cutout === '60%') {
        defaultOptions.cutout = '68%'; // thicker-looking ring with room for the total
    }

    return (
        <div className={`relative ${className}`}>
            <Doughnut data={data} options={defaultOptions} plugins={plugins} />
            {dotsLegend && legendRows.length > 0 ? (
                <ul className="mt-3 flex flex-col gap-1.5">
                    {legendRows.map((row, i) => (
                        <li key={i} className="flex items-center gap-2 text-xs">
                            <span
                                aria-hidden="true"
                                className="h-2 w-2 rounded-full shrink-0"
                                style={{ backgroundColor: row.color }}
                            />
                            <span className="text-body truncate">{row.label}</span>
                            <span className="ml-auto text-muted tabular-nums shrink-0">
                                {row.pct}% ({row.count})
                            </span>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
