import React from "react";

const cx = (...a) => a.filter(Boolean).join(" ");

/**
 * Skeleton — a single shimmering placeholder block.
 *
 * The caller supplies the dimensions via `className` (e.g. "h-4 w-32").
 *
 * @param {object}  props
 * @param {string}  [props.className]        Extra classes, typically width/height (e.g. "h-4 w-32").
 * @param {string}  [props.rounded="rounded-md"]  Corner radius token for the block.
 * @param {object}  [props.rest]             Any other props are spread onto the root element.
 */
function Skeleton({ className, rounded = "rounded-md", ...rest }) {
  return (
    <div
      aria-hidden="true"
      className={cx("animate-pulse bg-surface-2", rounded, className)}
      {...rest}
    />
  );
}

/**
 * SkeletonText — a stack of skeleton bars with varying widths to mimic text.
 *
 * @param {object}  props
 * @param {number}  [props.lines=3]    How many text bars to render.
 * @param {string}  [props.className]  Extra classes merged onto the wrapping container.
 * @param {object}  [props.rest]       Any other props are spread onto the root element.
 */
function SkeletonText({ lines = 3, className, ...rest }) {
  const widths = ["w-full", "w-11/12", "w-4/5", "w-3/4", "w-2/3", "w-1/2"];
  return (
    <div className={cx("space-y-2.5", className)} {...rest}>
      {Array.from({ length: Math.max(0, lines) }).map((_, i) => {
        const isLast = i === lines - 1;
        const width = isLast ? "w-1/2" : widths[i % widths.length];
        return <Skeleton key={i} className={cx("h-3.5", width)} />;
      })}
    </div>
  );
}

/**
 * SkeletonCard — a full loading placeholder for a card/panel.
 *
 * Renders a bordered surface containing a short heading block, a couple of
 * text skeletons, and a taller media/chart block.
 *
 * @param {object}  props
 * @param {string}  [props.className]  Extra classes merged onto the card root.
 * @param {object}  [props.rest]       Any other props are spread onto the root element.
 */
function SkeletonCard({ className, ...rest }) {
  return (
    <div
      className={cx(
        "bg-surface border border-line rounded-xl p-5 shadow-sm",
        className
      )}
      {...rest}
    >
      <div className="space-y-4">
        <Skeleton className="h-5 w-1/3" rounded="rounded-lg" />
        <SkeletonText lines={2} />
        <Skeleton className="h-32 w-full" rounded="rounded-lg" />
      </div>
    </div>
  );
}

export default Skeleton;
export { Skeleton, SkeletonText, SkeletonCard };
