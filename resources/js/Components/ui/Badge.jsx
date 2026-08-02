import React from "react";

const cx = (...a) => a.filter(Boolean).join(" ");

const TONES = {
  neutral: "bg-surface-2 text-muted",
  accent: "bg-accent/10 text-accent",
  success: "bg-success-soft text-success",
  warning: "bg-warning-soft text-warning",
  danger: "bg-danger-soft text-danger",
  info: "bg-info-soft text-info",
};

const SIZES = {
  sm: "text-xs px-2 py-0.5",
  md: "text-sm px-2.5 py-1",
};

/**
 * Badge — a compact status pill.
 *
 * @param {Object} props
 * @param {"neutral"|"accent"|"success"|"warning"|"danger"|"info"} [props.tone="neutral"] - Color scheme / semantic status.
 * @param {boolean} [props.dot=false] - Renders a small filled circle (current text color) before the label.
 * @param {"sm"|"md"} [props.size="sm"] - Pill size.
 * @param {string} [props.className] - Extra classes merged onto the root.
 * @param {React.ReactNode} [props.children] - Badge label.
 * @param {any} [props.rest] - Any other props spread onto the root <span>.
 */
export default function Badge({
  tone = "neutral",
  dot = false,
  size = "sm",
  className,
  children,
  ...rest
}) {
  const toneClasses = TONES[tone] || TONES.neutral;
  const sizeClasses = SIZES[size] || SIZES.sm;

  return (
    <span
      className={cx(
        "inline-flex items-center gap-1.5 rounded-full font-medium",
        toneClasses,
        sizeClasses,
        className
      )}
      {...rest}
    >
      {dot && (
        <span
          aria-hidden="true"
          className={cx(
            "shrink-0 rounded-full bg-current",
            size === "md" ? "h-2 w-2" : "h-1.5 w-1.5"
          )}
        />
      )}
      {children}
    </span>
  );
}
