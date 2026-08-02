const cx = (...a) => a.filter(Boolean).join(" ");

/**
 * Spinner — a small, crisp loading indicator.
 *
 * Renders an inline SVG spinning arc drawn with `currentColor`, so it inherits
 * the text color of whatever context it sits in (e.g. `text-accent`,
 * `text-white` inside a filled button, or `text-muted`).
 *
 * Props:
 * @param {number}  [size=16]   Width/height in pixels.
 * @param {string}  [className] Extra classes merged onto the root <svg>.
 * @param {object}  [rest]      Any other props spread onto the root <svg>.
 */
export default function Spinner({ size = 16, className, ...rest }) {
  return (
    <svg
      className={cx("animate-spin", className)}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      role="status"
      aria-label="Loading"
      {...rest}
    >
      {/* Faint full ring for context */}
      <circle
        cx="12"
        cy="12"
        r="9"
        stroke="currentColor"
        strokeWidth="2"
        className="opacity-25"
      />
      {/* Bright arc that reads as the spinning segment */}
      <path
        d="M21 12a9 9 0 0 0-9-9"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        className="opacity-90"
      />
    </svg>
  );
}
