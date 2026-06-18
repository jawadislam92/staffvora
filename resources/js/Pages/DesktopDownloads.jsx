import { useState } from 'react';
import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Apple, CheckCircle2, Copy, Download, FolderOpen, Laptop, MonitorDown, Network, ShieldAlert, ShieldCheck } from 'lucide-react';

const MAC_QUARANTINE_CMD = 'xattr -dr com.apple.quarantine "/Applications/Timetracker Desktop.app"';

// Per-user install location (electron-builder NSIS, perMachine:false). The folder
// is named after the app's productName — "SA Track" on current builds, but machines
// first set up on an older build may still show "Timetracker Desktop".
const WIN_INSTALL_PATH = '%LocalAppData%\\Programs\\SA Track';

function CopyChip({ value }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            /* clipboard may be blocked; user can select manually */
        }
    };

    return (
        <div className="flex items-stretch gap-2">
            <code className="min-w-0 flex-1 overflow-x-auto rounded bg-slate-900 px-3 py-2 font-mono text-xs text-emerald-200">
                {value}
            </code>
            <button
                type="button"
                onClick={copy}
                className="inline-flex shrink-0 items-center gap-1 rounded bg-slate-900 px-3 text-xs font-semibold text-white transition hover:bg-slate-800"
            >
                <Copy className="h-3.5 w-3.5" />
                {copied ? 'Copied' : 'Copy'}
            </button>
        </div>
    );
}

function AntivirusHelp() {
    return (
        <div className="mt-5 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div className="mb-3 flex items-center gap-2">
                <ShieldAlert className="h-5 w-5 text-amber-600" />
                <h2 className="text-base font-semibold text-slate-950">Antivirus blocking the install or sign-in? (Windows)</h2>
            </div>
            <p className="text-sm leading-6 text-slate-600">
                SA Track is our own tracker, but because it’s an unsigned app that takes screenshots and samples
                keyboard/mouse activity, antivirus can flag it as a false positive — the same exclusion every
                monitoring tool (Hubstaff, Time Doctor) needs. Symptoms: the installer says
                <span className="font-medium text-slate-800"> “cannot be closed,”</span> the app gets quarantined, or sign-in
                shows <span className="font-medium text-slate-800"> “Could not reach the server.”</span> Add an exception once
                per machine — the steps for the two most common are below.
            </p>

            <div className="mt-4 rounded-lg bg-slate-50 p-4">
                <div className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <FolderOpen className="h-4 w-4" />
                    First: find the install folder (you’ll need its path below)
                </div>
                <ol className="ml-4 list-decimal space-y-1 text-sm text-slate-700">
                    <li>Right-click the <span className="font-medium">SA Track</span> desktop or Start-menu shortcut → <span className="font-medium">Open file location</span>.</li>
                    <li>In the address bar, click once to reveal the full path and copy it. That’s the folder to exclude.</li>
                </ol>
                <p className="mt-2 text-xs text-slate-500">It’s normally this (paste into the address bar to confirm — older installs may read “Timetracker Desktop”):</p>
                <div className="mt-2">
                    <CopyChip value={WIN_INSTALL_PATH} />
                </div>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                {/* Bitdefender */}
                <div className="rounded-lg border border-rose-100 bg-rose-50/40 p-4">
                    <div className="mb-3 flex items-center gap-2">
                        <ShieldCheck className="h-4 w-4 text-rose-600" />
                        <div className="text-sm font-semibold text-slate-900">Bitdefender Total Security</div>
                    </div>

                    <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">A · Exclude the folder</div>
                    <ol className="ml-4 list-decimal space-y-1 text-sm text-slate-700">
                        <li>Open <span className="font-medium">Bitdefender</span> → left sidebar <span className="font-medium">Protection</span>.</li>
                        <li>In the <span className="font-medium">Antivirus</span> tile click <span className="font-medium">Open</span>.</li>
                        <li><span className="font-medium">Settings</span> tab → <span className="font-medium">Manage Exceptions</span> → <span className="font-medium">+ Add an Exception</span>.</li>
                        <li>Paste the install-folder path and turn <span className="font-medium">ON</span> every toggle — especially <span className="font-semibold text-rose-700">Advanced Threat Defense</span> (this one blocks SA&nbsp;Track.exe) and <span className="font-medium">Online Threat Prevention</span>. Click <span className="font-medium">Save</span>.</li>
                    </ol>

                    <div className="mb-1 mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">B · Allow it through the Firewall</div>
                    <p className="mb-1 text-xs text-slate-500">Required, or sign-in fails with “Could not reach the server.”</p>
                    <ol className="ml-4 list-decimal space-y-1 text-sm text-slate-700">
                        <li><span className="font-medium">Protection</span> → <span className="font-medium">Firewall</span> → <span className="font-medium">Rules</span>.</li>
                        <li>Search <span className="font-medium">SA Track</span>. Set the top <span className="font-medium">sa track.exe</span> rule’s <span className="font-medium">Access</span> to <span className="font-semibold text-emerald-700">ON</span> (Any network, Any protocol, Both). Use <span className="font-medium">Add rule</span> if none exists.</li>
                    </ol>

                    <div className="mb-1 mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">C · If it was already blocked</div>
                    <ol className="ml-4 list-decimal space-y-1 text-sm text-slate-700">
                        <li>Open the <span className="font-medium">Notifications</span> bell → find the SA Track “Threat blocked” entry → <span className="font-medium">Allow / Restore</span>.</li>
                        <li>Reinstall from this page, then sign in.</li>
                    </ol>
                </div>

                {/* Windows Defender */}
                <div className="rounded-lg border border-blue-100 bg-blue-50/40 p-4">
                    <div className="mb-3 flex items-center gap-2">
                        <Network className="h-4 w-4 text-blue-600" />
                        <div className="text-sm font-semibold text-slate-900">Windows Security (Defender)</div>
                    </div>

                    <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">A · Exclude the folder</div>
                    <ol className="ml-4 list-decimal space-y-1 text-sm text-slate-700">
                        <li>Press <span className="font-medium">Start</span>, type <span className="font-medium">Windows Security</span>, open it.</li>
                        <li><span className="font-medium">Virus &amp; threat protection</span>.</li>
                        <li>Under <span className="font-medium">Virus &amp; threat protection settings</span> click <span className="font-medium">Manage settings</span>.</li>
                        <li>Scroll to <span className="font-medium">Exclusions</span> → <span className="font-medium">Add or remove exclusions</span> → <span className="font-medium">Add an exclusion</span> → <span className="font-medium">Folder</span>.</li>
                        <li>Paste the install-folder path and confirm (approve the admin prompt).</li>
                    </ol>

                    <div className="mb-1 mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">B · If it was already quarantined</div>
                    <ol className="ml-4 list-decimal space-y-1 text-sm text-slate-700">
                        <li><span className="font-medium">Virus &amp; threat protection</span> → <span className="font-medium">Protection history</span>.</li>
                        <li>Find the SA Track item → <span className="font-medium">Actions</span> → <span className="font-medium">Restore</span> (or <span className="font-medium">Allow on device</span>), then reinstall.</li>
                    </ol>

                    <div className="mb-1 mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">C · The blue “Windows protected your PC” box</div>
                    <ol className="ml-4 list-decimal space-y-1 text-sm text-slate-700">
                        <li>This is SmartScreen, not a virus warning. Click <span className="font-medium">More info</span>.</li>
                        <li>Click <span className="font-medium">Run anyway</span> to launch the installer.</li>
                    </ol>
                    <p className="mt-3 text-xs text-slate-500">Defender’s firewall allows the app’s outbound connection by default, so no firewall rule is usually needed here.</p>
                </div>
            </div>

            <p className="mt-4 text-xs text-slate-500">
                Also seen on some machines: <span className="font-medium text-slate-700">NordVPN Threat Protection</span> can block the connection too —
                pause it or add SA Track to its exceptions if sign-in still can’t reach the server.
            </p>
        </div>
    );
}

function MacInstallHelp() {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(MAC_QUARANTINE_CMD);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            /* clipboard may be blocked; user can select manually */
        }
    };

    return (
        <div className="mt-5 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div className="mb-3 flex items-center gap-2">
                <Apple className="h-5 w-5 text-slate-700" />
                <h2 className="text-base font-semibold text-slate-950">Opening the app on macOS (first launch)</h2>
            </div>
            <p className="text-sm leading-6 text-slate-600">
                The Mac build is not yet signed with an Apple Developer ID, so on first launch macOS shows
                <span className="font-medium text-slate-800"> “Apple could not verify… Not Opened.”</span> This is expected.
                <span className="font-semibold text-rose-600"> Do not click “Move to Bin.”</span> Unblock it once per Mac:
            </p>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <div className="rounded-lg bg-slate-50 p-4">
                    <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Option A — Terminal (most reliable)</div>
                    <ol className="ml-4 list-decimal space-y-1 text-sm text-slate-700">
                        <li>Drag <span className="font-medium">Timetracker Desktop</span> into <span className="font-medium">Applications</span>.</li>
                        <li>Open <span className="font-medium">Terminal</span> and run the command below.</li>
                        <li>Double-click the app in Applications — it opens normally.</li>
                    </ol>
                    <div className="mt-3 flex items-stretch gap-2">
                        <code className="min-w-0 flex-1 overflow-x-auto rounded bg-slate-900 px-3 py-2 font-mono text-xs text-emerald-200">
                            {MAC_QUARANTINE_CMD}
                        </code>
                        <button
                            type="button"
                            onClick={copy}
                            className="inline-flex shrink-0 items-center gap-1 rounded bg-slate-900 px-3 text-xs font-semibold text-white transition hover:bg-slate-800"
                        >
                            <Copy className="h-3.5 w-3.5" />
                            {copied ? 'Copied' : 'Copy'}
                        </button>
                    </div>
                </div>

                <div className="rounded-lg bg-slate-50 p-4">
                    <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Option B — No Terminal</div>
                    <ol className="ml-4 list-decimal space-y-1 text-sm text-slate-700">
                        <li>Click <span className="font-medium">Done</span> on the warning (not “Move to Bin”).</li>
                        <li>Open  → <span className="font-medium">System Settings</span> → <span className="font-medium">Privacy &amp; Security</span>.</li>
                        <li>Scroll down — click <span className="font-medium">Open Anyway</span> next to “Timetracker Desktop”.</li>
                        <li>Launch the app again and click <span className="font-medium">Open</span>.</li>
                    </ol>
                    <p className="mt-3 text-xs text-slate-500">
                        After opening once, approve the prompts for Screen Recording, Accessibility, and Automation (per browser, for URL capture).
                    </p>
                </div>
            </div>
        </div>
    );
}

function formatBytes(bytes) {
    if (!bytes) {
        return null;
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit += 1;
    }

    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function DownloadCard({ title, subtitle, icon: Icon, accent, download }) {
    const available = download?.available;

    return (
        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex items-start justify-between gap-4">
                <div className="flex min-w-0 items-start gap-4">
                    <div className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-lg ${accent}`}>
                        <Icon className="h-6 w-6" />
                    </div>
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold text-slate-950">{title}</h2>
                        <p className="mt-1 text-sm text-slate-500">{subtitle}</p>
                    </div>
                </div>
                <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${available ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                    {available ? 'Available' : 'Pending'}
                </span>
            </div>

            <div className="mt-5 grid gap-3 rounded-lg bg-slate-50 p-4 text-sm text-slate-600 sm:grid-cols-2">
                <div>
                    <div className="text-xs font-semibold uppercase text-slate-400">Version</div>
                    <div className="mt-1 font-medium text-slate-900">{download?.version || 'Not built'}</div>
                </div>
                <div>
                    <div className="text-xs font-semibold uppercase text-slate-400">Size</div>
                    <div className="mt-1 font-medium text-slate-900">{formatBytes(download?.size) || '-'}</div>
                </div>
                <div className="sm:col-span-2">
                    <div className="text-xs font-semibold uppercase text-slate-400">SHA256</div>
                    <div className="mt-1 break-all font-mono text-xs text-slate-700">{download?.sha256 || '-'}</div>
                </div>
            </div>

            <div className="mt-5">
                {available ? (
                    <a
                        href={download.url}
                        className="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 sm:w-auto"
                    >
                        <Download className="h-4 w-4" />
                        Download
                    </a>
                ) : (
                    <button
                        type="button"
                        disabled
                        className="inline-flex w-full cursor-not-allowed items-center justify-center gap-2 rounded-lg bg-slate-200 px-4 py-3 text-sm font-semibold text-slate-500 sm:w-auto"
                    >
                        <Download className="h-4 w-4" />
                        Download
                    </button>
                )}
            </div>
        </section>
    );
}

export default function DesktopDownloads({ auth, downloads }) {
    return (
        <AuthenticatedLayout user={auth.user} header="Desktop App">
            <Head title="Desktop App" />

            <div className="min-h-screen bg-slate-950">
            <div className="w-full px-4 py-6 sm:px-6 lg:px-8">
                <div className="mb-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <div className="flex items-center gap-2 text-sm font-semibold uppercase text-blue-600">
                                <MonitorDown className="h-4 w-4" />
                                Employee tracker
                            </div>
                            <h1 className="mt-2 text-2xl font-bold text-slate-950">Download Timetracker Desktop</h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                Install the desktop tracker on company machines for screenshot capture, activity sampling,
                                and automatic report entries.
                            </p>
                        </div>
                        <div className="flex items-center gap-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            <ShieldAlert className="h-5 w-5 shrink-0" />
                            Unsigned local build
                        </div>
                    </div>
                </div>

                <div className="grid gap-5 xl:grid-cols-2">
                    <DownloadCard
                        title="Windows PC"
                        subtitle="Windows 10 or later, 64-bit"
                        icon={Laptop}
                        accent="bg-blue-50 text-blue-700"
                        download={downloads.windows}
                    />
                    <DownloadCard
                        title="macOS"
                        subtitle="Apple Silicon (M1/M2/M3), macOS 12 or later"
                        icon={Apple}
                        accent="bg-slate-100 text-slate-700"
                        download={downloads.mac}
                    />
                </div>

                {downloads.mac?.available && <MacInstallHelp />}

                <AntivirusHelp />

                <div className="mt-5 grid gap-4 lg:grid-cols-3">
                    <div className="rounded-lg border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-900">
                        <div className="mb-2 flex items-center gap-2 font-semibold">
                            <CheckCircle2 className="h-4 w-4" />
                            Sign in with web credentials
                        </div>
                        <p className="leading-6">Employees use the same Timetracker account after installation.</p>
                    </div>
                    <div className="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                        <div className="mb-2 font-semibold text-slate-900">Server URL</div>
                        <p className="leading-6">Use the company Timetracker URL when the desktop app asks for the server.</p>
                    </div>
                    <div className="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                        <div className="mb-2 font-semibold text-slate-900">Company rollout</div>
                        <p className="leading-6">For managed installs, distribute the Windows installer through your IT tool.</p>
                    </div>
                </div>
            </div>
            </div>
        </AuthenticatedLayout>
    );
}
