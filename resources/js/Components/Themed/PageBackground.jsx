import React from 'react';
import theme from '../../theme';

/**
 * PageBackground - Consistent page background
 * Simple, clean gradient background for all pages
 */
export function PageBackground() {
    return (
        <div className={`fixed inset-0 ${theme.backgrounds.page} ${theme.zIndex.background}`}></div>
    );
}

/**
 * PageBackgroundWithPattern - Background with decorative pattern
 * Adds subtle dot pattern overlay for visual interest
 */
export function PageBackgroundWithPattern() {
    return (
        <>
            <div className={`fixed inset-0 ${theme.backgrounds.page} ${theme.zIndex.background}`}></div>
            <div 
                className={`fixed inset-0 ${theme.zIndex.background}`}
                style={{
                    backgroundImage: 'radial-gradient(circle at 1px 1px, rgb(148 163 184 / 0.15) 1px, transparent 0)',
                    backgroundSize: '24px 24px'
                }}
            ></div>
        </>
    );
}

/**
 * PageContent - Wrapper for page content with proper z-index
 */
export function PageContent({ children, className = '' }) {
    return (
        <div className={`relative ${theme.zIndex.content} ${className}`}>
            {children}
        </div>
    );
}

export default PageBackground;
