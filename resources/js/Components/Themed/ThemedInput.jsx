import React from 'react';
import theme from '../../theme';

/**
 * ThemedInput - Consistent input component with various styles
 * 
 * @param {string} variant - Input style (light, glass)
 * @param {string} type - Input type
 * @param {string} value - Input value
 * @param {function} onChange - Change handler
 * @param {string} placeholder - Placeholder text
 * @param {boolean} disabled - Disabled state
 * @param {boolean} required - Required field
 * @param {string} className - Additional custom classes
 */
export default function ThemedInput({ 
    variant = 'light',
    type = 'text',
    value,
    onChange,
    placeholder,
    disabled = false,
    required = false,
    className = '',
    ...props
}) {
    const baseClasses = theme.components.input.base;
    const variantClasses = theme.components.input.variants[variant];
    const disabledClasses = disabled ? 'opacity-50 cursor-not-allowed' : '';

    return (
        <input
            type={type}
            value={value}
            onChange={onChange}
            placeholder={placeholder}
            disabled={disabled}
            required={required}
            className={`${baseClasses} ${variantClasses} ${disabledClasses} ${className}`}
            {...props}
        />
    );
}
