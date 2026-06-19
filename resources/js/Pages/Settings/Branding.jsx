import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Upload, Check, Lock, Moon, Sun, MoonStar } from 'lucide-react';

const PALETTE = ['#f97316', '#22c55e', '#3b82f6', '#a855f7', '#ec4899', '#eab308', '#14b8a6', '#ef4444', '#0ea5e9', '#f43f5e'];
const THEMES = [{ key: 'light', label: 'Light', icon: Sun }, { key: 'dark', label: 'Dark', icon: Moon }, { key: 'black', label: 'Black', icon: MoonStar }];

export default function Branding({ auth, branding, canManage, canBrand = true }) {
    const form = useForm({
        display_name: branding.display_name || '',
        accent_color: branding.accent_color || '#f97316',
        default_theme: branding.default_theme || 'dark',
    });
    const [logoPreview, setLogoPreview] = useState(branding.logo_url || null);
    const [uploading, setUploading] = useState(false);
    // Editing requires a workspace admin AND the white-label plan feature.
    const editable = canManage && canBrand;

    const save = (e) => {
        e.preventDefault();
        form.put(route('settings.branding.save'), { preserveScroll: true });
    };

    const uploadLogo = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setLogoPreview(URL.createObjectURL(file));
        setUploading(true);
        router.post(route('settings.branding.logo'), { logo: file }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => setUploading(false),
        });
    };

    const accent = form.data.accent_color;

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="text-xl font-semibold text-slate-100">Branding</h2>}>
            <Head title="Branding" />
            <div className="min-h-screen bg-slate-950">
                <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
                    <p className="mb-6 text-sm text-slate-400">
                        Make the product yours. Your logo, name, accent colour and default theme appear across the app and on the sign-in page for everyone in your workspace.
                    </p>

                    {canManage && !canBrand && (
                        <div className="mb-6 flex items-start gap-3 rounded-lg border border-line bg-surface p-4">
                            <Lock className="mt-0.5 h-5 w-5 shrink-0 text-subtle" />
                            <div className="text-sm">
                                <p className="font-semibold text-body">White-label branding is a higher-plan feature</p>
                                <p className="mt-0.5 text-muted">Upgrade to set your own logo, name, accent colour and default theme across the workspace.</p>
                                <Link href={route('billing.index')} className="mt-2 inline-flex rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">View plans</Link>
                            </div>
                        </div>
                    )}

                    <div className={`space-y-6 ${editable ? '' : 'pointer-events-none opacity-60'}`}>
                        {/* Logo */}
                        <section className="rounded-lg border border-slate-800 bg-slate-900 p-5">
                            <h3 className="mb-1 font-semibold text-slate-100">Workspace logo</h3>
                            <p className="mb-4 text-xs text-slate-400">PNG, JPG, or WebP, up to 8&nbsp;MB. Shown in the sidebar and on the login screen.</p>
                            <div className="flex items-center gap-4">
                                <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-xl border border-slate-700 bg-slate-950">
                                    {logoPreview
                                        ? <img src={logoPreview} alt="Logo" className="h-full w-full object-contain" />
                                        : <span className="text-xs text-slate-500">None</span>}
                                </div>
                                {editable && (
                                    <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-700 px-3 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800">
                                        <Upload className="h-4 w-4" />
                                        {uploading ? 'Uploading…' : 'Upload logo'}
                                        <input type="file" accept="image/png,image/jpeg,image/webp" onChange={uploadLogo} className="hidden" disabled={uploading} />
                                    </label>
                                )}
                            </div>
                        </section>

                        {/* Name + accent */}
                        <form onSubmit={save} className="rounded-lg border border-slate-800 bg-slate-900 p-5 space-y-5">
                            <div>
                                <label className="mb-1.5 block text-sm font-semibold text-slate-100">Workspace display name</label>
                                <input
                                    value={form.data.display_name}
                                    onChange={(e) => form.setData('display_name', e.target.value)}
                                    placeholder={branding.name || 'Your company'}
                                    disabled={!editable}
                                    className="w-full max-w-sm rounded-lg border-slate-700 bg-slate-950 text-sm text-slate-100 placeholder:text-slate-500 focus:border-orange-500"
                                />
                                <p className="mt-1 text-xs text-slate-400">Shown in the app title and header. Leave blank to use “{branding.name}”.</p>
                                {form.errors.display_name && <p className="mt-1 text-xs text-rose-400">{form.errors.display_name}</p>}
                            </div>

                            <div>
                                <label className="mb-2 block text-sm font-semibold text-slate-100">Accent colour</label>
                                <div className="flex flex-wrap items-center gap-2">
                                    {PALETTE.map((c) => (
                                        <button
                                            key={c}
                                            type="button"
                                            disabled={!editable}
                                            onClick={() => form.setData('accent_color', c)}
                                            className={`h-7 w-7 rounded-full ring-2 transition ${accent.toLowerCase() === c ? 'ring-white' : 'ring-transparent hover:ring-slate-500'}`}
                                            style={{ backgroundColor: c }}
                                            aria-label={c}
                                        />
                                    ))}
                                    <input
                                        type="text"
                                        value={form.data.accent_color}
                                        onChange={(e) => form.setData('accent_color', e.target.value)}
                                        disabled={!editable}
                                        className="ml-2 w-28 rounded-lg border-slate-700 bg-slate-950 text-sm text-slate-100 focus:border-orange-500"
                                        placeholder="#f97316"
                                    />
                                </div>
                                {form.errors.accent_color && <p className="mt-1 text-xs text-rose-400">{form.errors.accent_color}</p>}
                            </div>

                            <div>
                                <label className="mb-2 block text-sm font-semibold text-slate-100">Default theme</label>
                                <p className="mb-2 text-xs text-slate-400">The theme new members start on. Each person can still switch their own in the sidebar.</p>
                                <div className="inline-flex gap-1 rounded-lg border border-slate-700 bg-slate-950 p-1">
                                    {THEMES.map(({ key, label, icon: Icon }) => (
                                        <button
                                            key={key}
                                            type="button"
                                            disabled={!editable}
                                            onClick={() => form.setData('default_theme', key)}
                                            className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition ${form.data.default_theme === key ? 'bg-orange-500 text-slate-950' : 'text-slate-300 hover:bg-slate-800'}`}
                                        >
                                            <Icon className="h-4 w-4" /> {label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Live preview */}
                            <div className="rounded-lg border border-slate-800 bg-slate-950 p-4">
                                <p className="mb-2 text-xs uppercase tracking-wide text-slate-500">Preview</p>
                                <div className="flex items-center gap-3">
                                    <span className="rounded-md px-3 py-1.5 text-sm font-semibold text-slate-950" style={{ backgroundColor: accent }}>
                                        {form.data.display_name || branding.name || 'Workspace'}
                                    </span>
                                    <span className="text-sm font-medium" style={{ color: accent }}>Accent text</span>
                                </div>
                            </div>

                            {canManage && (
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-orange-500 px-4 py-2 text-sm font-bold text-slate-950 hover:bg-orange-400 disabled:opacity-50"
                                >
                                    <Check className="h-4 w-4" /> {form.processing ? 'Saving…' : 'Save branding'}
                                </button>
                            )}
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
