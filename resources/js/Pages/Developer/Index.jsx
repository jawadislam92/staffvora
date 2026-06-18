import React, { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Database,
    FileText,
    Image as ImageIcon,
    KeyRound,
    Play,
    RefreshCw,
    Send,
    Terminal,
    Trash2,
    Wrench,
    XCircle,
    Zap,
} from 'lucide-react';

function InfoRow({ label, value, mono = true }) {
    return (
        <div className="flex items-center justify-between gap-3 py-1.5 text-sm">
            <span className="text-slate-500">{label}</span>
            <span className={['truncate text-right text-slate-900', mono ? 'font-mono text-xs' : ''].join(' ')} title={String(value ?? '')}>
                {value === null || value === undefined || value === '' ? '—' : String(value)}
            </span>
        </div>
    );
}

function Badge({ ok, children }) {
    return (
        <span
            className={[
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
                ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700',
            ].join(' ')}
        >
            {ok ? <CheckCircle2 className="h-3 w-3" /> : <XCircle className="h-3 w-3" />}
            {children}
        </span>
    );
}

function Card({ title, icon: Icon, children, accent }) {
    return (
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <header className={['flex items-center gap-2 border-b border-slate-100 px-4 py-2.5', accent || 'bg-slate-50'].join(' ')}>
                {Icon && <Icon className="h-4 w-4 text-slate-500" />}
                <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
            </header>
            <div className="px-4 py-3">{children}</div>
        </section>
    );
}

function ActionButton({ icon: Icon, label, hint, danger, busy, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={busy}
            className={[
                'flex w-full items-start gap-3 rounded-md border px-3 py-2.5 text-left transition disabled:opacity-50',
                danger
                    ? 'border-rose-200 hover:bg-rose-50'
                    : 'border-slate-200 hover:bg-slate-50',
            ].join(' ')}
        >
            <Icon className={['mt-0.5 h-4 w-4 shrink-0', danger ? 'text-rose-500' : 'text-slate-500'].join(' ')} />
            <span className="min-w-0">
                <span className="block text-sm font-medium text-slate-900">{label}</span>
                {hint && <span className="block text-xs text-slate-500">{hint}</span>}
            </span>
        </button>
    );
}

function buildEnvDraft(groups) {
    const draft = {};
    (groups || []).forEach((group) => {
        group.fields.forEach((field) => {
            if (field.type === 'secret') {
                draft[field.key] = ''; // blank = keep current
            } else if (field.type === 'bool') {
                draft[field.key] = String(field.value) === 'true';
            } else {
                draft[field.key] = field.value ?? '';
            }
        });
    });
    return draft;
}

export default function DeveloperIndex({ auth, system, health, schedule, envGroups, lastOutput, lastAction }) {
    const flash = usePage().props.flash || {};
    const branding = usePage().props.branding || {};
    const [busy, setBusy] = useState(false);
    const [logLines, setLogLines] = useState([]);
    const [logFile, setLogFile] = useState(null);
    const [logsLoading, setLogsLoading] = useState(false);
    const [envDraft, setEnvDraft] = useState(() => buildEnvDraft(envGroups));
    const [savingEnv, setSavingEnv] = useState(false);
    const [logoFile, setLogoFile] = useState(null);
    const [uploadingLogo, setUploadingLogo] = useState(false);

    const uploadLogo = () => {
        if (!logoFile) return;
        setUploadingLogo(true);
        router.post(route('developer.branding.update'), { logo: logoFile }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setLogoFile(null),
            onFinish: () => setUploadingLogo(false),
        });
    };

    const runAction = (action, confirmText) => {
        if (confirmText && !confirm(confirmText)) return;
        setBusy(true);
        router.post(route('developer.run'), { action }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    const setEnvField = (key, value) => setEnvDraft((d) => ({ ...d, [key]: value }));

    const saveEnv = () => {
        setSavingEnv(true);
        router.put(route('developer.env.update'), { values: envDraft }, {
            preserveScroll: true,
            onSuccess: () => setEnvDraft((d) => {
                // clear secret fields after a successful save so they show "set"
                const next = { ...d };
                (envGroups || []).forEach((g) => g.fields.forEach((f) => {
                    if (f.type === 'secret') next[f.key] = '';
                }));
                return next;
            }),
            onFinish: () => setSavingEnv(false),
        });
    };

    const loadLogs = async () => {
        setLogsLoading(true);
        try {
            const res = await fetch(route('developer.logs'), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const json = await res.json();
            setLogLines(json.lines || []);
            setLogFile(json.file);
        } catch {
            setLogLines(['Could not load logs.']);
        } finally {
            setLogsLoading(false);
        }
    };

    useEffect(() => { loadLogs(); }, []);

    return (
        <AuthenticatedLayout user={auth.user} header={<h2 className="text-xl font-semibold text-slate-900">Developer</h2>}>
            <Head title="Developer" />

            <div className="min-h-screen bg-slate-950">
            <div className="mx-auto max-w-7xl space-y-4 px-4 py-6 sm:px-6 lg:px-8">
                {(flash.success || flash.error) && (
                    <div
                        className={[
                            'rounded-md border px-4 py-2 text-sm',
                            flash.error
                                ? 'border-rose-200 bg-rose-50 text-rose-800'
                                : 'border-emerald-200 bg-emerald-50 text-emerald-800',
                        ].join(' ')}
                    >
                        {flash.error || flash.success}
                    </div>
                )}

                {lastOutput && (
                    <Card title={`Output — ${lastAction || 'last action'}`} icon={Terminal} accent="bg-slate-900 text-white [&_h2]:text-white [&_svg]:text-slate-300">
                        <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded bg-slate-900 p-3 font-mono text-xs leading-relaxed text-emerald-200">
                            {lastOutput}
                        </pre>
                    </Card>
                )}

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card title="System" icon={Database}>
                        <InfoRow label="Environment" value={system.app_env} />
                        <InfoRow label="Debug" value={system.app_debug ? 'on' : 'off'} />
                        <InfoRow label="App URL" value={system.app_url} />
                        <InfoRow label="Timezone" value={system.app_timezone} />
                        <InfoRow label="Server time" value={system.server_time} />
                        <InfoRow label="PHP" value={system.php_version} />
                        <InfoRow label="Laravel" value={system.laravel_version} />
                        <InfoRow label="Database" value={`${system.db_connection} · ${system.db_database}`} />
                        <InfoRow label="Cache / Session / Queue" value={`${system.cache_driver} / ${system.session_driver} / ${system.queue_driver}`} />
                        <InfoRow label="Config cached" value={system.config_cached ? 'yes' : 'no'} />
                        <InfoRow label="Routes cached" value={system.routes_cached ? 'yes' : 'no'} />
                        <InfoRow label="Web build" value={system.web_build_at} />
                        <InfoRow label="Desktop build" value={system.desktop_build_at} />
                        <InfoRow
                            label="Git"
                            value={system.git?.commit ? `${system.git.branch}@${system.git.commit} · ${system.git.dirty_files} changed` : '—'}
                        />
                    </Card>

                    <Card title="Health" icon={Activity}>
                        <ul className="divide-y divide-slate-100">
                            {health.map((check) => (
                                <li key={check.name} className="flex items-center justify-between gap-3 py-2">
                                    <span className="text-sm text-slate-700">{check.name}</span>
                                    <span className="flex items-center gap-2">
                                        <span className="max-w-44 truncate text-xs text-slate-500" title={check.detail}>{check.detail}</span>
                                        <Badge ok={check.ok}>{check.ok ? 'OK' : 'FAIL'}</Badge>
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-3 border-t border-slate-100 pt-3">
                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Scheduled jobs</p>
                            <ul className="space-y-1.5">
                                {schedule.map((job) => (
                                    <li key={job.name} className="flex items-center justify-between gap-2 text-xs">
                                        <span className="text-slate-600">{job.name}</span>
                                        <span className="flex items-center gap-2">
                                            <span className="text-slate-400">{job.when}</span>
                                            <Badge ok={job.enabled}>{job.enabled ? 'on' : 'off'}</Badge>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </Card>

                    <Card title="Actions" icon={Wrench}>
                        <div className="space-y-2">
                            <ActionButton
                                icon={Zap}
                                label="Optimize (build caches)"
                                hint="Faster pages. Warning: breaks php artisan test until cleared."
                                busy={busy}
                                onClick={() => runAction('optimize')}
                            />
                            <ActionButton
                                icon={RefreshCw}
                                label="Clear caches"
                                hint="optimize:clear — run before the test suite."
                                busy={busy}
                                onClick={() => runAction('optimize_clear')}
                            />
                            <ActionButton
                                icon={Play}
                                label="Run migrations (local only)"
                                hint="php artisan migrate against the local DB."
                                busy={busy}
                                onClick={() => runAction('migrate', 'Run pending migrations against the local database?')}
                            />
                            <ActionButton
                                icon={Send}
                                label="Test Slack webhook"
                                hint="Sends one test message to the configured channel."
                                busy={busy}
                                onClick={() => runAction('slack_test', 'Send a test message to Slack?')}
                            />
                            <ActionButton
                                icon={Database}
                                label="Test screenshot storage"
                                hint="Writes, reads, signs, and deletes a probe file (S3 or local)."
                                busy={busy}
                                onClick={() => runAction('s3_test')}
                            />
                            <ActionButton
                                icon={FileText}
                                label="Preview activity digest"
                                hint="Builds yesterday's digest text without sending."
                                busy={busy}
                                onClick={() => runAction('digest_preview')}
                            />
                            <ActionButton
                                icon={Trash2}
                                label="Screenshot retention dry-run"
                                hint="Shows what the nightly prune would delete."
                                busy={busy}
                                onClick={() => runAction('prune_dry_run')}
                            />
                        </div>
                    </Card>
                </div>

                <Card title="Integration credentials" icon={KeyRound}>
                    <p className="mb-4 text-xs text-slate-500">
                        Edit Slack and S3 settings without touching the server. Secret fields are write-only —
                        they show whether a value is set, never the value itself. Leave a secret blank to keep
                        the current one. Saving reloads the config automatically.
                    </p>
                    <div className="grid gap-5 lg:grid-cols-2">
                        {(envGroups || []).map((group) => (
                            <div key={group.key} className="rounded-lg border border-slate-200 p-4">
                                <h3 className="mb-3 text-sm font-semibold text-slate-900">{group.label}</h3>
                                <div className="space-y-3">
                                    {group.fields.map((field) => (
                                        <div key={field.key}>
                                            <label className="flex items-center justify-between text-xs font-medium text-slate-600">
                                                <span>{field.label}</span>
                                                {field.type === 'secret' && (
                                                    <span className={field.is_set ? 'text-emerald-600' : 'text-slate-400'}>
                                                        {field.is_set ? 'set' : 'not set'}
                                                    </span>
                                                )}
                                            </label>
                                            <div className="mt-1">
                                                {field.type === 'bool' ? (
                                                    <select
                                                        value={envDraft[field.key] ? 'true' : 'false'}
                                                        onChange={(e) => setEnvField(field.key, e.target.value === 'true')}
                                                        className="w-full rounded border-slate-300 text-sm"
                                                    >
                                                        <option value="true">Enabled</option>
                                                        <option value="false">Disabled</option>
                                                    </select>
                                                ) : field.type === 'select' ? (
                                                    <select
                                                        value={envDraft[field.key] ?? ''}
                                                        onChange={(e) => setEnvField(field.key, e.target.value)}
                                                        className="w-full rounded border-slate-300 text-sm"
                                                    >
                                                        {field.options.map((opt) => (
                                                            <option key={opt} value={opt}>{opt}</option>
                                                        ))}
                                                    </select>
                                                ) : (
                                                    <input
                                                        type={field.type === 'secret' ? 'password' : 'text'}
                                                        value={envDraft[field.key] ?? ''}
                                                        onChange={(e) => setEnvField(field.key, e.target.value)}
                                                        placeholder={field.type === 'secret' ? (field.is_set ? '•••••••• (leave blank to keep)' : 'not set') : (field.placeholder || '')}
                                                        autoComplete="off"
                                                        className="w-full rounded border-slate-300 font-mono text-sm"
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="mt-4 flex items-center gap-3">
                        <button
                            type="button"
                            onClick={saveEnv}
                            disabled={savingEnv}
                            className="inline-flex items-center gap-2 rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60"
                        >
                            <KeyRound className="h-4 w-4" />
                            {savingEnv ? 'Saving…' : 'Save credentials'}
                        </button>
                        <span className="text-xs text-slate-400">After saving, use “Test screenshot storage” / “Test Slack webhook” above to verify.</span>
                    </div>
                </Card>

                <Card title="Branding" icon={ImageIcon}>
                    <div className="flex flex-wrap items-center gap-5">
                        <div className="flex h-20 w-20 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 p-2">
                            <img
                                src={branding?.logo_url || '/images/sparking-asia-logo.png?v=2'}
                                alt="Current logo"
                                className="max-h-full max-w-full object-contain"
                            />
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="mb-2 text-xs text-slate-500">
                                App logo shown in the navigation, login, and welcome pages. Stored on S3 so
                                deploys can't wipe it. PNG/JPG/WebP, max 1&nbsp;MB — a square image works best.
                            </p>
                            <div className="flex items-center gap-3">
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    onChange={(e) => setLogoFile(e.target.files?.[0] || null)}
                                    className="text-xs text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                                />
                                <button
                                    type="button"
                                    onClick={uploadLogo}
                                    disabled={!logoFile || uploadingLogo}
                                    className="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50"
                                >
                                    {uploadingLogo ? 'Uploading…' : 'Upload logo'}
                                </button>
                            </div>
                        </div>
                    </div>
                </Card>

                <Card title={`Logs ${logFile ? `— ${logFile}` : ''}`} icon={AlertTriangle}>
                    <div className="mb-2 flex items-center justify-between">
                        <p className="text-xs text-slate-500">Last 300 lines of the newest log file.</p>
                        <button
                            type="button"
                            onClick={loadLogs}
                            disabled={logsLoading}
                            className="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                        >
                            <RefreshCw className={['h-3 w-3', logsLoading ? 'animate-spin' : ''].join(' ')} />
                            Refresh
                        </button>
                    </div>
                    <pre className="max-h-80 overflow-auto whitespace-pre-wrap rounded bg-slate-900 p-3 font-mono text-[11px] leading-relaxed text-slate-200">
                        {logLines.length ? logLines.join('\n') : (logsLoading ? 'Loading…' : 'No log entries.')}
                    </pre>
                </Card>
            </div>
            </div>
        </AuthenticatedLayout>
    );
}
