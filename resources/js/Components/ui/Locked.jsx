import React, { useCallback, useRef } from "react";
import { Link } from "@inertiajs/react";
import Button from "./Button";

const cx = (...a) => a.filter(Boolean).join(" ");

/**
 * The billing destination, resolved through Ziggy when the manifest is loaded
 * and degraded to the literal path when it is not (SSR, a stale manifest, or
 * the design-kit gallery which renders outside a real page).
 */
function billingHref() {
    try {
        // eslint-disable-next-line no-undef
        return route("billing.index");
    } catch {
        return "/billing";
    }
}

/**
 * LockGlyph — inline padlock. The kit stays icon-library-free on purpose so a
 * component can render in any context (including the static design gallery).
 */
export function LockGlyph({ className, ...rest }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            className={cx("h-4 w-4", className)}
            {...rest}
        >
            <rect x="4" y="10.5" width="16" height="10" rx="2" />
            <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" />
            <circle cx="12" cy="15.5" r="1.25" />
        </svg>
    );
}

/**
 * LockBadge — the small "Locked" pill. Use it on a nav item, a tab, a table
 * header or anywhere a full overlay would be too heavy. Purely decorative: the
 * surrounding control must still say what it does.
 *
 * @param {object} props
 * @param {React.ReactNode} [props.children="Locked"] Pill label.
 * @param {boolean} [props.iconOnly=false] Render just the padlock (with an accessible label).
 * @param {string} [props.title] Tooltip/accessible text when `iconOnly`.
 * @param {string} [props.className] Extra classes merged onto the root.
 */
export function LockBadge({
    children = "Locked",
    iconOnly = false,
    title = "Not included in your plan",
    className,
    ...rest
}) {
    if (iconOnly) {
        return (
            <span
                className={cx("inline-flex items-center text-subtle", className)}
                title={title}
                {...rest}
            >
                <LockGlyph className="h-3.5 w-3.5" />
                <span className="sr-only">{title}</span>
            </span>
        );
    }

    return (
        <span
            className={cx(
                "inline-flex items-center gap-1 rounded-full border border-line bg-surface-2 px-2 py-0.5",
                "text-[11px] font-medium leading-4 text-subtle",
                className
            )}
            title={title}
            {...rest}
        >
            <LockGlyph className="h-3 w-3" />
            {children}
        </span>
    );
}

/**
 * UpgradeCallout — the message shown over (or beside) locked content: lock,
 * feature name, one line on what it unlocks, and the upgrade CTA.
 * Exported so a page can drop the same wording somewhere a wrapper does not fit.
 */
export function UpgradeCallout({
    name = "This feature",
    blurb,
    cta = "Upgrade to unlock",
    href,
    secondary,
    compact = false,
    ctaRef,
    className,
}) {
    const target = href || billingHref();

    return (
        <div
            className={cx(
                "w-full max-w-md rounded-xl border border-line bg-surface text-center shadow-lg",
                compact ? "p-4" : "p-5 sm:p-6",
                className
            )}
        >
            <span className="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-surface-2 text-subtle ring-1 ring-line">
                <LockGlyph className="h-5 w-5" />
            </span>

            <h3 className={cx("mt-3 font-semibold text-body", compact ? "text-sm" : "text-base")}>
                {name} isn&apos;t in your plan
            </h3>

            {blurb ? (
                <p className="mx-auto mt-1 max-w-sm text-sm text-muted">{blurb}</p>
            ) : null}

            <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
                <Button
                    as={Link}
                    href={target}
                    ref={ctaRef}
                    size={compact ? "sm" : "md"}
                    icon={<LockGlyph className="h-3.5 w-3.5" />}
                >
                    {cta}
                </Button>
                {secondary}
            </div>
        </div>
    );
}

/**
 * LockedFeature — the app-wide "shown, not hidden" wrapper.
 *
 * A capability the workspace has not bought must still be VISIBLE: the real
 * control/card/section renders, visually de-emphasised, under a lock badge and
 * an "Upgrade to unlock" CTA. A customer who cannot see a feature cannot want
 * it — and a nav item that silently vanishes teaches nothing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS IS PRESENTATION ONLY. It is NOT access control.
 * ─────────────────────────────────────────────────────────────────────────────
 * The server decides, every time, through `entitlement:<key>` middleware
 * (App\Http\Middleware\EnsureEntitlement) which asks the one resolver
 * `Organization::can()`. This component only mirrors the shared `entitlements`
 * Inertia prop. Never gate anything that matters on it, and never wrap content
 * the user is not allowed to SEE — wrap content they are not allowed to USE.
 * The children keep rendering, so pass placeholder/sample-shaped content rather
 * than another tenant's data if the data itself is sensitive.
 *
 * Behaviour when locked:
 *  • children are inert: `pointer-events-none`, `aria-hidden`, the `inert`
 *    attribute where supported, and a focus trap-out that bounces keyboard
 *    focus onto the CTA — so nothing behind the veil can be clicked or tabbed
 *    into and produce a 403;
 *  • the CTA is a real link, reachable by keyboard, first in tab order;
 *  • all colours come from semantic tokens, so light / dark / black all work.
 *
 * @param {object} props
 * @param {boolean} [props.locked=true] When false, children render untouched (zero wrapper).
 * @param {string} [props.feature] Entitlement key, emitted as `data-locked-feature` for QA/analytics.
 * @param {string} [props.name] Human feature name, e.g. "Insights".
 * @param {string} [props.blurb] One line on what unlocking buys.
 * @param {string} [props.cta="Upgrade to unlock"] Primary CTA label.
 * @param {string} [props.href] Upgrade destination. Defaults to the billing page.
 * @param {React.ReactNode} [props.secondary] Extra node beside the CTA (e.g. a "See plans" ghost link).
 * @param {"overlay"|"panel"|"inline"} [props.variant="overlay"]
 *        overlay — children behind a veil with the callout on top (cards, sections, charts);
 *        panel   — the callout alone in a bordered surface (entry points with nothing to show);
 *        inline  — children dimmed in place with a lock pill + text CTA (single fields, buttons).
 * @param {number|string} [props.minHeight] Minimum height of the overlay area (defaults to 200 for `overlay`).
 * @param {string} [props.className] Extra classes merged onto the root.
 * @param {React.ReactNode} [props.children] The real feature UI.
 */
export default function LockedFeature({
    locked = true,
    feature,
    name = "This feature",
    blurb,
    cta = "Upgrade to unlock",
    href,
    secondary,
    variant = "overlay",
    minHeight,
    className,
    children,
    ...rest
}) {
    const ctaRef = useRef(null);

    // Keyboard safety net for browsers without `inert`: anything inside the
    // veiled content that still takes focus immediately hands it to the CTA,
    // so Tab can never reach a control whose request the server would refuse.
    const bounceFocus = useCallback((event) => {
        if (ctaRef.current && !ctaRef.current.contains(event.target)) {
            event.stopPropagation();
            ctaRef.current.focus();
        }
    }, []);

    if (!locked) {
        return children ?? null;
    }

    const label = `${name} — not included in your plan`;

    if (variant === "panel") {
        return (
            <div
                role="group"
                aria-label={label}
                data-locked-feature={feature}
                className={cx(
                    "flex justify-center rounded-xl border border-line bg-surface-2/60 px-4 py-10",
                    className
                )}
                {...rest}
            >
                <UpgradeCallout
                    name={name}
                    blurb={blurb}
                    cta={cta}
                    href={href}
                    secondary={secondary}
                    ctaRef={ctaRef}
                />
            </div>
        );
    }

    if (variant === "inline") {
        return (
            <div
                role="group"
                aria-label={label}
                data-locked-feature={feature}
                className={cx("space-y-2", className)}
                {...rest}
            >
                <div
                    // eslint-disable-next-line react/no-unknown-property
                    inert=""
                    aria-hidden="true"
                    onFocusCapture={bounceFocus}
                    className="pointer-events-none select-none opacity-50 saturate-50"
                >
                    {children}
                </div>
                <div className="flex flex-wrap items-center gap-2 rounded-lg border border-line bg-surface-2 px-3 py-2">
                    <LockBadge />
                    <span className="min-w-0 flex-1 text-xs text-muted">
                        {blurb || `${name} is available on a higher plan.`}
                    </span>
                    <Link
                        ref={ctaRef}
                        href={href || billingHref()}
                        className="rounded-md px-1.5 py-0.5 text-xs font-semibold text-accent underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        {cta}
                    </Link>
                </div>
            </div>
        );
    }

    // overlay (default)
    return (
        <div
            role="group"
            aria-label={label}
            data-locked-feature={feature}
            className={cx("relative isolate overflow-hidden rounded-xl", className)}
            style={{ minHeight: minHeight ?? 200 }}
            {...rest}
        >
            <div
                // eslint-disable-next-line react/no-unknown-property
                inert=""
                aria-hidden="true"
                onFocusCapture={bounceFocus}
                // Capped so a very tall section can never push the callout below
                // the fold — the greyed content is a hint, not the feature.
                className="pointer-events-none max-h-[26rem] select-none overflow-hidden opacity-40 saturate-50"
            >
                {children}
            </div>

            <div className="absolute inset-0 z-10 flex items-center justify-center bg-app/70 p-4 backdrop-blur-[2px]">
                <UpgradeCallout
                    name={name}
                    blurb={blurb}
                    cta={cta}
                    href={href}
                    secondary={secondary}
                    ctaRef={ctaRef}
                />
            </div>
        </div>
    );
}

/** `Locked` is the short alias the pages read best with. Same component. */
export const Locked = LockedFeature;
