import { Children, cloneElement, isValidElement, useId } from "react";

const cx = (...a) => a.filter(Boolean).join(" ");

/** Shared control base styling for text-like inputs. */
const controlBase =
  "w-full rounded-md bg-surface-2 border border-line text-body placeholder:text-subtle px-3 py-2 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app disabled:opacity-50 disabled:cursor-not-allowed";

/**
 * Field — labelled wrapper for a form control.
 * @param {object} props
 * @param {React.ReactNode} [props.label] - Label text shown above the control.
 * @param {React.ReactNode} [props.hint] - Helper text shown below (hidden when error is set).
 * @param {React.ReactNode} [props.error] - Error text shown below; replaces hint when present.
 * @param {boolean} [props.required] - Renders a red asterisk after the label.
 * @param {string} [props.htmlFor] - id of the control the label points to.
 * @param {React.ReactNode} props.children - The control(s) to render.
 * @param {string} [props.className] - Extra classes merged onto the root <div>.
 */
export function Field({
  label,
  hint,
  error,
  required,
  htmlFor,
  children,
  className,
  ...rest
}) {
  const reactId = useId();
  const errorId = `${reactId}-error`;
  const hintId = `${reactId}-hint`;
  const hasError = error != null && error !== false;
  const showHint = !hasError && hint != null;

  // Programmatically tie the control to its error/hint text so screen readers
  // announce the failure and can jump to it. The control is passed as children,
  // so we clone the single control element to inject the ARIA wiring; when a
  // caller passes multiple children (e.g. a select plus a conditional input)
  // we skip cloning rather than guess which one is the field.
  const describedBy =
    [hasError ? errorId : null, showHint ? hintId : null]
      .filter(Boolean)
      .join(" ") || undefined;

  let control = children;
  const onlyChild =
    Children.count(children) === 1 ? Children.only(children) : null;
  if (isValidElement(onlyChild)) {
    const existingDescribedBy = onlyChild.props["aria-describedby"];
    control = cloneElement(onlyChild, {
      "aria-invalid": hasError ? true : onlyChild.props["aria-invalid"],
      "aria-describedby":
        [existingDescribedBy, describedBy].filter(Boolean).join(" ") ||
        undefined,
    });
  }

  return (
    <div className={cx("w-full", className)} {...rest}>
      {label != null && (
        <label
          htmlFor={htmlFor}
          className="block text-sm font-medium text-body mb-1.5"
        >
          {label}
          {required && (
            <span className="text-danger ml-0.5" aria-hidden="true">
              *
            </span>
          )}
        </label>
      )}
      {control}
      {hasError ? (
        <p id={errorId} role="alert" className="text-xs text-danger mt-1">
          {error}
        </p>
      ) : (
        showHint && (
          <p id={hintId} className="text-xs text-subtle mt-1">
            {hint}
          </p>
        )
      )}
    </div>
  );
}

/**
 * Input — text-like input using the shared control base.
 * @param {object} props
 * @param {string} [props.type] - Input type (default "text").
 * @param {string} [props.className] - Extra classes merged onto the root.
 */
export function Input({ type = "text", className, ...rest }) {
  return <input type={type} className={cx(controlBase, className)} {...rest} />;
}

/**
 * Select — dropdown using the shared control base with room for a caret.
 * @param {object} props
 * @param {React.ReactNode} props.children - <option> elements.
 * @param {string} [props.className] - Extra classes merged onto the root.
 */
export function Select({ children, className, ...rest }) {
  return (
    <select className={cx(controlBase, "pr-8 cursor-pointer", className)} {...rest}>
      {children}
    </select>
  );
}

/**
 * Textarea — multi-line input using the shared control base.
 * @param {object} props
 * @param {number} [props.rows] - Visible text rows (default 4).
 * @param {string} [props.className] - Extra classes merged onto the root.
 */
export function Textarea({ rows = 4, className, ...rest }) {
  return (
    <textarea
      rows={rows}
      className={cx(controlBase, "min-h-[6rem] resize-y", className)}
      {...rest}
    />
  );
}

/**
 * Checkbox — accent-colored checkbox with an optional inline label.
 * @param {object} props
 * @param {React.ReactNode} [props.label] - Text shown to the right of the box.
 * @param {string} [props.id] - Element id (auto-generated when omitted).
 * @param {string} [props.className] - Extra classes merged onto the wrapping <label>.
 */
export function Checkbox({ label, id, className, ...rest }) {
  const autoId = useId();
  const fieldId = id || autoId;
  return (
    <label
      htmlFor={fieldId}
      className={cx(
        "inline-flex items-center gap-2 cursor-pointer text-sm text-body select-none",
        rest.disabled && "opacity-50 cursor-not-allowed",
        className
      )}
    >
      <input
        id={fieldId}
        type="checkbox"
        className="h-4 w-4 rounded border-line text-accent bg-surface-2 transition cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app disabled:opacity-50 disabled:cursor-not-allowed"
        {...rest}
      />
      {label != null && <span>{label}</span>}
    </label>
  );
}

/**
 * Toggle — accessible on/off switch (role="switch").
 * @param {object} props
 * @param {boolean} [props.checked] - Whether the switch is on.
 * @param {(next: boolean) => void} [props.onChange] - Called with the next state on toggle.
 * @param {React.ReactNode} [props.label] - Optional text shown beside the switch.
 * @param {boolean} [props.disabled] - Disables interaction.
 * @param {string} [props.id] - Element id (auto-generated when omitted).
 * @param {string} [props.className] - Extra classes merged onto the wrapping <label>.
 */
export function Toggle({
  checked = false,
  onChange,
  label,
  disabled,
  id,
  className,
  ...rest
}) {
  const autoId = useId();
  const fieldId = id || autoId;
  const labelId = `${fieldId}-label`;

  // A <button> is NOT a labelable element, so the wrapping <label htmlFor> does
  // nothing for its accessible name — the sibling text span was invisible to
  // screen readers. Point the switch at that span with aria-labelledby when a
  // label is given; otherwise fall back to whatever aria-label the caller
  // spread in via {...rest}. In dev, warn when a switch would ship nameless.
  const hasSpreadAriaLabel = rest["aria-label"] != null;
  if (
    import.meta.env?.DEV &&
    label == null &&
    !hasSpreadAriaLabel
  ) {
    // eslint-disable-next-line no-console
    console.warn(
      "Toggle: no accessible name — pass a `label` or an `aria-label`."
    );
  }

  return (
    <label
      htmlFor={fieldId}
      className={cx(
        "inline-flex items-center gap-2.5 text-sm text-body select-none",
        disabled ? "opacity-50 cursor-not-allowed" : "cursor-pointer",
        className
      )}
    >
      <button
        id={fieldId}
        type="button"
        role="switch"
        aria-checked={checked}
        aria-labelledby={label != null ? labelId : undefined}
        disabled={disabled}
        onClick={() => onChange && onChange(!checked)}
        className={cx(
          "relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border border-line transition cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app disabled:opacity-50 disabled:cursor-not-allowed",
          checked ? "bg-accent" : "bg-surface-2"
        )}
        {...rest}
      >
        <span
          className={cx(
            "inline-block h-5 w-5 transform rounded-full bg-white shadow-sm transition",
            checked ? "translate-x-5" : "translate-x-0.5"
          )}
        />
      </button>
      {label != null && <span id={labelId}>{label}</span>}
    </label>
  );
}

export default Field;
