import React from 'react';
import theme from '../../theme';

/**
 * ThemedButton - Consistent button component with various styles
 * 
 * @param {string} variant - Button style (primary, secondary, success, danger)
 * @param {string} size - Button size (sm, md, lg)
 * @param {string} type - Button type (button, submit, reset)
 * @param {boolean} disabled - Disabled state
 * @param {function} onClick - Click handler
 * @param {React.ReactNode} children - Button content
 * @param {string} className - Additional custom classes
 */
export default function ThemedButton({ 
    variant = 'primary',
    size = 'md',
    type = 'button',
    disabled = false,
    onClick,
    children,
    className = ''
}) {
    const baseClasses = theme.components.button.base;
    const sizeClasses = theme.components.button.sizes[size];
    const variantClasses = theme.components.button.variants[variant];
    const disabledClasses = disabled ? 'opacity-50 cursor-not-allowed' : '';

    return (
        <button
            type={type}
            disabled={disabled}
            onClick={onClick}
            className={`${baseClasses} ${sizeClasses} ${variantClasses} ${disabledClasses} ${className}`}
        >
            {children}
        </button>
    );
}
