import { Head } from '@inertiajs/react';
import SiteHeader from '@/Components/Site/SiteHeader';
import SiteFooter from '@/Components/Site/SiteFooter';
import BlockRenderer from '@/Components/Site/BlockRenderer';

/**
 * Public marketing page. Deliberately a FIXED light palette (independent of the
 * app's dark theme) + the owner-chosen accent exposed as the `--a` / `--hl` CSS
 * vars, so blocks can theme themselves with `bg-[var(--a)]` / `text-[var(--a)]`.
 */
export default function Show({ page, theme, isAuthed }) {
    const accent = theme?.accent_color || '#f97316';
    const highlight = theme?.highlight_color || '#f59e0b';
    const meta = page?.meta || {};

    return (
        <div
            className="min-h-screen bg-white text-zinc-900 antialiased"
            style={{ '--a': accent, '--hl': highlight }}
        >
            <Head>
                <title>{meta.title || theme?.site_name || 'Tracksuite'}</title>
                {meta.description && <meta name="description" content={meta.description} />}
                <meta property="og:title" content={meta.title || theme?.site_name || 'Tracksuite'} />
                {meta.description && <meta property="og:description" content={meta.description} />}
                <meta name="twitter:card" content="summary_large_image" />
            </Head>

            <SiteHeader theme={theme} isAuthed={isAuthed} />
            <main>
                <BlockRenderer blocks={page?.blocks || []} theme={theme} />
            </main>
            <SiteFooter theme={theme} />
        </div>
    );
}
