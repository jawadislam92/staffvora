// Schema that drives the builder's per-block edit forms. Keep in sync with the
// block components in Components/Site/blocks/*. Field types the editor renders:
// text · textarea · html · cta · image · select · list (repeatable) · stringlist.

const ICONS = ['clock', 'camera', 'calendar', 'chart', 'slack', 'desktop', 'shield', 'bolt', 'users', 'lock', 'check', 'star', 'globe'];

export const SCHEMAS = {
    hero: {
        label: 'Hero',
        fields: [
            { key: 'eyebrow', type: 'text', label: 'Eyebrow (small pill)' },
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'heading_highlight', type: 'text', label: 'Highlighted line (accent colour)' },
            { key: 'subhead', type: 'textarea', label: 'Subheading' },
            { key: 'primary_cta', type: 'cta', label: 'Primary button' },
            { key: 'secondary_cta', type: 'cta', label: 'Secondary button' },
            { key: 'note', type: 'text', label: 'Small note under the buttons' },
            { key: 'image_url', type: 'image', label: 'Hero image (optional — built-in mock if empty)' },
        ],
    },
    logo_bar: {
        label: 'Logo bar',
        fields: [
            { key: 'caption', type: 'text', label: 'Caption' },
            { key: 'logos', type: 'list', label: 'Logos', item: [
                { key: 'name', type: 'text', label: 'Name' },
                { key: 'image_url', type: 'image', label: 'Logo image' },
            ] },
        ],
    },
    feature_grid: {
        label: 'Feature grid',
        fields: [
            { key: 'anchor', type: 'text', label: 'Anchor id (for nav, e.g. features)' },
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'subhead', type: 'text', label: 'Subheading' },
            { key: 'features', type: 'list', label: 'Features', item: [
                { key: 'icon', type: 'select', label: 'Icon', options: ICONS },
                { key: 'title', type: 'text', label: 'Title' },
                { key: 'text', type: 'textarea', label: 'Text' },
            ] },
        ],
    },
    feature_split: {
        label: 'Feature + image',
        fields: [
            { key: 'eyebrow', type: 'text', label: 'Eyebrow' },
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'text', type: 'textarea', label: 'Text' },
            { key: 'bullets', type: 'stringlist', label: 'Bullets' },
            { key: 'image_url', type: 'image', label: 'Image (optional)' },
            { key: 'image_side', type: 'select', label: 'Image side', options: ['right', 'left'] },
        ],
    },
    stats: {
        label: 'Stats band',
        fields: [
            { key: 'stats', type: 'list', label: 'Stats', item: [
                { key: 'value', type: 'text', label: 'Value' },
                { key: 'label', type: 'text', label: 'Label' },
            ] },
        ],
    },
    steps: {
        label: 'Steps',
        fields: [
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'steps', type: 'list', label: 'Steps', item: [
                { key: 'n', type: 'text', label: 'Number' },
                { key: 'title', type: 'text', label: 'Title' },
                { key: 'text', type: 'textarea', label: 'Text' },
            ] },
        ],
    },
    testimonials: {
        label: 'Testimonials',
        fields: [
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'items', type: 'list', label: 'Reviews', item: [
                { key: 'quote', type: 'textarea', label: 'Quote' },
                { key: 'name', type: 'text', label: 'Name' },
                { key: 'role', type: 'text', label: 'Role' },
                { key: 'company', type: 'text', label: 'Company' },
                { key: 'rating', type: 'text', label: 'Rating (1–5)' },
                { key: 'avatar_url', type: 'image', label: 'Avatar' },
            ] },
        ],
    },
    faq: {
        label: 'FAQ',
        fields: [
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'items', type: 'list', label: 'Questions', item: [
                { key: 'q', type: 'text', label: 'Question' },
                { key: 'a', type: 'textarea', label: 'Answer' },
            ] },
        ],
    },
    cta_band: {
        label: 'CTA band',
        fields: [
            { key: 'anchor', type: 'text', label: 'Anchor id (e.g. contact)' },
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'text', type: 'textarea', label: 'Text' },
            { key: 'primary_cta', type: 'cta', label: 'Button' },
        ],
    },
    rich_text: {
        label: 'Rich text',
        fields: [
            { key: 'html', type: 'html', label: 'Content (HTML — headings, bold, links)' },
        ],
    },
    image: {
        label: 'Image',
        fields: [
            { key: 'image_url', type: 'image', label: 'Image' },
            { key: 'alt', type: 'text', label: 'Alt text' },
            { key: 'caption', type: 'text', label: 'Caption' },
        ],
    },
};

export const BLOCK_TYPES = Object.keys(SCHEMAS).map((type) => ({ type, label: SCHEMAS[type].label }));

const DEFAULTS = {
    hero: { eyebrow: 'New badge', heading: 'Your headline', heading_highlight: 'with a highlight.', subhead: 'A short, benefit-driven subheading goes here.', primary_cta: { label: 'Start free', url: '/register' }, secondary_cta: { label: 'Book a demo', url: '/#contact' }, note: 'No credit card required' },
    logo_bar: { caption: 'Trusted by great teams', logos: [] },
    feature_grid: { heading: 'Features', subhead: '', features: [{ icon: 'bolt', title: 'A feature', text: 'Describe it briefly.' }] },
    feature_split: { eyebrow: '', heading: 'A standout capability', text: 'Explain the benefit.', bullets: [], image_side: 'right' },
    stats: { stats: [{ value: '100%', label: 'Label' }] },
    steps: { heading: 'How it works', steps: [{ n: '1', title: 'Step one', text: 'Text.' }] },
    testimonials: { heading: 'What customers say', items: [{ quote: 'Great product.', name: 'Name', role: 'Role', rating: 5 }] },
    faq: { heading: 'FAQ', items: [{ q: 'A question?', a: 'The answer.' }] },
    cta_band: { heading: 'Ready to start?', text: '', primary_cta: { label: 'Start free', url: '/register' } },
    rich_text: { html: '<h2>Heading</h2><p>Your text…</p>' },
    image: { image_url: null, alt: '', caption: '' },
};

export function newBlock(type) {
    return { id: 'b' + Math.random().toString(36).slice(2, 10), type, data: JSON.parse(JSON.stringify(DEFAULTS[type] || {})) };
}

export function blankItem(itemFields) {
    const obj = {};
    itemFields.forEach((f) => { obj[f.key] = f.type === 'image' ? null : ''; });
    return obj;
}
