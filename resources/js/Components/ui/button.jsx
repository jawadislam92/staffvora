import { forwardRef } from "react";
import Spinner from "./Spinner";

const cx = (...a) => a.filter(Boolean).join(" ");

const VARIANTS = {
  primary:
    "bg-accent text-white hover:brightness-110 active:brightness-95 border border-transparent",
  secondary:
    "bg-surface-2 text-body border border-line hover:bg-surface",
  ghost:
    "bg-transparent text-body border border-transparent hover:bg-surface-2",
  danger:
    "bg-danger text-white hover:brightness-110 active:brightness-95 border border-transparent",
};

const SIZES = {
  sm: "h-8 px-3 text-xs gap-1.5",
  md: "h-10 px-4 text-sm gap-2",
};

/**
 * Button — the primary action control for Staffvora.
 *
 * @param {"primary"|"secondary"|"ghost"|"danger"} [variant="primary"] Visual style.
 * @param {"sm"|"md"} [size="md"] Control height/padding scale.
 * @param {boolean} [loading=false] Show a leading spinner, keep width, block interaction.
 * @param {React.ReactNode} [icon] Leading node (icon) rendered before children.
 * @param {React.ReactNode} [iconTrailing] Trailing node rendered after children.
 * @param {boolean} [block=false] Stretch to full width (w-full).
 * @param {"button"|"a"|React.ElementType} [as="button"] Root element; auto-becomes "a" when href is set.
 * @param {string} [href] When provided, renders an anchor.
 * @param {boolean} [disabled=false] Disable the control.
 * @param {string} [className] Extra classes merged onto the root.
 * @param {React.ReactNode} [children] Button label/content.
 */
const Button = forwardRef(function Button(
  {
    variant = "primary",
    size = "md",
    loading = false,
    icon,
    iconTrailing,
    block = false,
    as,
    href,
    disabled = false,
    type,
    className,
    children,
    ...rest
  },
  ref
) {
  const Component = as || (href ? "a" : "button");
  const isNativeButton = Component === "button";
  const isDisabled = disabled || loading;

  const classes = cx(
    "relative inline-flex items-center justify-center font-medium rounded-lg select-none",
    "cursor-pointer transition",
    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app",
    "disabled:opacity-50 disabled:cursor-not-allowed",
    VARIANTS[variant] || VARIANTS.primary,
    SIZES[size] || SIZES.md,
    block && "w-full",
    loading && "pointer-events-none",
    className
  );

  // Anchors have no native disabled/type; emulate disabled semantics for a11y.
  const disabledProps = isNativeButton
    ? { disabled: isDisabled, type: type || "button" }
    : {
        "aria-disabled": isDisabled || undefined,
        tabIndex: isDisabled ? -1 : rest.tabIndex,
        href: isDisabled ? undefined : href,
      };

  const spinnerSize = size === "sm" ? 14 : 16;

  return (
    <Component
      ref={ref}
      className={classes}
      href={href}
      {...disabledProps}
      {...rest}
    >
      {loading && (
        <span className="absolute inset-0 flex items-center justify-center">
          <Spinner size={spinnerSize} />
        </span>
      )}
      <span
        className={cx(
          "inline-flex items-center justify-center",
          size === "sm" ? "gap-1.5" : "gap-2",
          loading && "invisible"
        )}
      >
        {icon != null && (
          <span className="inline-flex shrink-0 items-center">{icon}</span>
        )}
        {children != null && <span>{children}</span>}
        {iconTrailing != null && (
          <span className="inline-flex shrink-0 items-center">
            {iconTrailing}
          </span>
        )}
      </span>
    </Component>
  );
});

export default Button;
