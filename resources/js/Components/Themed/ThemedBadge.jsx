import React from 'react';
import theme from '../../theme';

/**
 * ThemedBadge - Consistent badge/pill component with various styles
 * 
 * @param {string} variant - Badge style (success, warning, error, info, neutral)
 * @param {React.ReactNode} children - Badge content
 * @param {string} className - Additional custom classes
 */
export default function ThemedBadge({ 
    variant = 'neutral',
    children,
    className = ''
}) {
    const baseClasses = theme.components.badge.base;
    const variantClasses = theme.components.badge.variants[variant];

    return (
        <span className={`${baseClasses} ${variantClasses} ${className}`}>
            {children}
        </span>
    );
}
