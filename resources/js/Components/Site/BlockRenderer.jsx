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
                return (
                    <div key={block.id} className={bg || undefined}>
                        <Cmp data={block.data || {}} theme={theme} brand={brand} plans={plans} addons={addons} />
                    </div>
                );
            })}
        </>
    );
}
