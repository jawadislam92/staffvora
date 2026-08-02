import { usePage } from '@inertiajs/react';

const BUNDLED_LOGO = '/images/default-logo.svg';

export default function ApplicationLogo({ size = "40" }) {
    const dimension = `${Number(size) * 0.25}rem`;
    const { branding } = usePage().props;

    return (
        <div className="flex justify-center" style={{ width: dimension, height: dimension }}>
            <img
                src={branding?.logo_url || BUNDLED_LOGO}
                className="h-full w-full object-contain drop-shadow-lg"
                alt={`${branding?.name || 'Staffvora'} logo`}
                onError={(e) => {
                    // Uploaded logo unreachable — fall back to the bundled one.
                    if (e.target.src !== window.location.origin + BUNDLED_LOGO) {
                        e.target.src = BUNDLED_LOGO;
                    } else {
                        e.target.style.display = 'none';
                    }
                }}
            />
        </div>
    );
}
