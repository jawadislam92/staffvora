import React, { useEffect, useState } from 'react';

export default function Avatar({ user, size = 'md', className = '' }) {
    const [imageFailed, setImageFailed] = useState(false);
    const sizeClasses = {
        sm: 'w-8 h-8 text-xs',
        md: 'w-10 h-10 text-sm',
        lg: 'w-16 h-16 text-lg',
        xl: 'w-20 h-20 text-xl'
    };

    const baseClasses = `rounded-full object-cover border border-line flex-shrink-0 ${sizeClasses[size]} ${className}`;

    // avatar_url is resolvable wherever the file lives (local /storage or a
    // signed S3 URL); the raw avatar path only works for local storage.
    // Null-safe: the shell must never crash if a user prop is missing.
    const src = user?.avatar_url || (user?.avatar ? `/storage/${user.avatar}` : null);
    const initial = (user?.name || '?').charAt(0).toUpperCase();

    useEffect(() => {
        setImageFailed(false);
    }, [src]);

    if (src && !imageFailed) {
        return (
            <img
                src={src}
                alt={`${user?.name || 'User'}'s avatar`}
                className={baseClasses}
                onError={() => setImageFailed(true)}
            />
        );
    }

    return (
        <div className={`${baseClasses} bg-surface-2 flex items-center justify-center`}>
            <span className="text-muted font-medium">{initial}</span>
        </div>
    );
}
