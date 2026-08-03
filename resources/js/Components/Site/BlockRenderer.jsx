import Hero from './blocks/Hero';
import LogoBar from './blocks/LogoBar';
import FeatureGrid from './blocks/FeatureGrid';
import FeatureSplit from './blocks/FeatureSplit';
import Stats from './blocks/Stats';
import Steps from './blocks/Steps';
import Testimonials from './blocks/Testimonials';
import Faq from './blocks/Faq';
import CtaBand from './blocks/CtaBand';
import RichText from './blocks/RichText';
import ImageBlock from './blocks/ImageBlock';
import PricingTable from './blocks/PricingTable';
import ContactBlock from './blocks/ContactBlock';
import TeamGrid from './blocks/TeamGrid';

// type → component. The builder only offers types that exist here.
export const BLOCK_REGISTRY = {
    hero: Hero,
    logo_bar: LogoBar,
    feature_grid: FeatureGrid,
    feature_split: FeatureSplit,
    stats: Stats,
    steps: Steps,
    testimonials: Testimonials,
    faq: Faq,
    cta_band: CtaBand,
    rich_text: RichText,
    image: ImageBlock,
    pricing_table: PricingTable,
    contact: ContactBlock,
    team_grid: TeamGrid,
};

// Optional per-section background (set in the builder). Light tints that keep
// text readable in both light + dark (no text-colour flip needed).
const SECTION_BG = {
    muted: 'bg-[var(--surface-2)]',
    soft: 'wash-tint',
    accent: 'sec-accent',
};

// LAST LINE OF DEFENCE for operator-authored block data.
//
// The real guard is server-side (App\Support\HtmlSanitizer, on write AND on
// read). This repeats the URL half of it here because React does NOT block
// `javascript:` in an href — the production react-dom bundle ships no URL
// sanitiser at all — so a single value that gets past the server becomes script
// execution on staffvora.com, the origin the customer app's session cookie
// belongs to. Blocks are small; the walk is cheap; the payoff is that no
// individual block component has to remember to do this.
//
// Keep this list in step with HtmlSanitizer::URL_KEYS.
const URL_KEYS = new Set([
    'url', 'href', 'src', 'link_url', 'image_url', 'avatar_url',
    'cover_url', 'logo_url', 'video_url', 'demo_url',
]);

// http(s)/mailto/tel or site-relative only. Control characters and HTML
// entities are stripped before the scheme test so `java&#09;script:` and
// `  JaVaScRiPt:` are caught; `//evil.test` is protocol-relative, not
// site-relative, so it is rejected too.
function safeHref(value) {
    if (typeof value !== 'string') return undefined;
    const probe = value
        .toLowerCase()
        .replace(/&#x?[0-9a-f]+;?/gi, '')
        .replace(/&(?:tab|newline|colon);/gi, '')
        .replace(/[\x00-\x20\x7f]/g, '');
    if (probe === '') return undefined;
    if (probe.startsWith('//') || probe.startsWith('/\\')) return undefined;
    if (probe.startsWith('#') || probe.startsWith('/')) return value;
    const scheme = probe.match(/^([a-z][a-z0-9+.-]*):/);
    if (scheme) return ['http', 'https', 'mailto', 'tel'].includes(scheme[1]) ? value : undefined;
    return value; // relative path such as `pricing`
}

function hardenBlockData(node) {
    if (Array.isArray(node)) return node.map(hardenBlockData);
    if (!node || typeof node !== 'object') return node;
    const out = {};
    for (const [key, value] of Object.entries(node)) {
        if (URL_KEYS.has(key)) out[key] = safeHref(value);
        // A non-string `html` is the type-confusion payload shape, never content.
        else if (key === 'html') out[key] = typeof value === 'string' ? value : undefined;
        else out[key] = hardenBlockData(value);
    }
    return out;
}

// Catalogues forwarded to every block. `plans` and `addons` are both LIVE
// pricing read from the database by Site\PageController — a block must never
// hardcode a price in its authored content, because the operator can change one
// in the panel and the public page would then advertise a figure we could be
// held to. Blocks that do not price anything simply ignore them.
export default function BlockRenderer({ blocks = [], theme, brand, plans, addons }) {
    return (
        <>
            {blocks.map((block) => {
                const Cmp = BLOCK_REGISTRY[block.type];
                if (!Cmp) return null;
                const bg = SECTION_BG[block.data?._bg];
                const data = hardenBlockData(block.data || {});
                return (
                    <div key={block.id} className={bg || undefined}>
                        <Cmp data={data} theme={theme} brand={brand} plans={plans} addons={addons} />
                    </div>
                );
            })}
        </>
    );
}
