import React from "react";

const cx = (...a) => a.filter(Boolean).join(" ");

const clampSlot = (n) => Math.min(5, Math.max(1, Number(n) || 1));
const clampPct = (n) => Math.min(100, Math.max(0, Number(n) || 0));

/**
 * GradientBar — pure-CSS horizontal progress bar in a categorical --viz slot.
 *
 * Track: bg-surface-2. Fill: left→right gradient from the slot color into a
 * lighter mix of it. Label sits left in text-body; the right side reads
 * "42% (133)" in text-muted (count omitted when not given).
 *
 * Props:
 * - accent    {1..5}   Categorical --viz slot for the fill. Default 1.
 * - label     {node}   Row label (left).
 * - pct       {number} Fill percentage 0–100.
 * - count     {number} Optional raw count shown beside the percentage.
 * - className {string} Extra classes merged onto the root.
 * - ...rest            Spread onto the root element.
 */
export default function GradientBar({
  accent = 1,
  label,
  pct = 0,
  count,
  className,
  ...rest
}) {
  const slot = clampSlot(accent);
  const width = clampPct(pct);
  const shown = Math.round(width * 10) / 10;

  return (
    <div className={cx("flex flex-col gap-1.5", className)} {...rest}>
      <div className="flex items-baseline justify-between gap-3 text-xs">
        <span className="text-body truncate">{label}</span>
        <span className="text-muted tabular-nums shrink-0">
          {count != null ? `${shown}% (${count})` : `${shown}%`}
        </span>
      </div>
      <div
        className="h-[10px] w-full rounded-full bg-surface-2 overflow-hidden"
        role="progressbar"
        aria-valuenow={shown}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <div
          className="h-full rounded-full"
          style={{
            width: `${width}%`,
            backgroundImage: `linear-gradient(90deg, var(--viz-${slot}), color-mix(in srgb, var(--viz-${slot}) 62%, white))`,
          }}
        />
      </div>
    </div>
  );
}
