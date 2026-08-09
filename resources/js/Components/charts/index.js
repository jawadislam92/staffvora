// Staffvora chart kit — theme-aware, data-driven inline-SVG charts.
//   import { Sparkline, TrendChart, ActivityStrip, RankedBars, Gauge } from '@/Components/charts'
// All are prop-driven, dependency-free, and render graceful empty states.
export { default as Sparkline } from "./Sparkline";
export { default as TrendChart } from "./TrendChart";
export { default as ActivityStrip } from "./ActivityStrip";
export { default as RankedBars } from "./RankedBars";
export { default as Gauge } from "./Gauge";
export { default as GradientBar } from "./GradientBar";
// NOTE: LineChart / BarChart / DoughnutChart stay OUT of this barrel on purpose —
// they pull in Chart.js and are lazy-loaded (Components/LazyLoad.jsx); exporting
// them here would drag Chart.js into every page that imports the SVG kit.
