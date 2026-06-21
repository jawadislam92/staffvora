import { Head } from '@inertiajs/react';
import SiteHeader from '@/Components/Site/SiteHeader';
import SiteFooter from '@/Components/Site/SiteFooter';
import BlockRenderer from '@/Components/Site/BlockRenderer';

/**
 * Public marketing page. Fixed LIGHT palette (independent of the dark app theme).
 * The whole brand palette is emitted as CSS custom properties on `.site-root`
 * from config/brand.php (passed as `brand`), so no block hardcodes a hex — and
 * the builder's theme.accent_color/highlight can still override the accent.
 */
export default function Show({ page, theme, brand, nav, isAuthed }) {
    const c = brand?.colors || {};
    const meta = page?.meta || {};

    const vars = {
        '--a': theme?.accent_color || c.accent || '#4F9E78',
        '--a-deep': c.accent_deep || '#3C8763',
        '--hl': theme?.highlight_color || c.highlight || '#86C9A4',
        '--brand': c.brand || '#1C3D2F',
        '--ink': c.ink || '#15201B',
        '--mint': c.mint || '#CFE6D7',
        '--paper': c.paper || '#F4F1E9',
        '--canvas': c.canvas || '#FBFAF6',
        '--surface': c.surface || '#ffffff',
        '--line': c.border || '#E4E2D8',
        '--text': c.text || '#15201B',
        '--muted': c.muted || '#4A5650',
        '--subtle': c.subtle || '#8A938C',
    };

    return (
        <div className="site-root min-h-screen" style={{ ...vars, background: 'var(--canvas)', color: 'var(--text)' }}>
            <Head>
                <title>{meta.title || brand?.name || 'Tracksuite'}</title>
                {meta.description && <meta name="description" content={meta.description} />}
                {meta.canonical && <link rel="canonical" href={meta.canonical} />}
                {meta.robots && <meta name="robots" content={meta.robots} />}
                <meta property="og:title" content={meta.title || brand?.name || 'Tracksuite'} />
                {meta.description && <meta property="og:description" content={meta.description} />}
                <meta property="og:type" content="website" />
                <meta name="twitter:card" content="summary_large_image" />
            </Head>

            <SiteHeader nav={nav?.header || []} brand={brand} theme={theme} isAuthed={isAuthed} />
            <main>
                <BlockRenderer blocks={page?.blocks || []} theme={theme} brand={brand} />
            </main>
            <SiteFooter nav={nav?.footer || []} brand={brand} theme={theme} />
        </div>
    );
}
