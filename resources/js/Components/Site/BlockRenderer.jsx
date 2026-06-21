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

export default function BlockRenderer({ blocks = [], theme, brand, plans }) {
    return (
        <>
            {blocks.map((block) => {
                const Cmp = BLOCK_REGISTRY[block.type];
                if (!Cmp) return null;
                return <Cmp key={block.id} data={block.data || {}} theme={theme} brand={brand} plans={plans} />;
            })}
        </>
    );
}
