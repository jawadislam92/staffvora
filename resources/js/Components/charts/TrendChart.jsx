import React from "react";
import { EmptyState } from "@/Components/ui";
import { useThemeName, themeAllowsGlow } from "./theme";

const cx = (...a) => a.filter(Boolean).join(" ");

const ACCENT = "#2563EB";
const ACCENT_LIGHT = "#60a5fa";

const ClockIcon = (props) => (
  <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...props}>
    <circle cx="12" cy="12" r="8.5" />
    <path d="M12 7.5V12l3 2" />
  </svg>
);

const LegendSwatch = ({ variant }) => {
  // 3px line samples drawn in SVG so dashes read crisply; color via currentColor wrapper
  return (
    <svg width="18" height="6" viewBox="0 0 18 6" className="shrink-0" aria-hidden="true">
      {variant === "solid" ? (
        <line x1="0" y1="3" x2="18" y2="3" stroke={ACCENT} strokeWidth="3" strokeLinecap="round" />
      ) : (
        <line
          x1="0"
          y1="3"
          x2="18"
          y2="3"
          stroke="currentColor"
          strokeWidth="2.5"
          strokeLinecap="round"
          strokeDasharray={variant === "dashed" ? "5 4" : "1.5 4"}
        />
      )}
    </svg>
  );
};

/**
 * TrendChart — the hero worked-hours area+line chart from the dashboard.
 *
 * Props:
 * - data     {[{label,value}]} Current-period series (x = label, y = value).
 * - compare  {number[]}        Previous-period values, drawn as a faint dashed line behind.
 * - unit     {string}          Y-axis unit suffix. Default "h".
 * - height   {number}          Plot height in px. Default 240.
 * - avg      {number}          If given, draws a dotted average line + legend entry.
 * - gradientFill {bool}        Vertical accent→transparent underfill. Default true
 *                              (the chart has always drawn it); false disables.
 * - glow     {bool}            Line halo. Omitted → legacy always-on behaviour
 *                              (unchanged call sites render identically).
 *                              true → theme-aware: dark/black themes only.
 *                              false → never.
 * - className {string}
 */
export default function TrendChart({
  data = [],
  compare,
  unit = "h",
  height = 240,
  avg,
  gradientFill = true,
  glow,
  className,
  ...rest
}) {
  const gid = React.useId().replace(/[:]/g, "");
  const theme = useThemeName();
  const drawGlow = glow === undefined ? true : glow === true && themeAllowsGlow(theme);

  const points = Array.isArray(data)
    ? data.filter((d) => d && typeof d.value === "number" && !Number.isNaN(d.value))
    : [];

  if (points.length === 0) {
    return (
      <div className={cx("w-full", className)} style={{ minHeight: height }} {...rest}>
        <EmptyState
          icon={<ClockIcon />}
          title="No activity in this period"
          description="Once time is tracked, the worked-hours trend appears here."
        />
      </div>
    );
  }

  // ---- geometry -----------------------------------------------------------
  const W = 720;
  const H = height;
  const padL = 52;
  const padR = 20;
  const padT = 24;
  const padB = 34;
  const plotW = W - padL - padR;
  const plotH = H - padT - padB;

  const values = points.map((p) => p.value);
  const cmp = Array.isArray(compare) ? compare.filter((n) => typeof n === "number" && !Number.isNaN(n)) : [];
  const rawMax = Math.max(...values, ...(cmp.length ? cmp : []), typeof avg === "number" ? avg : 0);
  // round the top up to a "nice" number for clean gridlines
  const niceMax = niceCeil(rawMax || 1);
  const min = 0;
  const max = niceMax;
  const span = max - min || 1;

  const n = points.length;
  const xAt = (i) => padL + (n > 1 ? (i / (n - 1)) * plotW : plotW / 2);
  const yAt = (v) => padT + (1 - (v - min) / span) * plotH;

  const curPts = points.map((p, i) => [xAt(i), yAt(p.value)]);
  const cmpPts = cmp.map((v, i) => [xAt(i), yAt(v)]);

  const curLine = smoothPath(curPts);
  const curArea = curPts.length
    ? `${curLine} L${curPts[curPts.length - 1][0].toFixed(2)},${(padT + plotH).toFixed(2)} L${curPts[0][0].toFixed(2)},${(padT + plotH).toFixed(2)} Z`
    : "";
  const cmpLine = cmpPts.length > 1 ? smoothPath(cmpPts) : "";

  // gridlines (4 rows)
  const rows = 4;
  const gridYs = Array.from({ length: rows + 1 }, (_, i) => padT + (i / rows) * plotH);
  const yLabels = Array.from({ length: rows + 1 }, (_, i) => Math.round(max - (i / rows) * span));

  // peak point
  let peakIdx = 0;
  values.forEach((v, i) => {
    if (v > values[peakIdx]) peakIdx = i;
  });
  const peak = points[peakIdx];
  const peakX = xAt(peakIdx);
  const peakY = yAt(peak.value);

  // x-axis label subset (~5 evenly spaced)
  const desired = Math.min(5, n);
  const xTickIdx = new Set();
  if (desired > 1) {
    for (let k = 0; k < desired; k++) xTickIdx.add(Math.round((k / (desired - 1)) * (n - 1)));
  } else {
    xTickIdx.add(0);
  }

  // peak flag box position (clamp within plot)
  const flagW = 104;
  const flagH = 38;
  let flagX = peakX - flagW / 2;
  flagX = Math.max(padL, Math.min(flagX, W - padR - flagW));
  const flagY = Math.max(2, peakY - flagH - 14);

  const fmt = (v) => `${Number.isInteger(v) ? v : v.toFixed(1)}${unit}`;

  return (
    <div className={cx("w-full", className)} {...rest}>
      {/* legend */}
      <div className="flex items-center gap-4 flex-wrap px-1 pb-1 text-xs text-muted">
        <span className="inline-flex items-center gap-1.5">
          <LegendSwatch variant="solid" />
          This period
        </span>
        {cmpLine ? (
          <span className="inline-flex items-center gap-1.5 text-subtle">
            <LegendSwatch variant="dashed" />
            Previous
          </span>
        ) : null}
        {typeof avg === "number" ? (
          <span className="inline-flex items-center gap-1.5 text-subtle">
            <LegendSwatch variant="dotted" />
            {`Avg ${fmt(avg)}`}
          </span>
        ) : null}
      </div>

      <svg
        viewBox={`0 0 ${W} ${H}`}
        width="100%"
        className="block h-auto"
        role="img"
        aria-label={`Worked hours trend, peak ${fmt(peak.value)} on ${peak.label}`}
      >
        <defs>
          <linearGradient id={`area-${gid}`} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={ACCENT} stopOpacity="0.42" />
            <stop offset="55%" stopColor={ACCENT} stopOpacity="0.12" />
            <stop offset="100%" stopColor={ACCENT} stopOpacity="0" />
          </linearGradient>
          <linearGradient id={`line-${gid}`} x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%" stopColor={ACCENT_LIGHT} />
            <stop offset="100%" stopColor={ACCENT} />
          </linearGradient>
          <filter id={`glow-${gid}`} x="-20%" y="-40%" width="140%" height="180%">
            <feGaussianBlur in="SourceGraphic" stdDeviation="3.4" result="blur" />
            <feMerge>
              <feMergeNode in="blur" />
              <feMergeNode in="SourceGraphic" />
            </feMerge>
          </filter>
        </defs>

        {/* gridlines + y labels (color via text-subtle wrapper -> currentColor) */}
        <g className="text-subtle">
          {gridYs.map((y, i) => (
            <line
              key={i}
              x1={padL}
              y1={y}
              x2={W - padR}
              y2={y}
              stroke="currentColor"
              strokeWidth="1"
              opacity="0.35"
            />
          ))}
          {yLabels.map((lbl, i) => (
            <text
              key={i}
              x={padL - 10}
              y={gridYs[i] + 4}
              textAnchor="end"
              fontSize="11"
              fill="currentColor"
            >
              {`${lbl}${unit}`}
            </text>
          ))}
        </g>

        {/* avg dotted line */}
        {typeof avg === "number" ? (
          <line
            x1={padL}
            y1={yAt(avg)}
            x2={W - padR}
            y2={yAt(avg)}
            stroke={ACCENT}
            strokeWidth="1.2"
            strokeDasharray="2 5"
            opacity="0.6"
          />
        ) : null}

        {/* compare dashed line behind */}
        {cmpLine ? (
          <path
            d={cmpLine}
            fill="none"
            stroke="currentColor"
            className="text-subtle"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeDasharray="5 6"
            opacity="0.7"
          />
        ) : null}

        {/* area + current line */}
        {gradientFill ? <path d={curArea} fill={`url(#area-${gid})`} stroke="none" /> : null}
        <path
          d={curLine}
          fill="none"
          stroke={`url(#line-${gid})`}
          strokeWidth="2.6"
          strokeLinecap="round"
          strokeLinejoin="round"
          filter={drawGlow ? `url(#glow-${gid})` : undefined}
        />

        {/* peak marker + connector */}
        <line x1={peakX} y1={peakY} x2={peakX} y2={padT + plotH} stroke={ACCENT} strokeWidth="1" strokeDasharray="3 4" opacity="0.6" />
        <circle cx={peakX} cy={peakY} r="6.5" fill={ACCENT} stroke="currentColor" className="text-app" strokeWidth="3" />

        {/* peak flag label anchored in SVG coords */}
        <g>
          <rect
            x={flagX}
            y={flagY}
            width={flagW}
            height={flagH}
            rx="9"
            fill="currentColor"
            className="text-surface-2"
            stroke={ACCENT}
            strokeWidth="1.5"
          />
          <text x={flagX + flagW / 2} y={flagY + 17} textAnchor="middle" className="text-body" fill="currentColor" fontSize="14" fontWeight="700">
            {fmt(peak.value)}
          </text>
          <text x={flagX + flagW / 2} y={flagY + 31} textAnchor="middle" className="text-subtle" fill="currentColor" fontSize="11">
            {peak.label}
          </text>
        </g>

        {/* x labels */}
        <g className="text-subtle">
          {points.map((p, i) =>
            xTickIdx.has(i) ? (
              <text key={i} x={xAt(i)} y={H - 12} textAnchor="middle" fontSize="11" fill="currentColor">
                {p.label}
              </text>
            ) : null
          )}
        </g>
      </svg>
    </div>
  );
}

// --- helpers ---------------------------------------------------------------

function niceCeil(v) {
  if (v <= 0) return 1;
  const pow = Math.pow(10, Math.floor(Math.log10(v)));
  const n = v / pow;
  let nice;
  if (n <= 1) nice = 1;
  else if (n <= 2) nice = 2;
  else if (n <= 2.5) nice = 2.5;
  else if (n <= 5) nice = 5;
  else nice = 10;
  return nice * pow;
}

function smoothPath(pts) {
  if (!pts.length) return "";
  if (pts.length === 1) return `M${pts[0][0]},${pts[0][1]}`;
  let d = `M${pts[0][0].toFixed(2)},${pts[0][1].toFixed(2)}`;
  for (let i = 0; i < pts.length - 1; i++) {
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
  return d;
}
