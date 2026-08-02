import React from "react";

const cx = (...a) => a.filter(Boolean).join(" ");

/**
 * StatCard — a KPI stat card for the analytics dashboard.
 *
 * Props:
 * @param {React.ReactNode} label   Small uppercase caption above the value.
 * @param {React.ReactNode} value   The primary metric (rendered tabular-nums).
 * @param {{value: string|number, dir: "up"|"down", good?: boolean}} [delta]
 *        Change chip. Color follows `good` when provided; otherwise up→success,
 *        down→danger.
 * @param {React.ReactNode} [hint]  Small caption shown under the value.
 * @param {React.ReactNode} [icon]  Node shown top-right in a subtle bg-surface-2 square.
 * @param {React.ReactNode} [spark] Sparkline slot, shown at the bottom of the card.
 * @param {string} [className]      Extra classes merged onto the root.
 * @param {object} [rest]           Spread onto the root element.
 */

const Caret = ({ dir }) => (
  <svg
    width="14"
    height="14"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="2"
    strokeLinecap="round"
    strokeLinejoin="round"
    aria-hidden="true"
  >
    {dir === "down" ? (
      <polyline points="6 9 12 15 18 9" />
    ) : (
      <polyline points="6 15 12 9 18 15" />
    )}
  </svg>
);

export function DeltaChip({ value, dir = "up", good, className, ...rest }) {
  const isGood = good === undefined ? dir === "up" : good;
  return (
    <span
      className={cx(
        "inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium tabular-nums",
        isGood ? "text-success bg-success-soft" : "text-danger bg-danger-soft",
        className
      )}
      {...rest}
    >
      <Caret dir={dir} />
      {value}
    </span>
  );
}

export default function StatCard({
  label,
  value,
  delta,
  hint,
  icon,
  spark,
  className,
  ...rest
}) {
  return (
    <div
      className={cx(
        "bg-surface border border-line rounded-xl p-5 flex flex-col gap-2",
        className
      )}
      {...rest}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="flex flex-col gap-2 min-w-0">
          {label != null && (
            <span className="text-xs uppercase tracking-wide text-muted">
              {label}
            </span>
          )}
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-2xl font-semibold text-body tabular-nums">
              {value}
            </span>
            {delta && (
              <DeltaChip value={delta.value} dir={delta.dir} good={delta.good} />
            )}
          </div>
          {hint != null && (
            <span className="text-xs text-subtle">{hint}</span>
          )}
        </div>

        {icon != null && (
          <span className="shrink-0 inline-flex items-center justify-center h-9 w-9 rounded-md bg-surface-2 text-muted">
            {icon}
          </span>
        )}
      </div>

      {spark != null && <div className="mt-1">{spark}</div>}
    </div>
  );
}
