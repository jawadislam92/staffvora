import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

// Build a compact, windowed list of page numbers with ellipsis gaps, so a
// paginator with hundreds of pages never renders hundreds of controls.
function buildWindow(current, last, delta) {
    const shown = [];
    for (let i = 1; i <= last; i += 1) {
        if (i === 1 || i === last || (i >= current - delta && i <= current + delta)) {
            shown.push(i);
        }
    }
    const out = [];
    let prev = 0;
    for (const p of shown) {
        if (prev && p - prev > 1) out.push('…');
        out.push(p);
        prev = p;
    }
    return out;
}

const cellBase =
    'inline-flex h-10 min-w-10 items-center justify-center rounded-lg px-3 text-sm font-medium ' +
    'transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent';

/**
 * TraditionalPagination — token-themed, responsive paginator.
 *
 * - Uses the app's semantic tokens (bg-surface / border-line / text-* /
 *   bg-accent), so it re-themes from `data-theme` and no longer needs the old
 *   hand-switched `dark` palette prop (still accepted and ignored for callers
 *   that pass it).
 * - Below `sm` it renders a compact "‹ Prev · Page X of Y · Next ›" row whose
 *   controls are all ≥40px and always on-screen — the previous numbered row was
 *   773px wide and ran off the left edge of a phone.
 * - The numbered row is windowed with ellipses and wraps, so it can never push
 *   the page into horizontal scroll regardless of page count.
 */
export function TraditionalPagination({
    pagination,
    className = '',
    preserveState = true,
    preserveScroll = false,
    // Legacy prop — the component is now fully token-driven. Accepted so existing
    // call sites don't spread it onto the DOM, but intentionally unused.
    // eslint-disable-next-line no-unused-vars
    dark = false,
}) {
    if (!pagination) return null;

    const { links = [], from, to, total } = pagination;

    // Numeric page links carry the canonical URLs (with any appended filters).
    const numeric = links.filter((l) => /^\d+$/.test(String(l.label).trim()));
    const urlByPage = new Map(numeric.map((l) => [Number(l.label), l.url]));

    const current =
        pagination.current_page ??
        Number(numeric.find((l) => l.active)?.label) ??
        1;
    const last =
        pagination.last_page ??
        (numeric.length ? Number(numeric[numeric.length - 1].label) : 1);

    const prevUrl = pagination.prev_page_url ?? links[0]?.url ?? null;
    const nextUrl = pagination.next_page_url ?? links[links.length - 1]?.url ?? null;

    const NavArrow = ({ url, label, children }) =>
        url ? (
            <Link
                href={url}
                preserveState={preserveState}
                preserveScroll={preserveScroll}
                aria-label={label}
                className={`${cellBase} border border-line bg-surface text-muted hover:bg-surface-2 hover:text-body`}
            >
                {children}
            </Link>
        ) : (
            <span
                aria-disabled="true"
                aria-label={label}
                className={`${cellBase} border border-line bg-surface text-subtle opacity-60`}
            >
                {children}
            </span>
        );

    return (
        <div
            className={`flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between ${className}`}
        >
            {/* Results info */}
            <div className="text-sm font-medium text-muted">
                Showing <span className="font-semibold text-body">{from ?? 0}</span> to{' '}
                <span className="font-semibold text-body">{to ?? 0}</span> of{' '}
                <span className="font-semibold text-body">{total ?? 0}</span> results
            </div>

            {/* Compact mobile pager (< sm): always reachable, ≥40px targets. */}
            <div className="flex items-center justify-between gap-3 sm:hidden">
                <NavArrow url={prevUrl} label="Previous page">
                    <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                    <span className="ml-1">Prev</span>
                </NavArrow>
                <span className="text-sm font-medium text-muted">
                    Page <span className="font-semibold text-body">{current}</span> of{' '}
                    <span className="font-semibold text-body">{last}</span>
                </span>
                <NavArrow url={nextUrl} label="Next page">
                    <span className="mr-1">Next</span>
                    <ChevronRight className="h-4 w-4" aria-hidden="true" />
                </NavArrow>
            </div>

            {/* Numbered pager (sm+): windowed + wraps, never overflows. */}
            <div className="hidden flex-wrap items-center justify-end gap-1 sm:flex">
                <NavArrow url={prevUrl} label="Previous page">
                    <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                </NavArrow>

                {buildWindow(current, last, 2).map((p, i) => {
                    if (p === '…') {
                        return (
                            <span
                                key={`gap-${i}`}
                                className="inline-flex h-10 min-w-10 items-center justify-center px-2 text-sm text-subtle"
                            >
                                …
                            </span>
                        );
                    }
                    if (p === current) {
                        return (
                            <span
                                key={p}
                                aria-current="page"
                                className={`${cellBase} bg-accent font-semibold text-white shadow-sm`}
                            >
                                {p}
                            </span>
                        );
                    }
                    return (
                        <Link
                            key={p}
                            href={urlByPage.get(p) ?? '#'}
                            preserveState={preserveState}
                            preserveScroll={preserveScroll}
                            aria-label={`Go to page ${p}`}
                            className={`${cellBase} border border-line bg-surface text-muted hover:bg-surface-2 hover:text-body`}
                        >
                            {p}
                        </Link>
                    );
                })}

                <NavArrow url={nextUrl} label="Next page">
                    <ChevronRight className="h-4 w-4" aria-hidden="true" />
                </NavArrow>
            </div>
        </div>
    );
}

export default TraditionalPagination;
