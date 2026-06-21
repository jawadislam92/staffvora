// The Tracksuite mark — an open progress-ring with a single measured arc (from
// the "Measured System" brand guidelines). Reads as time / completion / calm
// focus, never a watching eye. Three tones for light, dark and single-colour use.
export function SiteMark({ size = 28, tone = 'brand' }) {
    const ring = tone === 'reversed' ? 'rgba(255,255,255,.32)' : tone === 'mono' ? 'rgba(28,61,47,.28)' : '#BFD7C7';
    const arc = tone === 'reversed' ? '#86C9A4' : tone === 'mono' ? '#1C3D2F' : 'var(--a, #4F9E78)';
    return (
        <svg width={size} height={size} viewBox="0 0 48 48" fill="none" aria-hidden="true" className="shrink-0">
            <circle cx="24" cy="24" r="16" stroke={ring} strokeWidth="3.5" />
            <path d="M24 8 A16 16 0 0 1 39.03 29.47" stroke={arc} strokeWidth="3.5" strokeLinecap="round" />
            <circle cx="39.03" cy="29.47" r="3.4" fill={arc} />
        </svg>
    );
}

// Full lockup: mark + wordmark. `tone` reversed = on dark/evergreen surfaces.
export default function SiteLogo({ name = 'Tracksuite', tone = 'brand', size = 28, className = '', textClass = 'text-lg' }) {
    const wordColor = tone === 'reversed' ? '#ffffff' : 'var(--brand, #1C3D2F)';
    return (
        <span className={`inline-flex items-center gap-2.5 ${className}`}>
            <SiteMark size={size} tone={tone} />
            <span className={`font-bold tracking-tight ${textClass}`} style={{ color: wordColor, letterSpacing: '-0.03em' }}>{name}</span>
        </span>
    );
}
