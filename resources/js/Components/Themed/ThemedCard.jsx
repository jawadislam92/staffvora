import React from 'react';
import theme from '../../theme';

/**
 * ThemedCard - Consistent card component with various styles
 * 
 * @param {string} variant - Card style (light, glass, solid)
 * @param {string} padding - Card padding (sm, md, lg)
 * @param {React.ReactNode} children - Card content
 * @param {string} className - Additional custom classes
 */
export default function ThemedCard({ 
    variant = 'light',
    padding = 'md',
    children,
    className = ''
}) {
    const paddingClasses = {
        sm: 'p-4',
        md: 'p-6',
        lg: 'p-8',
    };

    const baseClasses = theme.components.card.base;
    const variantClasses = theme.components.card.variants[variant];
    const radiusClasses = theme.radius['2xl'];
    const paddingClass = paddingClasses[padding];

    return (
        <div className={`${baseClasses} ${variantClasses} ${radiusClasses} ${paddingClass} ${className}`}>
            {children}
        </div>
    );
}
