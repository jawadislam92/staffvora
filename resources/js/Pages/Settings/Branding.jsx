import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Field, Input } from '@/Components/ui';
import { Upload, Check, Lock, Moon, Sun, MoonStar, Palette } from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

const PALETTE = ['#2563eb', '#0ea5e9', '#14b8a6', '#22c55e', '#eab308', '#f97316', '#ef4444', '#ec4899', '#a855f7', '#6366f1'];
const THEMES = [
    { key: 'light', label: 'Light', icon: Sun },
    { key: 'dark', label: 'Dark', icon: Moon },
    { key: 'black', label: 'Black', icon: MoonStar },
];

// ── Preview dataset ──────────────────────────────────────────────────────────
// Rendered only when `preview` is true, so the page can be QA'd offline with no
// controller/props. Mirrors the real prop shape from BrandingController::edit().
const DEV_SAMPLE = {
    user: { id: 1, name: 'Jawad', email: 'jawad@staffvora.test', is_super_admin: true, permissions: [] },
    branding: {
        name: 'Acme Corp',
        display_name: 'Acme',
        accent_color: '#7c3aed',
        default_theme: 'dark',
        logo_url: null,
    },
    canManage: true,
    canBrand: true,
};

export default function Branding({
    auth,
    branding,
    canManage,
    canBrand = true,
    preview = false,
}) {
    // In preview mode swap every prop-dependent input for the sample dataset.
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const data = preview ? DEV_SAMPLE.branding : branding;
    const manage = preview ? DEV_SAMPLE.canManage : canManage;
    const brand = preview ? DEV_SAMPLE.canBrand : canBrand;

    const form = useForm({
        display_name: data.display_name || '',
        accent_color: data.accent_color || '#2563eb',
        default_theme: data.default_theme || 'dark',
    });
    const [logoPreview, setLogoPreview] = useState(data.logo_url || null);
    const [uploading, setUploading] = useState(false);

    // Editing requires a workspace admin AND the white-label plan feature.
    const editable = manage && brand;
    const accent = form.data.accent_color;

    const save = (e) => {
        e.preventDefault();
        if (preview) return; // offline QA — never hit the network
        form.put(route('settings.branding.save'), { preserveScroll: true });
    };

    const uploadLogo = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setLogoPreview(URL.createObjectURL(file));
        if (preview) return; // offline QA — show the local preview only
        setUploading(true);
        router.post(route('settings.branding.logo'), { logo: file }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => setUploading(false),
        });
    };

    return (
        <AuthenticatedLayout
            user={user}
            header={<h2 className="text-xl font-semibold text-body">Branding</h2>}
        >
            <Head title="Branding" />

            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 space-y-6">
                <div className="max-w-3xl space-y-6">
                    <p className="text-sm text-muted">
                        Make the product yours. Your logo, name, accent colour and default theme
                        appear across the app and on the sign-in page for everyone in your workspace.
                    </p>

                    {/* Upgrade prompt — admin without the white-label entitlement. */}
                    {manage && !brand && (
                        <Card padded className="border-accent/40 bg-accent/5">
                            <div className="flex items-start gap-3">
                                <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                                    <Lock className="h-5 w-5" />
                                </span>
                                <div className="text-sm">
                                    <p className="font-semibold text-body">White-label branding is a higher-plan feature</p>
                                    <p className="mt-0.5 text-muted">
                                        Upgrade to set your own logo, name, accent colour and default
                                        theme across the workspace.
                                    </p>
                                    <Button
                                        as={Link}
                                        href={preview ? '#' : route('billing.index')}
                                        size="sm"
                                        className="mt-3"
                                    >
                                        View plans
                                    </Button>
                                </div>
                            </div>
                        </Card>
                    )}

                    <div className={cx('space-y-6', !editable && 'pointer-events-none opacity-60')}>
                        {/* ── Logo ──────────────────────────────────────────── */}
                        <Card
                            title="Workspace logo"
                            description="PNG, JPG, or WebP, up to 8 MB. Shown in the sidebar and on the login screen."
                        >
                            <div className="flex items-center gap-4">
                                <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-xl border border-line bg-surface-2">
                                    {logoPreview ? (
                                        <img src={logoPreview} alt="Logo" className="h-full w-full object-contain" />
                                    ) : (
                                        <span className="text-xs text-subtle">None</span>
                                    )}
                                </div>
                                {editable && (
                                    <label
                                        className={cx(
                                            'inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line bg-surface-2 px-3 py-2 text-sm font-medium text-body transition hover:bg-surface',
                                            'focus-within:ring-2 focus-within:ring-accent focus-within:ring-offset-2 focus-within:ring-offset-app',
                                        )}
                                    >
                                        <Upload className="h-4 w-4" />
                                        {uploading ? 'Uploading…' : 'Upload logo'}
                                        <input
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            onChange={uploadLogo}
                                            className="sr-only"
                                            disabled={uploading}
                                        />
                                    </label>
                                )}
                            </div>
                        </Card>

                        {/* ── Name + accent + theme ─────────────────────────── */}
                        <form onSubmit={save}>
                            <Card
                                title="Identity"
                                description="Name, accent colour and the default theme new members start on."
                                footer={
                                    manage ? (
                                        <Button
                                            type="submit"
                                            loading={form.processing}
                                            icon={<Check className="h-4 w-4" />}
                                        >
                                            {form.processing ? 'Saving…' : 'Save branding'}
                                        </Button>
                                    ) : null
                                }
                            >
                                <div className="space-y-6">
                                    {/* Display name */}
                                    <Field
                                        label="Workspace display name"
                                        htmlFor="display_name"
                                        hint={`Shown in the app title and header. Leave blank to use “${data.name}”.`}
                                        error={form.errors.display_name}
                                        className="max-w-sm"
                                    >
                                        <Input
                                            id="display_name"
                                            value={form.data.display_name}
                                            onChange={(e) => form.setData('display_name', e.target.value)}
                                            placeholder={data.name || 'Your company'}
                                            disabled={!editable}
                                        />
                                    </Field>

                                    {/* Accent colour */}
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-body">Accent colour</label>
                                        <div className="flex flex-wrap items-center gap-2">
                                            {PALETTE.map((c) => {
                                                const selected = accent?.toLowerCase() === c;
                                                return (
                                                    <button
                                                        key={c}
                                                        type="button"
                                                        disabled={!editable}
                                                        onClick={() => form.setData('accent_color', c)}
                                                        aria-label={c}
                                                        aria-pressed={selected}
                                                        className={cx(
                                                            'h-7 w-7 rounded-full ring-2 ring-offset-2 ring-offset-app transition',
                                                            'focus-visible:outline-none focus-visible:ring-accent',
                                                            selected ? 'ring-body' : 'ring-transparent hover:ring-line',
                                                        )}
                                                        style={{ backgroundColor: c }}
                                                    />
                                                );
                                            })}

                                            {/* Native custom-colour swatch */}
                                            <label
                                                className="relative ml-1 inline-flex h-7 w-7 cursor-pointer items-center justify-center overflow-hidden rounded-full border border-line bg-surface-2 text-muted"
                                                title="Custom colour"
                                            >
                                                <Palette className="h-4 w-4" />
                                                <input
                                                    type="color"
                                                    value={/^#[0-9a-fA-F]{6}$/.test(accent) ? accent : '#2563eb'}
                                                    onChange={(e) => form.setData('accent_color', e.target.value)}
                                                    disabled={!editable}
                                                    className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                                                />
                                            </label>

                                            <Input
                                                value={form.data.accent_color}
                                                onChange={(e) => form.setData('accent_color', e.target.value)}
                                                disabled={!editable}
                                                placeholder="#2563eb"
                                                className="ml-1 w-28"
                                            />
                                        </div>
                                        {form.errors.accent_color && (
                                            <p className="mt-1 text-xs text-danger">{form.errors.accent_color}</p>
                                        )}
                                    </div>

                                    {/* Default theme */}
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium text-body">Default theme</label>
                                        <p className="mb-2 text-xs text-subtle">
                                            The theme new members start on. Each person can still switch
                                            their own in the sidebar.
                                        </p>
                                        <div className="inline-flex gap-1 rounded-lg border border-line bg-surface-2 p-1">
                                            {THEMES.map(({ key, label, icon: Icon }) => {
                                                const active = form.data.default_theme === key;
                                                return (
                                                    <button
                                                        key={key}
                                                        type="button"
                                                        disabled={!editable}
                                                        onClick={() => form.setData('default_theme', key)}
                                                        aria-pressed={active}
                                                        className={cx(
                                                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition',
                                                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                                                            active ? 'bg-accent text-white' : 'text-muted hover:bg-surface hover:text-body',
                                                        )}
                                                    >
                                                        <Icon className="h-4 w-4" /> {label}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    {/* Live preview — driven by the picked accent, not a hardcoded colour. */}
                                    <div
                                        className="rounded-lg border border-line bg-surface-2 p-4"
                                        style={{ '--preview-accent': accent }}
                                    >
                                        <p className="mb-2 text-xs uppercase tracking-wide text-subtle">Preview</p>
                                        <div className="flex flex-wrap items-center gap-3">
                                            <span
                                                className="rounded-md px-3 py-1.5 text-sm font-semibold text-white"
                                                style={{ backgroundColor: 'var(--preview-accent)' }}
                                            >
                                                {form.data.display_name || data.name || 'Workspace'}
                                            </span>
                                            <span
                                                className="text-sm font-medium"
                                                style={{ color: 'var(--preview-accent)' }}
                                            >
                                                Accent text
                                            </span>
                                            <span
                                                className="inline-flex h-6 items-center rounded-full border px-2.5 text-xs font-medium"
                                                style={{
                                                    color: 'var(--preview-accent)',
                                                    borderColor: 'var(--preview-accent)',
                                                }}
                                            >
                                                Badge
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </Card>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
