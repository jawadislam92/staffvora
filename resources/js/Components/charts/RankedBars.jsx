import React from "react";
import { EmptyState } from "@/Components/ui";
import { useThemeName, themeAllowsGlow } from "./theme";

const cx = (...a) => a.filter(Boolean).join(" ");

const clampSlot = (n) => Math.min(5, Math.max(1, Number(n) || 1));

/**
 * RankedBars — a ranked list (e.g. Top projects by hours). Each row has a
 * two-digit rank, a label + value, an accent-filled progress track, and an
 * optional meta line.
 *
 * Props:
 * - items    [{label, value, pct, meta?, accent?}]  Rows. `value` shown right of
 *            the label, `pct` (0..100) drives the bar width. A row may carry its
 *            own `accent` {1..5} (entity-fixed color) which wins over the
 *            component-level accent.
 * - accent   {1..5}  Categorical --viz slot for every bar's fill: a left→right
 *            gradient from the slot color into a lighter mix (same treatment as
 *            GradientBar). Additive — omitted (and no per-item accent) → the
 *            legacy solid bg-accent fill, unchanged.
 * - glow     {bool}  Soft shadow under each accented track in the bar's own
 *            color. Dark/black themes only. Default false.
 * - accentAll {bool}  Reserved — all bars use the accent by default. Default true.
 * - className {string}
 */
export default function RankedBars({
  items = [],
  accent,
  glow = false,
  accentAll = true,
  className,
  ...rest
}) {
  // Hook before the empty-state early return (rules of hooks).
  const theme = useThemeName();
  const drawGlow = glow && themeAllowsGlow(theme);

  const rows = Array.isArray(items) ? items.filter(Boolean) : [];

  if (rows.length === 0) {
    return (
      <div className={cx("w-full", className)} {...rest}>
        <EmptyState
          title="Nothing to rank yet"
          description="Items appear here once there's activity to compare."
        />
      </div>
    );
  }

  // If pct missing, derive from value relative to the max.
  const maxVal = Math.max(...rows.map((r) => Number(r.value) || 0), 0) || 1;
  const widthOf = (r) => {
    const p = typeof r.pct === "number" ? r.pct : ((Number(r.value) || 0) / maxVal) * 100;
    return Math.max(0, Math.min(100, p));
  };

  // Per-row --viz slot: the row's own accent (entity-fixed) wins over the
  // component-level one; neither given → null → legacy bg-accent fill.
  const slotOf = (r) =>
    r.accent != null ? clampSlot(r.accent) : accent != null ? clampSlot(accent) : null;

  return (
    <div className={cx("w-full flex flex-col gap-4", className)} {...rest}>
      {rows.map((r, i) => {
        const slot = slotOf(r);
        return (
          <div key={i} className="grid grid-cols-[26px_1fr] items-center gap-3">
            <span className="text-xs font-semibold tabular-nums text-subtle">
              {String(i + 1).padStart(2, "0")}
            </span>
            <div className="min-w-0">
              <div className="flex items-center justify-between gap-3 mb-1.5">
                <span className="text-sm font-medium text-body truncate">{r.label}</span>
                {r.value != null ? (
                  <span className="text-xs font-semibold tabular-nums text-muted shrink-0">
                    {r.value}
                  </span>
                ) : null}
              </div>
              <div
                className="h-2 rounded-md bg-surface-2 overflow-hidden"
                style={
                  slot && drawGlow
                    ? { boxShadow: `0 4px 14px -6px color-mix(in srgb, var(--viz-${slot}) 55%, transparent)` }
                    : undefined
                }
              >
                <div
                  className={cx("h-full rounded-md", !slot && "bg-accent")}
                  style={{
                    width: `${widthOf(r)}%`,
                    ...(slot
                      ? {
                          backgroundImage: `linear-gradient(90deg, var(--viz-${slot}), color-mix(in srgb, var(--viz-${slot}) 62%, white))`,
                        }
                      : null),
                  }}
                />
              </div>
              {r.meta ? (
                <div className="mt-1.5 text-[11px] text-subtle">{r.meta}</div>
              ) : null}
            </div>
          </div>
        );
      })}
    </div>
  );
}
