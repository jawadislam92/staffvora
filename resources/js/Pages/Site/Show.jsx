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
export default function Show({ page, theme, brand, nav, plans, isAuthed }) {
    const c = brand?.colors || {};
    const meta = page?.meta || {};

    // Palette comes from config/brand.php (the single source of truth) — change a
    // hex there to rebrand the whole site.
    const vars = {
        '--a': c.accent || '#4F46E5',
        '--a-deep': c.accent_deep || '#4338CA',
        '--g-from': c.grad_from || '#8B5CF6',
        '--g-to': c.grad_to || '#4F46E5',
        '--hl': c.highlight || '#A78BFA',
        '--brand': c.brand || '#1E1B4B',
        '--ink': c.ink || '#15132E',
        '--mint': c.mint || '#EDE9FE',
        '--paper': c.paper || '#F5F3FF',
        '--pink': c.pink || '#FCE7F3',
        '--lavender': c.lavender || '#EDE9FE',
        '--canvas': c.canvas || '#FFFFFF',
        '--surface': c.surface || '#ffffff',
        '--line': c.border || '#E9E7F5',
        '--text': c.text || '#1E1B2E',
        '--muted': c.muted || '#56536E',
        '--subtle': c.subtle || '#9794AE',
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
                <BlockRenderer blocks={page?.blocks || []} theme={theme} brand={brand} plans={plans} />
            </main>
            <SiteFooter nav={nav?.footer || []} brand={brand} theme={theme} />
        </div>
    );
}
