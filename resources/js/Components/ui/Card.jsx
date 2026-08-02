import React from "react";

const cx = (...a) => a.filter(Boolean).join(" ");

/**
 * CardHeader — the header row used by Card.
 *
 * @param {object} props
 * @param {React.ReactNode} [props.title]       Heading text/node (left).
 * @param {React.ReactNode} [props.description]  Supporting text/node under the title.
 * @param {React.ReactNode} [props.actions]      Node shown at the header right.
 * @param {string}          [props.className]    Extra classes merged onto the root.
 */
export function CardHeader({ title, description, actions, className, ...rest }) {
  return (
    <div
      className={cx(
        "flex items-start justify-between gap-4 px-5 py-4 border-b border-line",
        className
      )}
      {...rest}
    >
      <div className="min-w-0">
        {title != null && (
          <div className="text-body font-semibold">{title}</div>
        )}
        {description != null && (
          <p className="text-sm text-muted mt-0.5">{description}</p>
        )}
      </div>
      {actions != null && (
        <div className="flex-shrink-0 flex items-center gap-2">{actions}</div>
      )}
    </div>
  );
}

/**
 * Card — a surface panel with an optional header and footer.
 *
 * @param {object} props
 * @param {React.ReactNode} [props.title]        Header title (left). Ignored if `header` is set.
 * @param {React.ReactNode} [props.description]  Header description (left). Ignored if `header` is set.
 * @param {React.ReactNode} [props.actions]      Node shown at the header right.
 * @param {React.ReactNode} [props.header]       Custom header node overriding title/description/actions.
 * @param {React.ReactNode} [props.footer]       Node rendered in a bottom footer row.
 * @param {boolean}         [props.padded=true]  Whether the body gets p-5 padding.
 * @param {string}          [props.className]    Extra classes merged onto the root.
 * @param {React.ReactNode} [props.children]     Body content.
 */
export default function Card({
  title,
  description,
  actions,
  header,
  footer,
  padded = true,
  className,
  children,
  ...rest
}) {
  const hasHeader = header != null || title != null || description != null;

  return (
    <div
      className={cx(
        "bg-surface border border-line rounded-xl shadow-sm",
        className
      )}
      {...rest}
    >
      {hasHeader &&
        (header != null ? (
          header
        ) : (
          <CardHeader title={title} description={description} actions={actions} />
        ))}

      {children != null && (
        <div className={cx(padded && "p-5")}>{children}</div>
      )}

      {footer != null && (
        <div className="px-5 py-4 border-t border-line">{footer}</div>
      )}
    </div>
  );
}
