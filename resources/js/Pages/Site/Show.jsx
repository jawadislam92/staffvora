import SiteShell, { siteEffectiveBrand } from '@/Components/Site/SiteShell';
import BlockRenderer from '@/Components/Site/BlockRenderer';

/**
 * Public marketing block-page. The shared SiteShell provides the palette +
 * day/night switcher, header, footer and SEO; this just renders the page's
 * blocks inside it (with the builder-effective brand so contact overrides apply).
 */
export default function Show({ page, theme, brand, nav, plans, jsonLd, isAuthed }) {
    const eb = siteEffectiveBrand(brand, theme);
    return (
        <SiteShell brand={brand} theme={theme} nav={nav} jsonLd={jsonLd} isAuthed={isAuthed} meta={page?.meta || {}}>
            <BlockRenderer blocks={page?.blocks || []} theme={theme} brand={eb} plans={plans} />
        </SiteShell>
    );
}
