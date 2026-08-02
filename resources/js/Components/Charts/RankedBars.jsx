import React from "react";
import { EmptyState } from "@/Components/ui";

const cx = (...a) => a.filter(Boolean).join(" ");

/**
 * RankedBars — a ranked list (e.g. Top projects by hours). Each row has a
 * two-digit rank, a label + value, an accent-filled progress track, and an
 * optional meta line.
 *
 * Props:
 * - items    [{label, value, pct, meta?}]  Rows. `value` shown right of the label,
 *                                          `pct` (0..100) drives the bar width.
 * - accentAll {bool}  Reserved — all bars use the accent by default. Default true.
 * - className {string}
 */
export default function RankedBars({ items = [], accentAll = true, className, ...rest }) {
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

  return (
    <div className={cx("w-full flex flex-col gap-4", className)} {...rest}>
      {rows.map((r, i) => (
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
            <div className="h-2 rounded-md bg-surface-2 overflow-hidden">
              <div
                className="h-full rounded-md bg-accent"
                style={{ width: `${widthOf(r)}%` }}
              />
            </div>
            {r.meta ? (
              <div className="mt-1.5 text-[11px] text-subtle">{r.meta}</div>
            ) : null}
          </div>
        </div>
      ))}
    </div>
  );
}
