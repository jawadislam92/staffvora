import React from "react";
import { EmptyState } from "@/Components/ui";
import { useThemeName, themeAllowsGlow } from "./theme";

const cx = (...a) => a.filter(Boolean).join(" ");

const clampSlot = (n) => Math.min(5, Math.max(1, Number(n) || 1));

/**
 * Gauge — a semicircular arc dial for a single 0..100 reading (activity,
 * utilization, billable share). Added for Insights; pure inline SVG, no
 * dependency, theme-aware.
 *
 * The arc is drawn in three parts:
 *   1. a full-sweep TRACK in bg-surface-2,
 *   2. optional THRESHOLD ticks so the reading has a scale to be read against
 *      (a bare percentage is not an insight),
 *   3. the VALUE sweep, whose colour follows the band the value falls in.
 *
 * A null/undefined value is a first-class state, not zero: "we have no signal"
 * and "the signal is 0%" are different facts and must not look the same.
 *
 * Props:
 * - value      {number|null}  0..100. `null` renders the no-data state.
 * - label      {node}         Caption under the number.
 * - unit       {string}       Suffix on the number. Default "%".
 * - bands      {[{from:number, tone:"success"|"warning"|"danger"|"info"|"accent"}]}
 *                             Colour bands, ascending by `from`. The value takes
 *                             the tone of the highest band it reaches.
 * - thresholds {number[]}     Tick marks drawn on the track (0..100).
 * - accent     {1..5}         Paint the value sweep from the categorical --viz
 *                             slot instead of the semantic band tone. Additive:
 *                             omitted → legacy band coloring, unchanged.
 * - glow       {bool}         Soft halo around the value sweep in its own
 *                             color. Dark/black themes only — light stays
 *                             crisp. Default false (legacy).
 * - size       {number}       Width in px of the drawing box. Default 220.
 * - caption    {node}         Small explanatory line under the label.
 * - emptyTitle {string}       Heading for the no-data state.
 * - emptyDescription {string} Body for the no-data state.
 * - className  {string}
 */

// Semantic tone -> the stroke class used for the value sweep. Semantic tokens
// only; no raw palette colours anywhere in this file.
const TONE_STROKE = {
  success: "text-success",
  warning: "text-warning",
  danger: "text-danger",
  info: "text-info",
  accent: "text-accent",
};

const DEFAULT_BANDS = [
  { from: 0, tone: "danger" },
  { from: 30, tone: "warning" },
  { from: 50, tone: "success" },
];

export default function Gauge({
  value,
  label,
  unit = "%",
  bands = DEFAULT_BANDS,
  thresholds = [],
  accent,
  glow = false,
  size = 220,
  caption,
  emptyTitle = "No reading yet",
  emptyDescription = "This dial appears once there is data to measure.",
  className,
  ...rest
}) {
  // Hook before any early return (rules of hooks) — the no-data state must not
  // change the hook order between renders.
  const theme = useThemeName();
  const hasValue = typeof value === "number" && !Number.isNaN(value);

  if (!hasValue) {
    return (
      <div className={cx("w-full", className)} {...rest}>
        <EmptyState title={emptyTitle} description={emptyDescription} />
      </div>
    );
  }

  const pct = Math.max(0, Math.min(100, value));

  // Geometry: a 180° arc from left to right across a viewBox twice as wide as
  // it is tall, with room beneath for the number.
  const W = 200;
  const H = 132;
  const cxp = 100;
  const cyp = 104;
  const r = 84;
  const stroke = 14;

  const pointAt = (p) => {
    const angle = Math.PI * (1 - p / 100);
    return [cxp + r * Math.cos(angle), cyp - r * Math.sin(angle)];
  };

  const arcTo = (p) => {
    const [x0, y0] = pointAt(0);
    const [x1, y1] = pointAt(p);
    return `M${x0.toFixed(2)},${y0.toFixed(2)} A${r},${r} 0 ${p > 50 ? 1 : 0} 1 ${x1.toFixed(2)},${y1.toFixed(2)}`;
  };

  const sorted = [...bands].sort((a, b) => a.from - b.from);
  const band = sorted.reduce(
    (acc, b) => (pct >= b.from ? b : acc),
    sorted[0] || { tone: "accent" }
  );
  const toneClass = TONE_STROKE[band.tone] || TONE_STROKE.accent;

  // Categorical accent (additive): the sweep wears the --viz slot instead of
  // the band tone. Glow is dark/black-only, in the sweep's own color either way.
  const hasAccent = accent != null;
  const drawGlow = glow && themeAllowsGlow(theme);
  const sweepClass = hasAccent ? undefined : toneClass;
  const sweepStyle =
    hasAccent || drawGlow
      ? {
          ...(hasAccent ? { color: `var(--viz-${clampSlot(accent)})` } : null),
          ...(drawGlow ? { filter: "drop-shadow(0 0 6px currentColor)" } : null),
        }
      : undefined;

  return (
    <div className={cx("w-full flex flex-col items-center", className)} {...rest}>
      <svg
        viewBox={`0 0 ${W} ${H}`}
        width={size}
        className="block max-w-full h-auto"
        role="img"
        aria-label={`${label || "Gauge"}: ${Math.round(pct)}${unit}`}
      >
        {/* track */}
        <path
          d={arcTo(100)}
          fill="none"
          stroke="currentColor"
          className="text-subtle"
          strokeWidth={stroke}
          strokeLinecap="round"
          opacity="0.28"
        />

        {/* threshold ticks — the scale the reading is judged against */}
        {thresholds
          .filter((t) => typeof t === "number" && t > 0 && t < 100)
          .map((t, i) => {
            const angle = Math.PI * (1 - t / 100);
            const inner = r - stroke / 2 - 2;
            const outer = r + stroke / 2 + 2;
            return (
              <line
                key={i}
                x1={cxp + inner * Math.cos(angle)}
                y1={cyp - inner * Math.sin(angle)}
                x2={cxp + outer * Math.cos(angle)}
                y2={cyp - outer * Math.sin(angle)}
                stroke="currentColor"
                className="text-muted"
                strokeWidth="1.5"
                strokeLinecap="round"
                opacity="0.7"
              />
            );
          })}

        {/* value sweep — hidden at exactly 0 so a round cap can't imply a
            reading that isn't there */}
        {pct > 0 ? (
          <path
            d={arcTo(pct)}
            fill="none"
            stroke="currentColor"
            className={sweepClass}
            style={sweepStyle}
            strokeWidth={stroke}
            strokeLinecap="round"
          />
        ) : null}

        <text
          x={cxp}
          y={cyp - 16}
          textAnchor="middle"
          fill="currentColor"
          className="text-body"
          fontSize="34"
          fontWeight="700"
        >
          {`${Math.round(pct)}${unit}`}
        </text>

        {/* end labels so the sweep direction is unambiguous */}
        <text x={cxp - r} y={cyp + 18} textAnchor="middle" fill="currentColor" className="text-subtle" fontSize="10">
          0
        </text>
        <text x={cxp + r} y={cyp + 18} textAnchor="middle" fill="currentColor" className="text-subtle" fontSize="10">
          100
        </text>
      </svg>

      {label ? <div className="mt-1 text-sm font-medium text-body">{label}</div> : null}
      {caption ? <p className="mt-1 text-xs text-muted text-center max-w-xs">{caption}</p> : null}
    </div>
  );
}
