import { useState } from 'react';
import axios from 'axios';
import { usePage } from '@inertiajs/react';
import { Sun, Moon, MoonStar } from 'lucide-react';

const THEMES = [
    { key: 'light', label: 'Light', icon: Sun },
    { key: 'dark', label: 'Dark', icon: Moon },
    { key: 'black', label: 'Black', icon: MoonStar },
];

/**
 * Per-user Light / Dark / Black switch. Applies instantly (data-theme + cookie)
 * and persists to the server. The workspace default is set in Branding settings.
 */
export default function ThemeSwitcher() {
    const [active, setActive] = useState(usePage().props?.theme || 'dark');

    const pick = (theme) => {
        setActive(theme);
        document.documentElement.dataset.theme = theme;
        document.cookie = `tt_theme=${theme};path=/;max-age=31536000;samesite=lax`;
        axios.put(route('theme.update'), { theme }).catch(() => {});
    };

    return (
        <div className="inline-flex items-center gap-0.5 rounded-lg border border-line bg-surface p-0.5">
            {THEMES.map(({ key, label, icon: Icon }) => (
                <button
                    key={key}
                    type="button"
                    onClick={() => pick(key)}
                    title={`${label} theme`}
                    aria-pressed={active === key}
                    className={`flex h-7 w-7 items-center justify-center rounded-md transition ${
                        active === key ? 'bg-accent text-white' : 'text-muted hover:bg-surface-2 hover:text-body'
                    }`}
                >
                    <Icon className="h-4 w-4" />
                </button>
            ))}
        </div>
    );
}
