import { usePage } from '@inertiajs/react';

const BUNDLED_LOGO = '/images/sparking-asia-logo.png?v=2';

export default function ApplicationLogo({ size = "40" }) {
    const dimension = `${Number(size) * 0.25}rem`;
    const { branding } = usePage().props;

    return (
        <div className="flex justify-center" style={{ width: dimension, height: dimension }}>
            <img
                src={branding?.logo_url || BUNDLED_LOGO}
                className="h-full w-full object-contain drop-shadow-lg"
                alt="Sparking Asia Logo"
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
