import React from "react";

const cx = (...a) => a.filter(Boolean).join(" ");

/**
 * Sparkline — a tiny, smooth trend line drawn to fit its box.
 *
 * Color follows `currentColor`, so the caller sets it via a text-* class
 * (e.g. `text-accent`, `text-success`). The optional soft area fill reuses
 * the same color at low opacity.
 *
 * Props:
 * - data      {number[]} Series values. Empty / single-point renders a flat baseline.
 * - height    {number}   Rendered height in px (viewBox height too). Default 32.
 * - area      {bool}     Draw a soft gradient fill under the line. Default false.
 * - className {string}   Extra classes on the <svg> (put the color class here).
 * - ...rest              Spread onto the <svg>.
 */
export default function Sparkline({
  data = [],
  height = 32,
  area = false,
  className,
  ...rest
}) {
  const W = 100; // viewBox width; preserveAspectRatio="none" stretches to fit
  const H = Math.max(1, height);
  const pad = 2; // keep stroke off the very edge
  const gid = React.useId();

  const nums = Array.isArray(data) ? data.filter((n) => typeof n === "number" && !Number.isNaN(n)) : [];

  // Build a smoothed path from the (normalized) points.
  const { line, fill } = React.useMemo(() => {
    if (nums.length === 0) {
      const y = H / 2;
      return { line: `M0,${y} L${W},${y}`, fill: "" };
    }
    const min = Math.min(...nums);
    const max = Math.max(...nums);
    const span = max - min || 1;
    const n = nums.length;
    const stepX = n > 1 ? W / (n - 1) : 0;

    const pts = nums.map((v, i) => {
      const x = n > 1 ? i * stepX : W / 2;
      // invert Y (0 at top) and inset by pad so the stroke isn't clipped
      const y = pad + (1 - (v - min) / span) * (H - pad * 2);
      return [x, y];
    });

    if (n === 1) {
      const y = pts[0][1];
      const l = `M0,${y} L${W},${y}`;
      return { line: l, fill: area ? `${l} L${W},${H} L0,${H} Z` : "" };
    }

    // Catmull-Rom -> cubic bezier for a soft curve.
    let d = `M${pts[0][0]},${pts[0][1]}`;
    for (let i = 0; i < n - 1; i++) {
      const p0 = pts[i - 1] || pts[i];
      const p1 = pts[i];
      const p2 = pts[i + 1];
      const p3 = pts[i + 2] || p2;
      const c1x = p1[0] + (p2[0] - p0[0]) / 6;
      const c1y = p1[1] + (p2[1] - p0[1]) / 6;
      const c2x = p2[0] - (p3[0] - p1[0]) / 6;
      const c2y = p2[1] - (p3[1] - p1[1]) / 6;
      d += ` C${c1x.toFixed(2)},${c1y.toFixed(2)} ${c2x.toFixed(2)},${c2y.toFixed(2)} ${p2[0].toFixed(2)},${p2[1].toFixed(2)}`;
    }
    const f = area ? `${d} L${W},${H} L0,${H} Z` : "";
    return { line: d, fill: f };
  }, [nums, H, area]);

  return (
    <svg
      viewBox={`0 0 ${W} ${H}`}
      width="100%"
      height={H}
      preserveAspectRatio="none"
      fill="none"
      role="img"
      aria-hidden="true"
      className={cx("block overflow-visible", className)}
      {...rest}
    >
      {area && fill ? (
        <>
          <defs>
            <linearGradient id={`spark-${gid}`} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="currentColor" stopOpacity="0.28" />
              <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
            </linearGradient>
          </defs>
          <path d={fill} fill={`url(#spark-${gid})`} stroke="none" />
        </>
      ) : null}
      <path
        d={line}
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  );
}
