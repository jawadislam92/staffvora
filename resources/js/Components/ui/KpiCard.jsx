import React from "react";
import { Sparkline } from "@/Components/charts";
import { useThemeName, themeAllowsGlow } from "@/Components/charts/theme";

const cx = (...a) => a.filter(Boolean).join(" ");

const clampSlot = (n) => Math.min(5, Math.max(1, Number(n) || 1));

const Arrow = ({ direction }) => (
  <svg
    width="12"
    height="12"
    viewBox="0 0 24 24"
    fill="currentColor"
    aria-hidden="true"
    className="shrink-0"
  >
    {direction === "down" ? (
      <path d="M12 19l-7-8h14l-7 8z" />
    ) : (
      <path d="M12 5l7 8H5l7-8z" />
    )}
  </svg>
);

/**
 * KpiCard — a KPI card with its own categorical accent hue.
 *
 * Tinted icon chip top-left, muted label, large value, ▲/▼ delta row, and a
 * sparkline glowing in the accent along the card bottom. All hues come from
 * the --viz tokens so the one component tree serves light / dark / black.
 *
 * Props:
 * - accent {1..5}  Categorical --viz slot. Default 1.
 * - icon   {node}  Icon element (e.g. a Lucide icon) shown in the tinted chip.
 * - label  {node}  Muted caption above the value.
 * - value  {node}  The big number.
 * - sub    {node}  Small caption under the value (e.g. "Team total · today").
 * - delta  {{pct, direction: "up"|"down"|"flat", good: true|false|null, label}}
 *          Change row. Arrow color follows GOOD, not direction: text-success
 *          when good, text-danger when bad, text-muted when flat/neutral.
 *          Null/absent → row not rendered.
 * - spark  {number[]} Sparkline series along the card bottom (36px tall,
 *          gradient underfill, glow in dark/black themes only).
 *          Null/absent → strip not rendered.
 */
export default function KpiCard({
  accent = 1,
  icon,
  label,
  value,
  sub,
  delta,
  spark,
  className,
  style,
  ...rest
}) {
  const slot = clampSlot(accent);
  const theme = useThemeName();
  const glowTheme = themeAllowsGlow(theme); // dark/black: soft accent glow; light: crisp

  const accentVar = `var(--viz-${slot})`;
  const cardStyle = {
    // hairline border tinted toward the accent (softer in light)
    borderColor: `color-mix(in srgb, ${accentVar} ${glowTheme ? 30 : 18}%, var(--border))`,
    ...(glowTheme
      ? { boxShadow: `0 8px 30px -12px color-mix(in srgb, ${accentVar} 45%, transparent)` }
      : {}),
    ...style,
  };

  const hasDelta = delta != null && delta.direction != null;
  const flat = hasDelta && (delta.direction === "flat" || delta.good == null);
  const deltaTone = !hasDelta
    ? ""
    : flat
      ? "text-muted"
      : delta.good
        ? "text-success"
        : "text-danger";
  const deltaPct =
    hasDelta && typeof delta.pct === "number" && !Number.isNaN(delta.pct)
      ? `${delta.pct}%`
      : null;

  return (
    <div
      className={cx(
        "bg-surface border rounded-2xl overflow-hidden flex flex-col",
        className
      )}
      style={cardStyle}
      {...rest}
    >
      <div className="p-5 flex flex-col gap-3 min-w-0">
        <div className="flex items-center gap-3">
          {icon != null && (
            <span
              className="shrink-0 inline-flex items-center justify-center h-10 w-10 rounded-xl"
              style={{
                backgroundColor: `var(--viz-${slot}-soft)`,
                color: accentVar,
              }}
            >
              {icon}
            </span>
          )}
          {label != null && (
            <span className="text-xs uppercase tracking-wide text-muted min-w-0 truncate">
              {label}
            </span>
          )}
        </div>

        <div className="flex flex-col gap-1 min-w-0">
          <span className="text-3xl font-semibold text-body tabular-nums leading-tight">
            {value}
          </span>
          {sub != null && <span className="text-xs text-subtle">{sub}</span>}
        </div>

        {hasDelta && (
          <div className="flex items-center gap-1.5 text-xs">
            <span className={cx("inline-flex items-center gap-1 font-medium tabular-nums", deltaTone)}>
              {delta.direction !== "flat" && <Arrow direction={delta.direction} />}
              {deltaPct}
            </span>
            {delta.label != null && <span className="text-subtle">{delta.label}</span>}
          </div>
        )}
      </div>

      {Array.isArray(spark) && spark.length > 0 && (
        <div className="mt-auto">
          <Sparkline data={spark} height={36} accent={slot} glow className="w-full" />
        </div>
      )}
    </div>
  );
}
