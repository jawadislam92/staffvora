// Lightweight stroke icons used by editable feature blocks (referenced by key).
const P = (d) => (props) => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...props}>
        {d}
    </svg>
);

export const SITE_ICONS = {
    clock: P(<><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3.2 2" /></>),
    camera: P(<><rect x="3" y="5" width="18" height="13" rx="2" /><circle cx="12" cy="11.5" r="3" /></>),
    calendar: P(<><rect x="3" y="4" width="18" height="17" rx="2" /><path d="M3 9h18M8 2v4M16 2v4" /></>),
    chart: P(<path d="M4 19V5M4 19h16M8 16v-5M12 16V8M16 16v-3" />),
    slack: P(<><rect x="13" y="2" width="3" height="8" rx="1.5" /><rect x="8" y="14" width="3" height="8" rx="1.5" /><rect x="14" y="13" width="8" height="3" rx="1.5" /><rect x="2" y="8" width="8" height="3" rx="1.5" /></>),
    desktop: P(<><rect x="2" y="4" width="20" height="13" rx="2" /><path d="M8 21h8m-4-4v4" /></>),
    shield: P(<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z" />),
    bolt: P(<path d="M13 2L4 14h7l-1 8 9-12h-7z" />),
    users: P(<><circle cx="9" cy="8" r="3" /><path d="M3 20a6 6 0 0112 0M16 6a3 3 0 010 6M21 20a6 6 0 00-4-5.6" /></>),
    lock: P(<><rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V8a4 4 0 018 0v3" /></>),
    check: P(<path d="M20 6L9 17l-5-5" />),
    star: P(<path d="M12 3l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.8 6.2 21l1.1-6.5L2.6 9.8l6.5-.9z" />),
    globe: P(<><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c2.5 2.6 2.5 15.4 0 18M12 3c-2.5 2.6-2.5 15.4 0 18" /></>),
};

export function SiteIcon({ name, className }) {
    const Cmp = SITE_ICONS[name] || SITE_ICONS.bolt;
    return <Cmp className={className} />;
}
