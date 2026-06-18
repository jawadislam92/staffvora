import React from 'react';
import theme from '../../theme';

/**
 * PageContainer - Standardized page wrapper with consistent spacing and width
 * 
 * @param {string} maxWidth - Maximum width constraint (sm, md, lg, xl, 2xl, full)
 * @param {string} spacing - Vertical spacing (tight, normal, relaxed, loose)
 * @param {React.ReactNode} children - Child components
 * @param {string} className - Additional custom classes
 */
export default function PageContainer({ 
    maxWidth = 'xl', 
    spacing = 'normal',
    children,
    className = ''
}) {
    const spacingClass = theme.layout.sectionSpacing[spacing];
    
    return (
        <div className={`${spacingClass} relative z-10`}>
            <div className={`max-w-${maxWidth} mx-auto ${theme.layout.containerPadding.mobile} ${theme.layout.containerPadding.tablet} ${theme.layout.containerPadding.desktop} ${className}`}>
                {children}
            </div>
        </div>
    );
}
