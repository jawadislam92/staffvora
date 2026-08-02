import { Head } from '@inertiajs/react';
import { Apple, CheckCircle2, Download, Laptop, MonitorDown, ShieldAlert } from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Badge } from '@/Components/ui';

const cx = (...a) => a.filter(Boolean).join(' ');

// --- Preview sample (offline dev render) ------------------------------------
// Mirrors the shape the controller sends: downloads.windows / downloads.mac.
const DEV_SAMPLE = {
    user: { name: 'Jawad', is_super_admin: true, permissions: [] },
    downloads: {
        windows: {
            available: true,
            version: '0.3.4',
            filename: 'Staffvora Desktop Setup 0.3.4.exe',
            size: 96_468_992,
            sha256: 'a1b2c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff00',
            url: '#',
        },
        mac: {
            available: true,
            version: '0.3.2',
            filename: 'Staffvora Desktop-0.3.2-arm64.dmg',
            size: 118_293_504,
            sha256: '00ffeeddccbbaa998877665544332211ffee00cc11dd22aa33bb44cc55dd66ee',
            url: '#',
        },
    },
};

function formatBytes(bytes) {
    if (!bytes) return null;

    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit += 1;
    }

    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function MetaRow({ label, value, mono = false }) {
    return (
        <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-subtle">{label}</dt>
            <dd className={cx('mt-1 break-all text-body', mono ? 'font-mono text-xs text-muted' : 'text-sm font-medium')}>
                {value}
            </dd>
        </div>
    );
}

function DownloadCard({ title, subtitle, icon: Icon, download, recommended = false }) {
    const available = Boolean(download?.available);

    return (
        <Card className="flex flex-col">
            <div className="flex items-start justify-between gap-4">
                <div className="flex min-w-0 items-start gap-4">
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                        <Icon className="h-6 w-6" />
                    </div>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-lg font-semibold text-body">{title}</h2>
                            {recommended && <Badge tone="accent">Recommended</Badge>}
                        </div>
                        <p className="mt-1 text-sm text-subtle">{subtitle}</p>
                    </div>
                </div>
                <Badge tone={available ? 'success' : 'neutral'} dot className="shrink-0">
                    {available ? 'Preview available' : 'Pending'}
                </Badge>
            </div>

            <dl className="mt-5 grid gap-3 rounded-lg bg-surface-2 p-4 sm:grid-cols-2">
                <MetaRow label="Version" value={download?.version || 'Not built'} />
                <MetaRow label="Size" value={formatBytes(download?.size) || '-'} />
                <div className="sm:col-span-2">
                    <MetaRow label="File" value={download?.filename || '-'} mono />
                </div>
                <div className="sm:col-span-2">
                    <MetaRow label="SHA-256" value={download?.sha256 || '-'} mono />
                </div>
            </dl>

            <div className="mt-5">
                {available ? (
                    <Button href={download.url} icon={<Download className="h-4 w-4" />} className="w-full sm:w-auto">
                        Download preview
                    </Button>
                ) : (
                    <Button
                        variant="secondary"
                        disabled
                        icon={<Download className="h-4 w-4" />}
                        className="w-full sm:w-auto"
                    >
                        Not available
                    </Button>
                )}
            </div>
        </Card>
    );
}

export default function DesktopDownloads({ auth, downloads, preview = false }) {
    const user = preview ? DEV_SAMPLE.user : auth.user;
    const data = preview ? DEV_SAMPLE.downloads : downloads;

    return (
        <AuthenticatedLayout user={user} header="Desktop App">
            <Head title="Desktop App" />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <Card>
                    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <div className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-accent">
                                <MonitorDown className="h-4 w-4" />
                                Optional desktop tracker
                            </div>
                            <h1 className="mt-2 text-2xl font-bold text-body">Download Staffvora Desktop</h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-muted">
                                Track time from Windows or macOS. Screenshot and activity features run only while
                                tracking and only when enabled by the workspace.
                            </p>
                        </div>
                        <div className="flex shrink-0 items-center gap-2 rounded-lg bg-warning-soft px-4 py-3 text-sm font-semibold text-warning">
                            <ShieldAlert className="h-5 w-5 shrink-0" />
                            Preview — signing required
                        </div>
                    </div>
                </Card>

                <div className="grid gap-6 xl:grid-cols-2">
                    <DownloadCard
                        title="Windows PC"
                        subtitle="Windows 10 or later, 64-bit"
                        icon={Laptop}
                        download={data.windows}
                        recommended
                    />
                    <DownloadCard
                        title="macOS"
                        subtitle="Apple Silicon, macOS 12 or later"
                        icon={Apple}
                        download={data.mac}
                    />
                </div>

                <div className="flex items-start gap-3 rounded-lg border border-warning/40 bg-warning-soft p-5 text-sm leading-6 text-warning">
                    <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0" />
                    <p>
                        These installers are for controlled evaluation. They must be code-signed, notarized on macOS,
                        and tested through a clean release pipeline before broad customer rollout. Do not ask employees
                        to disable or bypass security software to install Staffvora.
                    </p>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card>
                        <div className="mb-2 flex items-center gap-2 font-semibold text-body">
                            <CheckCircle2 className="h-4 w-4 text-success" />
                            One account
                        </div>
                        <p className="text-sm leading-6 text-muted">Use the same Staffvora credentials on the web and desktop.</p>
                    </Card>
                    <Card>
                        <div className="mb-2 font-semibold text-body">Workspace server</div>
                        <p className="text-sm leading-6 text-muted">Enter the workspace's Staffvora URL when the app asks for a server.</p>
                    </Card>
                    <Card>
                        <div className="mb-2 font-semibold text-body">Managed rollout</div>
                        <p className="text-sm leading-6 text-muted">After signing, distribute verified installers through your IT tooling.</p>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
