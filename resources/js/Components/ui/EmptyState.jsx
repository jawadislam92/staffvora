import React from "react";

const cx = (...a) => a.filter(Boolean).join(" ");

/**
 * DefaultInboxIcon — the fallback tray/inbox glyph used when no `icon` is provided.
 * Inline SVG, inherits color via stroke="currentColor".
 */
export function DefaultInboxIcon(props) {
  return (
    <svg
      viewBox="0 0 24 24"
      width="20"
      height="20"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...props}
    >
      <path d="M4 13h4l1.5 2.5h5L16 13h4" />
      <path d="M5 13l1.8-6.2A2 2 0 0 1 8.7 5.3h6.6a2 2 0 0 1 1.9 1.5L19 13v4a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2z" />
    </svg>
  );
}

/**
 * EmptyState — a graceful, centered empty-state surface for "no data" moments.
 *
 * Props:
 * - icon        {node}   Glyph shown in the circular badge. Defaults to an inbox/tray svg.
 * - title       {node}   Primary heading text.
 * - description {node}   Supporting explanation (kept short).
 * - action      {node}   Optional call-to-action, e.g. a <Button>.
 * - className   {string} Extra classes merged onto the root.
 * - ...rest              Spread onto the root element.
 */
export default function EmptyState({
  icon,
  title,
  description,
  action,
  className,
  ...rest
}) {
  return (
    <div
      className={cx(
        "flex flex-col items-center text-center gap-3 py-12 px-6",
        className
      )}
      {...rest}
    >
      <div className="h-12 w-12 grid place-items-center rounded-full bg-surface-2 text-muted">
        {icon || <DefaultInboxIcon />}
      </div>

      {title ? (
        <h3 className="text-body font-medium">{title}</h3>
      ) : null}

      {description ? (
        <p className="text-sm text-muted max-w-sm">{description}</p>
      ) : null}

      {action ? <div className="mt-1">{action}</div> : null}
    </div>
  );
}
