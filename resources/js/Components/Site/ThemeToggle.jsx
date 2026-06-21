/**
 * Day / night switcher for the marketing site. A pill that slides a knob left↔
 * right while the icon crossfades + rotates between a sun and a moon. Purely
 * presentational — the parent owns the `mode` state and persistence.
 */
export default function ThemeToggle({ mode = 'light', onToggle, className = '' }) {
    const dark = mode === 'dark';
    return (
        <button
            type="button"
            onClick={onToggle}
            role="switch"
            aria-checked={dark}
            aria-label={dark ? 'Switch to day mode' : 'Switch to night mode'}
            title={dark ? 'Day mode' : 'Night mode'}
            className={`group relative inline-flex h-9 w-[58px] shrink-0 items-center rounded-full border border-[var(--line)] bg-[var(--surface-2)] px-1 transition-colors duration-300 hover:border-[color-mix(in_srgb,var(--a)_45%,var(--line))] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--a)] ${className}`}
        >
            {/* Sliding knob with the morphing icon */}
            <span
                className="relative flex h-7 w-7 items-center justify-center rounded-full text-white shadow-md transition-transform duration-300 ease-out"
                style={{
                    background: 'linear-gradient(135deg, var(--g-from), var(--g-to))',
                    transform: dark ? 'translateX(22px)' : 'translateX(0)',
                }}
            >
                <svg viewBox="0 0 24 24" className="h-4 w-4">
                    {/* Sun — fades/rotates out when going dark */}
                    <g
                        fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"
                        style={{
                            transformOrigin: 'center', transition: 'opacity .3s ease, transform .4s ease',
                            opacity: dark ? 0 : 1, transform: dark ? 'rotate(-90deg) scale(0.4)' : 'rotate(0) scale(1)',
                        }}
                    >
                        <circle cx="12" cy="12" r="4.2" fill="currentColor" stroke="none" />
                        <path d="M12 2.6v2.2M12 19.2v2.2M21.4 12h-2.2M4.8 12H2.6M18.4 5.6l-1.6 1.6M7.2 16.8l-1.6 1.6M18.4 18.4l-1.6-1.6M7.2 7.2L5.6 5.6" />
                    </g>
                    {/* Moon — fades/rotates in when going dark */}
                    <path
                        d="M21 12.8A8.5 8.5 0 1 1 11.2 3a6.6 6.6 0 0 0 9.8 9.8Z"
                        fill="currentColor"
                        style={{
                            transformOrigin: 'center', transition: 'opacity .3s ease, transform .4s ease',
                            opacity: dark ? 1 : 0, transform: dark ? 'rotate(0) scale(1)' : 'rotate(90deg) scale(0.4)',
                        }}
                    />
                </svg>
            </span>
        </button>
    );
}
