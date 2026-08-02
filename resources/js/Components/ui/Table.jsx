import React from "react";

const cx = (...a) => a.filter(Boolean).join(" ");

/**
 * SortCaret — tiny inline sort indicator used inside sortable <Th>.
 *
 * @param {object}  props
 * @param {boolean} [props.active=false] Whether this column is the active sort column.
 * @param {"asc"|"desc"} [props.dir="asc"] Sort direction to point toward.
 */
function SortCaret({ active = false, dir = "asc" }) {
  return (
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
      className={cx(
        "shrink-0 transition",
        active ? "text-accent" : "text-subtle",
        active && dir === "desc" ? "rotate-180" : ""
      )}
    >
      <path d="M6 15l6-6 6 6" />
    </svg>
  );
}

/**
 * THead — table header section wrapper.
 *
 * @param {object} props
 * @param {React.ReactNode} [props.children]
 * @param {string} [props.className] Extra classes merged onto the <thead>.
 */
export function THead({ className, children, ...rest }) {
  return (
    <thead className={cx("bg-surface-2/40", className)} {...rest}>
      {children}
    </thead>
  );
}

/**
 * TBody — table body section wrapper.
 *
 * @param {object} props
 * @param {React.ReactNode} [props.children]
 * @param {string} [props.className] Extra classes merged onto the <tbody>.
 */
export function TBody({ className, children, ...rest }) {
  return (
    <tbody className={cx(className)} {...rest}>
      {children}
    </tbody>
  );
}

/**
 * Tr — table row with hover highlight and a divider that collapses on the last row.
 *
 * @param {object} props
 * @param {React.ReactNode} [props.children]
 * @param {string} [props.className] Extra classes merged onto the <tr>.
 */
export function Tr({ className, children, ...rest }) {
  return (
    <tr
      className={cx(
        "border-b border-line last:border-0 hover:bg-surface-2/40 transition",
        className
      )}
      {...rest}
    >
      {children}
    </tr>
  );
}

/**
 * Th — table header cell. When `sortable`, renders a clickable control with a
 * caret that reflects the active column and direction.
 *
 * @param {object}  props
 * @param {React.ReactNode} [props.children]
 * @param {boolean} [props.sortable=false] Enable the sort affordance (caret + click/keyboard).
 * @param {boolean} [props.active=false] Whether this column is the current sort column.
 * @param {"asc"|"desc"} [props.dir="asc"] Current sort direction.
 * @param {(e: React.MouseEvent|React.KeyboardEvent) => void} [props.onClick] Sort handler.
 * @param {string} [props.className] Extra classes merged onto the <th> (e.g. "text-right").
 */
export function Th({
  className,
  children,
  sortable = false,
  active = false,
  dir = "asc",
  onClick,
  ...rest
}) {
  const base =
    "text-left text-xs uppercase tracking-wide font-medium text-muted px-4 py-3";

  if (!sortable) {
    return (
      <th className={cx(base, className)} {...rest}>
        {children}
      </th>
    );
  }

  const handleKeyDown = (e) => {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      onClick?.(e);
    }
  };

  return (
    <th
      scope="col"
      aria-sort={active ? (dir === "desc" ? "descending" : "ascending") : "none"}
      className={cx(base, className)}
      {...rest}
    >
      <span
        role="button"
        tabIndex={0}
        onClick={onClick}
        onKeyDown={handleKeyDown}
        className={cx(
          "inline-flex items-center gap-1.5 cursor-pointer select-none rounded-md transition",
          "hover:text-body",
          active && "text-accent",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
        )}
      >
        {children}
        <SortCaret active={active} dir={dir} />
      </span>
    </th>
  );
}

/**
 * Td — table body cell.
 *
 * @param {object} props
 * @param {React.ReactNode} [props.children]
 * @param {string} [props.className] Extra classes merged onto the <td> (e.g. "text-right tabular-nums").
 */
export function Td({ className, children, ...rest }) {
  return (
    <td className={cx("px-4 py-3 text-body align-middle", className)} {...rest}>
      {children}
    </td>
  );
}

/**
 * Table — composable, theme-aware table shell. Compose with THead, TBody, Tr,
 * Th, and Td. Horizontal overflow scrolls within the wrapper so the page body
 * never scrolls sideways.
 *
 * @param {object} props
 * @param {React.ReactNode} [props.children] Table sections/rows (thead/tbody/tr...).
 * @param {string} [props.className] Extra classes merged onto the <table>.
 */
export default function Table({ className, children, ...rest }) {
  return (
    <div className="overflow-x-auto">
      <table className={cx("w-full text-sm border-collapse", className)} {...rest}>
        {children}
      </table>
    </div>
  );
}

export { Table };
