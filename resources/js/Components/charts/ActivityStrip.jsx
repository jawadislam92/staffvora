import React from "react";
import { EmptyState } from "@/Components/ui";
import { useThemeName, themeAllowsGlow } from "./theme";

const cx = (...a) => a.filter(Boolean).join(" ");

const clampSlot = (n) => Math.min(5, Math.max(1, Number(n) || 1));

// tone -> classes for the segment bar + its legend dot
const TONES = {
  tracked: { bar: "bg-success", dot: "bg-success", label: "Tracked" },
  manual: { bar: "bg-info", dot: "bg-info", label: "Manual" },
  idle: { bar: "bg-warning-soft", dot: "bg-warning", label: "Idle" },
  untracked: { bar: "bg-surface-2", dot: "bg-surface-2 ring-1 ring-line", label: "Untracked" },
};

const HOUR_TICKS = ["12a", "4a", "8a", "12p", "4p", "8p", "12a"];

/**
 * ActivityStrip — a single rounded ribbon split into tracked / manual / idle /
 * untracked segments, with optional hour ticks beneath and a % legend.
 *
 * Props (one of):
 * - breakdown {{tracked,idle,manual,untracked}}  Percentages (any scale — normalized).
 * - segments  [{label,pct,tone,accent?}]         Explicit ordered segments. A segment
 *                                                may carry `accent` {1..5} to wear a
 *                                                categorical --viz slot instead of its
 *                                                tone class (additive — tone-only
 *                                                segments render exactly as before).
 * - hours     {bool}                             Show the 12a..12a tick row. Default true.
 * - glow      {bool}                             Soft shadow under the ribbon in the
 *                                                accent segments' colors. Dark/black
 *                                                themes only; no-op when no segment
 *                                                carries an accent. Default false.
 * - className {string}
 */
export default function ActivityStrip({
  breakdown,
  segments,
  hours = true,
  glow = false,
  className,
  ...rest
}) {
  const theme = useThemeName();
  // Normalize inputs into an ordered segment list.
  const segs = React.useMemo(() => {
    if (Array.isArray(segments) && segments.length) {
      return segments
        .map((s) => ({
          label: s.label ?? TONES[s.tone]?.label ?? "—",
          pct: Number(s.pct) || 0,
          tone: s.tone || "tracked",
          accent: s.accent != null ? clampSlot(s.accent) : null,
        }))
        .filter((s) => s.pct > 0);
    }
    const b = breakdown || {};
    return ["tracked", "manual", "idle", "untracked"]
      .map((tone) => ({ label: TONES[tone].label, pct: Number(b[tone]) || 0, tone }))
      .filter((s) => s.pct > 0);
  }, [breakdown, segments]);

  const total = segs.reduce((sum, s) => sum + s.pct, 0);

  if (total <= 0) {
    return (
      <div className={cx("w-full", className)} {...rest}>
        <EmptyState
          title="No activity yet"
          description="Segments appear here once time is tracked for this period."
        />
      </div>
    );
  }

  const pctOf = (v) => (v / total) * 100;

  // Glow (dark/black only): the ribbon clips its children, so the halo sits on
  // the container — stacked soft shadows in the accent segments' own colors.
  const accented = segs.filter((s) => s.accent);
  const ribbonStyle =
    glow && themeAllowsGlow(theme) && accented.length
      ? {
          boxShadow: accented
            .slice(0, 3)
            .map(
              (s, i) =>
                `0 6px 22px -10px color-mix(in srgb, var(--viz-${s.accent}) ${i === 0 ? 45 : 25}%, transparent)`
            )
            .join(", "),
        }
      : undefined;

  return (
    <div className={cx("w-full", className)} {...rest}>
      <div
        className="flex h-11 rounded-xl overflow-hidden border border-line"
        style={ribbonStyle}
        role="img"
        aria-label={segs.map((s) => `${s.label} ${Math.round(pctOf(s.pct))}%`).join(", ")}
      >
        {segs.map((s, i) => {
          const tone = TONES[s.tone] || TONES.tracked;
          return (
            <span
              key={i}
              className={cx("h-full transition-[filter] hover:brightness-110", !s.accent && tone.bar)}
              style={{
                width: `${pctOf(s.pct)}%`,
                ...(s.accent ? { backgroundColor: `var(--viz-${s.accent})` } : null),
              }}
              title={`${s.label} · ${Math.round(pctOf(s.pct))}%`}
            />
          );
        })}
      </div>

      {hours ? (
        <div className="flex justify-between mt-2">
          {HOUR_TICKS.map((t, i) => (
            <span key={i} className="text-[10.5px] tabular-nums text-subtle">
              {t}
            </span>
          ))}
        </div>
      ) : null}

      <div className="flex flex-wrap gap-x-4 gap-y-2 mt-4">
        {segs.map((s, i) => {
          const tone = TONES[s.tone] || TONES.tracked;
          return (
            <span key={i} className="inline-flex items-center gap-2 text-xs text-muted">
              <span
                className={cx("h-2.5 w-2.5 rounded-[3px] shrink-0", !s.accent && tone.dot)}
                style={s.accent ? { backgroundColor: `var(--viz-${s.accent})` } : undefined}
              />
              {`${s.label} · ${Math.round(pctOf(s.pct))}%`}
            </span>
          );
        })}
      </div>
    </div>
  );
}
