import React, { useEffect, useState } from 'react';

export default function Avatar({ user, size = 'md', className = '' }) {
    const [imageFailed, setImageFailed] = useState(false);
    const sizeClasses = {
        sm: 'w-8 h-8 text-xs',
        md: 'w-10 h-10 text-sm',
        lg: 'w-16 h-16 text-lg',
        xl: 'w-20 h-20 text-xl'
    };

    const baseClasses = `rounded-full object-cover border flex-shrink-0 ${sizeClasses[size]} ${className}`;

    // avatar_url is resolvable wherever the file lives (local /storage or a
    // signed S3 URL); the raw avatar path only works for local storage.
    const src = user.avatar_url || (user.avatar ? `/storage/${user.avatar}` : null);

    useEffect(() => {
        setImageFailed(false);
    }, [src]);

    if (src && !imageFailed) {
        return (
            <img
                src={src}
                alt={`${user.name}'s avatar`}
                className={baseClasses}
                onError={() => setImageFailed(true)}
            />
        );
    }

    return (
        <div className={`${baseClasses} bg-gray-300 flex items-center justify-center`}>
            <span className="text-gray-600 font-medium">
                {user.name.charAt(0).toUpperCase()}
            </span>
        </div>
    );
}
