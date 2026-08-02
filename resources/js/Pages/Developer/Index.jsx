import React, { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import PlatformLayout, { PlatformPageHeader } from '@/Layouts/PlatformLayout';
import { Button, Card, CardHeader, Badge, Field, Input, Select } from '@/Components/ui';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Database,
    FileText,
    KeyRound,
    Play,
    RefreshCw,
    Send,
    ShieldAlert,
    Terminal,
    Trash2,
    Wrench,
    XCircle,
    Zap,
} from 'lucide-react';

const cx = (...a) => a.filter(Boolean).join(' ');

// ---------------------------------------------------------------------------
// DEV_SAMPLE — offline fixtures for `preview` mode. Mirrors the exact shapes
// DeveloperController@index sends (system / health / schedule / envGroups /
// lastOutput / lastAction) plus a sample tail of logs. Never used when the
// page is rendered by the real controller.
// ---------------------------------------------------------------------------
const DEV_SAMPLE = {
    system: {
        app_env: 'production',
        app_debug: false,
        app_url: 'https://platform.staffvora.com',
        app_timezone: 'UTC',
        server_time: '2026-07-22 14:12:07',
        php_version: '8.3.9',
        laravel_version: '12.4.1',
        db_connection: 'mysql',
        db_database: 'staffvora',
        cache_driver: 'redis',
        session_driver: 'redis',
        queue_driver: 'redis',
        config_cached: true,
        routes_cached: false,
        web_build_at: '2026-07-22 09:41:55',
        desktop_build_at: '2026-07-19 03:20:11',
        git: { commit: 'a1b2c3d', branch: 'main', dirty_files: 2 },
    },
    health: [
        { name: 'Database', ok: true, detail: '4 ms' },
        { name: 'Cache', ok: true, detail: 'redis' },
        { name: 'Storage: public', ok: true, detail: 'writable' },
        { name: 'Storage: screenshots', ok: true, detail: 'writable' },
        { name: 'Slack webhook', ok: true, detail: 'configured' },
        { name: 'Migrations', ok: false, detail: '1 pending' },
    ],
    schedule: [
        { name: 'Weekly Slack work-hours report', enabled: true, when: 'Sundays 10:00 Asia/Karachi' },
        { name: 'Daily Slack activity digest', enabled: true, when: 'Daily 09:00 Asia/Karachi' },
        { name: 'Screenshot retention prune', enabled: true, when: 'Daily 02:30 Asia/Karachi' },
    ],
    envGroups: [
        {
            key: 'slack',
            label: 'Slack reports',
            fields: [
                { key: 'SLACK_REPORT_WEBHOOK_URL', label: 'Incoming webhook URL', type: 'secret', is_set: true },
                { key: 'SLACK_REPORT_TIMEZONE', label: 'Timezone', type: 'text', placeholder: 'Asia/Karachi', value: 'Asia/Karachi' },
                { key: 'SLACK_WEEKLY_REPORT_ENABLED', label: 'Weekly work-hours report', type: 'bool', value: 'true' },
                { key: 'SLACK_DAILY_DIGEST_ENABLED', label: 'Daily activity digest', type: 'bool', value: 'true' },
                { key: 'SLACK_DAILY_DIGEST_TIME', label: 'Daily digest time (HH:MM)', type: 'text', placeholder: '09:00', value: '09:00' },
            ],
        },
        {
            key: 's3',
            label: 'Screenshot storage (S3)',
            fields: [
                { key: 'SCREENSHOTS_DRIVER', label: 'Driver', type: 'select', options: ['local', 's3'], value: 's3' },
                { key: 'AWS_ACCESS_KEY_ID', label: 'Access key ID', type: 'secret', is_set: true },
                { key: 'AWS_SECRET_ACCESS_KEY', label: 'Secret access key', type: 'secret', is_set: true },
                { key: 'AWS_DEFAULT_REGION', label: 'Region', type: 'text', placeholder: 'eu-north-1', value: 'eu-north-1' },
                { key: 'SCREENSHOTS_BUCKET', label: 'Bucket name', type: 'text', placeholder: 'company-screenshots', value: 'sparkingasia-timetracker-screenshots' },
            ],
        },
    ],
    lastOutput:
        'INFO  Configuration cached successfully.\nINFO  Blade templates cached successfully.\nINFO  Events cached successfully.',
    lastAction: 'optimize',
    diagnostics: {
        window_days: 7,
        counts: { desktop: 42, 'tracker-health': 6 },
        levels: { info: 31, warn: 11, error: 6 },
        recent: [
            {
                id: 4821,
                category: 'tracker-health',
                level: 'error',
                event: 'tracker_health_decision',
                summary: 'ALERTED: counting time but capturing nothing (likely antivirus block) — session #9912 (DESKTOP-A17)',
                workspace: 'Northwind Studio',
                user: 'Asim Khan',
                device_name: 'DESKTOP-A17',
                app_version: '0.4.5',
                captured_at: '2026-07-22 13:45:02',
            },
            {
                id: 4820,
                category: 'desktop',
                level: 'warn',
                event: 'screenshot_capture_failed',
                summary: '[WARN] screenshot_capture_failed @DESKTOP-A17 — Access is denied (Asim Khan)',
                workspace: 'Northwind Studio',
                user: 'Asim Khan',
                device_name: 'DESKTOP-A17',
                app_version: '0.4.5',
                captured_at: '2026-07-22 13:31:47',
            },
            {
                id: 4819,
                category: 'tracker-health',
                level: 'info',
                event: 'tracker_health_decision',
                summary: 'skipped: on break — session #9908 (LT-FIN-04)',
                workspace: 'Meridian Books',
                user: 'Dana Ali',
                device_name: 'LT-FIN-04',
                app_version: '0.4.5',
                captured_at: '2026-07-22 12:58:10',
            },
            {
                id: 4818,
                category: 'desktop',
                level: 'info',
                event: 'agent_resumed',
                summary: '[INFO] agent_resumed @LT-FIN-04 — resumed after sleep (Dana Ali)',
                workspace: 'Meridian Books',
                user: 'Dana Ali',
                device_name: 'LT-FIN-04',
                app_version: '0.4.5',
                captured_at: '2026-07-22 12:40:00',
            },
        ],
    },
    logLines: [
        '[2026-07-22 14:05:12] production.INFO: Daily activity digest queued {"tenants":18}',
        '[2026-07-22 13:58:44] production.INFO: Screenshot uploaded to S3 {"key":"shots/2026/07/22/abc.webp"}',
        '[2026-07-22 13:40:02] production.WARNING: Slow query (1.8s) on attendance report',
        '[2026-07-22 12:11:31] production.INFO: Weekly Slack report skipped — not Sunday',
        '[2026-07-22 09:41:55] production.INFO: Config, view and event caches rebuilt',
    ],
    logFile: 'laravel-2026-07-22.log',
};

function InfoRow({ label, value, mono = true }) {
    return (
        <div className="flex items-center justify-between gap-3 py-1.5 text-sm">
            <span className="text-subtle">{label}</span>
            <span
                className={cx('truncate text-right text-body', mono && 'font-mono text-xs')}
                title={String(value ?? '')}
            >
                {value === null || value === undefined || value === '' ? '—' : String(value)}
            </span>
        </div>
    );
}

function HealthBadge({ ok, children }) {
    return (
        <Badge tone={ok ? 'success' : 'danger'}>
            {ok ? <CheckCircle2 className="h-3 w-3" /> : <XCircle className="h-3 w-3" />}
            {children}
        </Badge>
    );
}

// Titled panel built on the kit Card with an icon in the header, tokenized so
// it reads correctly in all three themes.
function SectionCard({ title, icon: Icon, actions, children, className }) {
    return (
        <Card
            className={className}
            header={
                <CardHeader
                    title={
                        <span className="flex items-center gap-2 text-sm font-semibold text-body">
                            {Icon && <Icon className="h-4 w-4 text-muted" aria-hidden="true" />}
                            {title}
                        </span>
                    }
                    actions={actions}
                />
            }
        >
            {children}
        </Card>
    );
}

// A single maintenance tool. Primary tools use the accent icon; destructive
// ones flip to the danger token.
function ActionButton({ icon: Icon, label, hint, danger, busy, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={busy}
            className={cx(
                'flex w-full items-start gap-3 rounded-lg border px-3 py-2.5 text-left transition disabled:opacity-50',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                danger ? 'border-danger/30 hover:bg-danger-soft' : 'border-line hover:bg-surface-2',
            )}
        >
            <Icon className={cx('mt-0.5 h-4 w-4 shrink-0', danger ? 'text-danger' : 'text-accent')} aria-hidden="true" />
            <span className="min-w-0">
                <span className="block text-sm font-medium text-body">{label}</span>
                {hint && <span className="block text-xs text-subtle">{hint}</span>}
            </span>
        </button>
    );
}

// A console / terminal readout surface. Uses bg-app (the darkest token) so it
// stays legible across dark / light / black themes.
function Console({ children, className }) {
    return (
        <pre
            className={cx(
                'overflow-auto whitespace-pre-wrap rounded-lg border border-line bg-app p-3 font-mono text-xs leading-relaxed text-body',
                className,
            )}
        >
            {children}
        </pre>
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

export default function DeveloperIndex({
    system,
    health,
    schedule,
    envGroups,
    lastOutput,
    lastAction,
    preview = false,
}) {
    // In preview, render fully offline from DEV_SAMPLE. Non-preview behavior is
    // identical to before — props come straight from the controller.
    const data = preview
        ? DEV_SAMPLE
        : { system, health, schedule, envGroups, lastOutput, lastAction };

    // usePage() is called unconditionally (rules of hooks); the layout depends on
    // the same Inertia context so it is always present, even in preview.
    const pageProps = usePage().props;
    const flash = (preview ? {} : pageProps.flash) || {};
    const [busy, setBusy] = useState(false);
    const [logLines, setLogLines] = useState(preview ? DEV_SAMPLE.logLines : []);
    const [logFile, setLogFile] = useState(preview ? DEV_SAMPLE.logFile : null);
    const [logsLoading, setLogsLoading] = useState(false);
    const [diagnostics, setDiagnostics] = useState(
        preview ? DEV_SAMPLE.diagnostics : { window_days: 7, counts: {}, levels: {}, recent: [] },
    );
    const [diagLoading, setDiagLoading] = useState(false);
    const [envDraft, setEnvDraft] = useState(() => buildEnvDraft(data.envGroups));
    const [savingGroup, setSavingGroup] = useState(null);

    const runAction = (action, confirmText) => {
        if (preview) return;
        if (confirmText && !confirm(confirmText)) return;
        setBusy(true);
        router.post(route('developer.run'), { action }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    const setEnvField = (key, value) => setEnvDraft((d) => ({ ...d, [key]: value }));

    // Each integration saves on its own, sending only its fields + a `group`
    // marker so it can't clobber the others.
    const saveEnvGroup = (group) => {
        if (preview) return;
        setSavingGroup(group.key);
        const values = {};
        group.fields.forEach((f) => { values[f.key] = envDraft[f.key]; });
        router.put(route('developer.env.update'), { values, group: group.key }, {
            preserveScroll: true,
            onSuccess: () => setEnvDraft((d) => {
                const next = { ...d };
                group.fields.forEach((f) => { if (f.type === 'secret') next[f.key] = ''; });
                return next;
            }),
            onFinish: () => setSavingGroup(null),
        });
    };

    const loadLogs = async () => {
        if (preview) return;
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

    const loadDiagnostics = async () => {
        if (preview) return;
        setDiagLoading(true);
        try {
            const res = await fetch(route('developer.diagnostics'), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const json = await res.json();
            setDiagnostics({
                window_days: json.window_days ?? 7,
                counts: json.counts || {},
                levels: json.levels || {},
                recent: json.recent || [],
            });
        } catch {
            setDiagnostics({ window_days: 7, counts: {}, levels: {}, recent: [] });
        } finally {
            setDiagLoading(false);
        }
    };

    useEffect(() => { loadLogs(); loadDiagnostics(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const { system: sys, health: healthList, schedule: scheduleList, envGroups: groups, lastOutput: output, lastAction: action } = data;

    return (
        <PlatformLayout
            title="Developer"
            header={
                <PlatformPageHeader
                    title="Developer"
                    description="System panel — environment, health checks and a fixed set of safe maintenance actions."
                />
            }
        >
            <Head title="Developer" />

            <div className="space-y-4">
                {(flash.success || flash.error) && (
                    <div
                        className={cx(
                            'rounded-lg border px-4 py-2 text-sm',
                            flash.error
                                ? 'border-danger/30 bg-danger-soft text-danger'
                                : 'border-success/30 bg-success-soft text-success',
                        )}
                    >
                        {flash.error || flash.success}
                    </div>
                )}

                {output && (
                    <SectionCard title={`Output — ${action || 'last action'}`} icon={Terminal}>
                        <Console className="max-h-64">{output}</Console>
                    </SectionCard>
                )}

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <SectionCard title="System" icon={Database}>
                        <InfoRow label="Environment" value={sys.app_env} />
                        <InfoRow label="Debug" value={sys.app_debug ? 'on' : 'off'} />
                        <InfoRow label="App URL" value={sys.app_url} />
                        <InfoRow label="Timezone" value={sys.app_timezone} />
                        <InfoRow label="Server time" value={sys.server_time} />
                        <InfoRow label="PHP" value={sys.php_version} />
                        <InfoRow label="Laravel" value={sys.laravel_version} />
                        <InfoRow label="Database" value={`${sys.db_connection} · ${sys.db_database}`} />
                        <InfoRow label="Cache / Session / Queue" value={`${sys.cache_driver} / ${sys.session_driver} / ${sys.queue_driver}`} />
                        <InfoRow label="Config cached" value={sys.config_cached ? 'yes' : 'no'} />
                        <InfoRow label="Routes cached" value={sys.routes_cached ? 'yes' : 'no'} />
                        <InfoRow label="Web build" value={sys.web_build_at} />
                        <InfoRow label="Desktop build" value={sys.desktop_build_at} />
                        <InfoRow
                            label="Git"
                            value={sys.git?.commit ? `${sys.git.branch}@${sys.git.commit} · ${sys.git.dirty_files} changed` : '—'}
                        />
                    </SectionCard>

                    <SectionCard title="Health" icon={Activity}>
                        <ul className="divide-y divide-line">
                            {healthList.map((check) => (
                                <li key={check.name} className="flex items-center justify-between gap-3 py-2">
                                    <span className="text-sm text-muted">{check.name}</span>
                                    <span className="flex items-center gap-2">
                                        <span className="max-w-44 truncate text-xs text-subtle" title={check.detail}>{check.detail}</span>
                                        <HealthBadge ok={check.ok}>{check.ok ? 'OK' : 'FAIL'}</HealthBadge>
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <div className="mt-3 border-t border-line pt-3">
                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-subtle">Scheduled jobs</p>
                            <ul className="space-y-1.5">
                                {scheduleList.map((job) => (
                                    <li key={job.name} className="flex items-center justify-between gap-2 text-xs">
                                        <span className="text-muted">{job.name}</span>
                                        <span className="flex items-center gap-2">
                                            <span className="text-subtle">{job.when}</span>
                                            <HealthBadge ok={job.enabled}>{job.enabled ? 'on' : 'off'}</HealthBadge>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </SectionCard>

                    <SectionCard title="Actions" icon={Wrench}>
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
                                danger
                                busy={busy}
                                onClick={() => runAction('prune_dry_run')}
                            />
                        </div>
                    </SectionCard>
                </div>

                <SectionCard title="Integration credentials" icon={KeyRound}>
                    <p className="mb-4 text-xs text-subtle">
                        Edit Slack and S3 settings without touching the server. Secret fields are write-only —
                        they show whether a value is set, never the value itself. Leave a secret blank to keep
                        the current one. Saving reloads the config automatically.
                    </p>
                    <div className="grid gap-5 lg:grid-cols-2">
                        {(groups || []).map((group) => (
                            <div key={group.key} className="flex flex-col rounded-lg border border-line bg-surface-2/40 p-4">
                                <h3 className="mb-3 text-sm font-semibold text-body">{group.label}</h3>
                                <div className="space-y-3">
                                    {group.fields.map((field) => (
                                        <Field
                                            key={field.key}
                                            htmlFor={`env-${field.key}`}
                                            label={
                                                <span className="flex items-center justify-between gap-2">
                                                    <span>{field.label}</span>
                                                    {field.type === 'secret' && (
                                                        <span className={cx('text-xs font-medium', field.is_set ? 'text-success' : 'text-subtle')}>
                                                            {field.is_set ? 'set' : 'not set'}
                                                        </span>
                                                    )}
                                                </span>
                                            }
                                        >
                                            {field.type === 'bool' ? (
                                                <Select
                                                    id={`env-${field.key}`}
                                                    value={envDraft[field.key] ? 'true' : 'false'}
                                                    onChange={(e) => setEnvField(field.key, e.target.value === 'true')}
                                                >
                                                    <option value="true">Enabled</option>
                                                    <option value="false">Disabled</option>
                                                </Select>
                                            ) : field.type === 'select' ? (
                                                <Select
                                                    id={`env-${field.key}`}
                                                    value={envDraft[field.key] ?? ''}
                                                    onChange={(e) => setEnvField(field.key, e.target.value)}
                                                >
                                                    {field.options.map((opt) => (
                                                        <option key={opt} value={opt}>{opt}</option>
                                                    ))}
                                                </Select>
                                            ) : (
                                                <Input
                                                    id={`env-${field.key}`}
                                                    type={field.type === 'secret' ? 'password' : 'text'}
                                                    value={envDraft[field.key] ?? ''}
                                                    onChange={(e) => setEnvField(field.key, e.target.value)}
                                                    placeholder={field.type === 'secret' ? (field.is_set ? '•••••••• (leave blank to keep)' : 'not set') : (field.placeholder || '')}
                                                    autoComplete="off"
                                                    className="font-mono"
                                                />
                                            )}
                                        </Field>
                                    ))}
                                </div>
                                <Button
                                    variant="primary"
                                    size="sm"
                                    className="mt-4 self-start"
                                    icon={<KeyRound className="h-3.5 w-3.5" />}
                                    loading={savingGroup === group.key}
                                    onClick={() => saveEnvGroup(group)}
                                >
                                    {savingGroup === group.key ? 'Saving…' : `Save ${group.label.split('(')[0].trim()}`}
                                </Button>
                            </div>
                        ))}
                    </div>
                    <p className="mt-4 text-xs text-subtle">Each integration saves on its own. After saving, use “Test screenshot storage” / “Test Slack webhook” above to verify.</p>
                </SectionCard>

                <SectionCard
                    title="Tracker diagnostics — desktop agents & capture-health"
                    icon={ShieldAlert}
                    actions={
                        <Button
                            variant="secondary"
                            size="sm"
                            loading={diagLoading}
                            icon={<RefreshCw className={cx('h-3.5 w-3.5', diagLoading && 'animate-spin')} />}
                            onClick={loadDiagnostics}
                        >
                            Refresh
                        </Button>
                    }
                >
                    <p className="mb-3 text-xs text-subtle">
                        What the desktop agents reported about themselves (capture blocked, uploads failing,
                        crashes) and every decision the capture-health watchdog made — across all workspaces,
                        newest first. Counts cover the last {diagnostics.window_days} days.
                    </p>

                    <div className="mb-3 flex flex-wrap items-center gap-1.5">
                        {Object.keys(diagnostics.counts).length === 0 ? (
                            <span className="text-xs text-subtle">
                                {diagLoading ? 'Loading…' : 'Nothing captured yet.'}
                            </span>
                        ) : (
                            Object.entries(diagnostics.counts).map(([category, total]) => (
                                <span
                                    key={category}
                                    className="rounded-full border border-line px-2 py-0.5 text-xs text-muted"
                                >
                                    {category} <b className="text-body">{total}</b>
                                </span>
                            ))
                        )}
                        {(diagnostics.levels?.error || 0) > 0 && (
                            <Badge tone="danger">{diagnostics.levels.error} error</Badge>
                        )}
                        {(diagnostics.levels?.warn || 0) > 0 && (
                            <Badge tone="warning">{diagnostics.levels.warn} warn</Badge>
                        )}
                    </div>

                    <div className="max-h-80 overflow-auto rounded-lg border border-line">
                        {diagnostics.recent.length === 0 ? (
                            <p className="p-3 text-xs text-subtle">
                                {diagLoading ? 'Loading…' : 'No events captured — every agent is reporting cleanly.'}
                            </p>
                        ) : (
                            <ul className="divide-y divide-line">
                                {diagnostics.recent.map((event) => (
                                    <li key={event.id} className="flex items-start gap-2 p-2.5">
                                        <span
                                            className={cx(
                                                'mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-xs font-semibold',
                                                event.level === 'error'
                                                    ? 'bg-danger-soft text-danger'
                                                    : event.level === 'warn'
                                                        ? 'bg-warning-soft text-warning'
                                                        : 'bg-info-soft text-info',
                                            )}
                                        >
                                            {event.category}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="break-words font-mono text-xs leading-snug text-body">
                                                {event.summary}
                                            </p>
                                            <p className="text-xs text-subtle">
                                                {[event.captured_at, event.workspace, event.user, event.device_name, event.app_version]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <p className="mt-2 text-xs text-subtle">
                        Retention is enforced nightly by <code className="text-muted">monitoring:prune-screenshots</code>:
                        30 days maximum, and at most 20,000 rows per workspace.
                    </p>
                </SectionCard>

                <SectionCard
                    title={`Logs ${logFile ? `— ${logFile}` : ''}`}
                    icon={AlertTriangle}
                    actions={
                        <Button
                            variant="secondary"
                            size="sm"
                            loading={logsLoading}
                            icon={<RefreshCw className={cx('h-3.5 w-3.5', logsLoading && 'animate-spin')} />}
                            onClick={loadLogs}
                        >
                            Refresh
                        </Button>
                    }
                >
                    <p className="mb-2 text-xs text-subtle">Last 300 lines of the newest log file.</p>
                    <Console className="max-h-80 text-muted">
                        {logLines.length ? logLines.join('\n') : (logsLoading ? 'Loading…' : 'No log entries.')}
                    </Console>
                </SectionCard>
            </div>
        </PlatformLayout>
    );
}
