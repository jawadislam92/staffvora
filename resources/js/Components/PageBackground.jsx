/**
 * PageBackground Component
 * 
 * A reusable, optimized background component for consistent styling
 * across all application pages. Uses the same beautiful gradient as
 * Dashboard and Attendance pages.
 * 
 * Features:
 * - Beautiful subtle gradient (slate-50 to blue-50)
 * - Optimized for performance (CSS-only, no JS)
 * - Works perfectly with sidebar layout
 * - Responsive and lightweight
 */

export default function PageBackground({ children, className = '' }) {
    return (
        <div className={`bg-gradient-to-br from-slate-50 to-blue-50/30 min-h-screen ${className}`}>
            {children}
        </div>
    );
}

/**
 * Alternative: Pattern Background (for special pages)
 * Adds a subtle dot pattern for visual interest
 */
export function PageBackgroundWithPattern({ children, className = '' }) {
    return (
        <div 
            className={`bg-gradient-to-br from-slate-50 to-blue-50/30 min-h-screen ${className}`}
            style={{
                backgroundImage: `
                    radial-gradient(circle at 2px 2px, rgb(148 163 184 / 0.05) 1px, transparent 0)
                `,
                backgroundSize: '32px 32px'
            }}
        >
            {children}
        </div>
    );
}

/**
 * Content Container
 * Standard content wrapper with optimal padding
 */
export function PageContent({ children, className = '' }) {
    return (
        <div className={`px-4 sm:px-6 lg:px-8 py-6 ${className}`}>
            {children}
        </div>
    );
}
