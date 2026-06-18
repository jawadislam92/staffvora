/**
 * Global Theme Configuration
 * Centralized styling constants for the entire application
 */

export const theme = {
    // Typography
    fonts: {
        sans: 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        mono: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
        display: 'Inter, sans-serif',
    },

    // Font Sizes
    fontSize: {
        xs: '0.75rem',      // 12px
        sm: '0.875rem',     // 14px
        base: '1rem',       // 16px
        lg: '1.125rem',     // 18px
        xl: '1.25rem',      // 20px
        '2xl': '1.5rem',    // 24px
        '3xl': '1.875rem',  // 30px
        '4xl': '2.25rem',   // 36px
        '5xl': '3rem',      // 48px
    },

    // Layout & Spacing
    layout: {
        // Page widths
        maxWidth: {
            sm: '640px',
            md: '768px',
            lg: '1024px',
            xl: '1280px',
            '2xl': '1536px',
            full: '100%',
        },
        // Container padding
        containerPadding: {
            mobile: 'px-4',      // 16px
            tablet: 'sm:px-6',   // 24px
            desktop: 'lg:px-8',  // 32px
        },
        // Vertical spacing
        sectionSpacing: {
            tight: 'py-4',       // 16px
            normal: 'py-6',      // 24px
            relaxed: 'py-8',     // 32px
            loose: 'py-12',      // 48px
        },
        // Sidebar
        sidebar: {
            width: 'w-64',       // 256px
            collapsedWidth: 'w-16', // 64px
        },
    },

    // Background Gradients
    backgrounds: {
        // Main page background - light, clean
        page: 'bg-gradient-to-br from-slate-50 to-blue-50/30',
        
        // Sidebar - dark, professional
        sidebar: 'bg-gradient-to-b from-slate-900 to-slate-800',
        
        // Card backgrounds
        card: {
            light: 'bg-white/95 backdrop-blur-xl',
            glass: 'bg-white/10 backdrop-blur-xl',
            gradient: 'bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl',
        },
        
        // Hero sections
        hero: 'bg-gradient-to-br from-blue-50 via-white to-purple-50/30',
        
        // Status backgrounds
        status: {
            success: 'bg-gradient-to-r from-green-500 to-emerald-600',
            warning: 'bg-gradient-to-r from-yellow-500 to-orange-600',
            error: 'bg-gradient-to-r from-red-500 to-pink-600',
            info: 'bg-gradient-to-r from-blue-500 to-cyan-600',
        },
    },

    // Blur Effects
    blur: {
        none: 'backdrop-blur-none',
        sm: 'backdrop-blur-sm',      // 4px
        md: 'backdrop-blur-md',      // 12px
        lg: 'backdrop-blur-lg',      // 16px
        xl: 'backdrop-blur-xl',      // 24px
        '2xl': 'backdrop-blur-2xl',  // 40px
        '3xl': 'backdrop-blur-3xl',  // 64px
    },

    // Shadows
    shadows: {
        sm: 'shadow-sm',
        md: 'shadow-md',
        lg: 'shadow-lg',
        xl: 'shadow-xl',
        '2xl': 'shadow-2xl',
        inner: 'shadow-inner',
        none: 'shadow-none',
        // Custom colored shadows
        colored: {
            blue: 'shadow-lg shadow-blue-500/20',
            purple: 'shadow-lg shadow-purple-500/20',
            pink: 'shadow-lg shadow-pink-500/20',
            green: 'shadow-lg shadow-green-500/20',
        },
    },

    // Border Radius
    radius: {
        none: 'rounded-none',
        sm: 'rounded-sm',
        md: 'rounded-md',
        lg: 'rounded-lg',
        xl: 'rounded-xl',
        '2xl': 'rounded-2xl',
        '3xl': 'rounded-3xl',
        full: 'rounded-full',
    },

    // Colors (Tailwind-based)
    colors: {
        // Primary brand colors
        primary: {
            light: 'blue-400',
            DEFAULT: 'blue-500',
            dark: 'blue-600',
        },
        secondary: {
            light: 'purple-400',
            DEFAULT: 'purple-500',
            dark: 'purple-600',
        },
        // Accent colors
        accent: {
            teal: 'teal-500',
            cyan: 'cyan-500',
            indigo: 'indigo-500',
            pink: 'pink-500',
        },
        // Status colors
        status: {
            success: 'green-500',
            warning: 'yellow-500',
            error: 'red-500',
            info: 'blue-500',
        },
        // Neutral colors
        neutral: {
            50: 'slate-50',
            100: 'slate-100',
            200: 'slate-200',
            300: 'slate-300',
            400: 'slate-400',
            500: 'slate-500',
            600: 'slate-600',
            700: 'slate-700',
            800: 'slate-800',
            900: 'slate-900',
        },
    },

    // Transitions & Animations
    transitions: {
        fast: 'transition-all duration-150 ease-in-out',
        normal: 'transition-all duration-200 ease-in-out',
        slow: 'transition-all duration-300 ease-in-out',
        slower: 'transition-all duration-500 ease-in-out',
    },

    // Component Styles
    components: {
        // Button styles
        button: {
            base: 'inline-flex items-center justify-center font-medium transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2',
            sizes: {
                sm: 'px-3 py-1.5 text-sm rounded-lg',
                md: 'px-4 py-2 text-base rounded-xl',
                lg: 'px-6 py-3 text-lg rounded-xl',
            },
            variants: {
                primary: 'bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 text-white shadow-lg hover:shadow-xl',
                secondary: 'bg-white/10 hover:bg-white/20 text-white backdrop-blur-xl border border-white/20',
                success: 'bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white',
                danger: 'bg-gradient-to-r from-red-500 to-pink-600 hover:from-red-600 hover:to-pink-700 text-white',
            },
        },

        // Input styles
        input: {
            base: 'w-full px-4 py-3 rounded-xl border focus:ring-2 focus:border-transparent transition-all duration-200',
            variants: {
                light: 'bg-white border-slate-300 focus:ring-blue-500 text-slate-900',
                glass: 'bg-white/10 backdrop-blur-xl border-white/20 focus:ring-blue-400 text-white placeholder-white/50',
            },
        },

        // Card styles
        card: {
            base: 'overflow-hidden border',
            variants: {
                light: 'bg-white/95 backdrop-blur-xl shadow-2xl border-white/20',
                glass: 'bg-gradient-to-br from-white/10 to-white/5 backdrop-blur-xl shadow-2xl border-white/10',
                solid: 'bg-white shadow-lg border-slate-200',
            },
        },

        // Badge styles
        badge: {
            base: 'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
            variants: {
                success: 'bg-green-100 text-green-700 border border-green-200',
                warning: 'bg-yellow-100 text-yellow-700 border border-yellow-200',
                error: 'bg-red-100 text-red-700 border border-red-200',
                info: 'bg-blue-100 text-blue-700 border border-blue-200',
                neutral: 'bg-slate-100 text-slate-700 border border-slate-200',
            },
        },
    },

    // Z-Index layers
    zIndex: {
        background: '-z-10',
        base: 'z-0',
        content: 'z-10',
        dropdown: 'z-20',
        sticky: 'z-30',
        sidebar: 'z-40',
        modal: 'z-50',
        popover: 'z-60',
        tooltip: 'z-70',
    },
};

// Helper functions for combining classes
export const getPageClasses = () => {
    return `${theme.backgrounds.page} min-h-screen`;
};

export const getContainerClasses = (maxWidth = 'xl') => {
    return `max-w-${maxWidth} mx-auto ${theme.layout.containerPadding.mobile} ${theme.layout.containerPadding.tablet} ${theme.layout.containerPadding.desktop}`;
};

export const getCardClasses = (variant = 'light') => {
    return `${theme.components.card.base} ${theme.components.card.variants[variant]} ${theme.radius['2xl']}`;
};

export const getButtonClasses = (variant = 'primary', size = 'md') => {
    return `${theme.components.button.base} ${theme.components.button.sizes[size]} ${theme.components.button.variants[variant]}`;
};

export const getInputClasses = (variant = 'light') => {
    return `${theme.components.input.base} ${theme.components.input.variants[variant]}`;
};

export const getBadgeClasses = (variant = 'neutral') => {
    return `${theme.components.badge.base} ${theme.components.badge.variants[variant]}`;
};

export default theme;
