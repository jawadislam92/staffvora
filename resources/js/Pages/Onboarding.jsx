import { useMemo, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Briefcase,
    Building2,
    Check,
    CheckCircle2,
    Copy,
    MonitorDown,
    Rocket,
    Sparkles,
    UserPlus,
    Users,
} from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const WORKSPACE_TYPES = [
    { value: 'general', label: 'In-house team / company', hint: 'Track your own employees. A clean tracker, no agency jargon.', icon: Building2 },
    { value: 'agency', label: 'Client services agency', hint: 'Adds clients, billable work types, and optional marketplace/account profiles.', icon: Briefcase },
    { value: 'bpo', label: 'BPO / Call center', hint: 'Shift-based attendance for large operational teams.', icon: Users },
    { value: 'freelancer', label: 'Freelancer', hint: 'Just you — track time against your own projects.', icon: UserPlus },
];

// A compact, themed text field that matches the app's surface tokens.
function Field({ id, label, error, ...props }) {
    return (
        <div>
            {label && <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-body">{label}</label>}
            <input
                id={id}
                className="w-full rounded-lg border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-body placeholder:text-subtle transition focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
                {...props}
            />
            {error && <p className="mt-1.5 text-sm text-red-500">{error}</p>}
        </div>
    );
}

function Stepper({ steps, current, onJump }) {
    return (
        <ol className="mb-8 flex flex-wrap items-center gap-2">
            {steps.map((s, i) => {
                const done = i < current;
                const active = i === current;
                return (
                    <li key={s.key} className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => onJump(i)}
                            className={[
                                'flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold transition',
                                active ? 'bg-accent text-white shadow-sm shadow-black/20'
                                    : done ? 'bg-accent/15 text-accent hover:bg-accent/25'
                                        : 'bg-surface-2 text-subtle hover:text-body',
                            ].join(' ')}
                        >
                            <span className="flex h-4 w-4 items-center justify-center">
                                {done ? <Check className="h-3.5 w-3.5" /> : <span>{i + 1}</span>}
                            </span>
                            <span className="hidden sm:inline">{s.title}</span>
                        </button>
                        {i < steps.length - 1 && <span className="h-px w-4 bg-line" aria-hidden="true" />}
                    </li>
                );
            })}
        </ol>
    );
}

export default function Onboarding({ workspaceType, clientTerm, usesProfiles, workTypes, progress, seats, downloads }) {
    const { auth, flash } = usePage().props;
    const [step, setStep] = useState(0);

    // ── Step 1: workspace type ──────────────────────────────────────────────
    const typeForm = useForm({ workspace_type: workspaceType || 'general' });
    const saveType = (value) => {
        typeForm.setData('workspace_type', value);
        router.put(route('onboarding.workspace-type'), { workspace_type: value }, { preserveScroll: true, preserveState: true });
    };

    // ── Step 2: invite team ─────────────────────────────────────────────────
    const inviteForm = useForm({ name: '', email: '' });
    const [invited, setInvited] = useState([]);
    const [copied, setCopied] = useState(null);
    const sendInvite = (e) => {
        e.preventDefault();
        inviteForm.post(route('onboarding.team'), {
            preserveScroll: true,
            onSuccess: (page) => {
                const inv = page.props.flash?.invited;
                if (inv) setInvited((list) => [...list, inv]);
                inviteForm.reset();
            },
        });
    };
    const copyCreds = (inv) => {
        const text = `Staffvora login\nEmail: ${inv.email}\nTemporary password: ${inv.password}`;
        navigator.clipboard?.writeText(text);
        setCopied(inv.email);
        setTimeout(() => setCopied(null), 2000);
    };

    // ── Step 4: first client ────────────────────────────────────────────────
    const clientForm = useForm({ name: '', work_type: 'tracker_manual' });
    const [clientCreated, setClientCreated] = useState(progress?.has_client ?? false);
    const createClient = (e) => {
        e.preventDefault();
        clientForm.post(route('onboarding.client'), {
            preserveScroll: true,
            onSuccess: () => { setClientCreated(true); clientForm.reset('name'); },
        });
    };

    const finish = () => router.post(route('onboarding.complete'));

    const steps = useMemo(() => [
        { key: 'type', title: 'Workspace' },
        { key: 'team', title: 'Invite team' },
        { key: 'tracker', title: 'Install tracker' },
        { key: 'client', title: `First ${clientTerm.toLowerCase()}` },
        { key: 'done', title: 'Finish' },
    ], [clientTerm]);

    const next = () => setStep((s) => Math.min(s + 1, steps.length - 1));
    const back = () => setStep((s) => Math.max(s - 1, 0));

    const NavRow = ({ children }) => (
        <div className="mt-8 flex items-center justify-between border-t border-line pt-5">
            <button
                type="button"
                onClick={back}
                disabled={step === 0}
                className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted transition hover:bg-surface-2 hover:text-body disabled:cursor-not-allowed disabled:opacity-40"
            >
                <ArrowLeft className="h-4 w-4" /> Back
            </button>
            <div className="flex items-center gap-3">{children}</div>
        </div>
    );

    const NextButton = ({ label = 'Continue' }) => (
        <button
            type="button"
            onClick={next}
            className="inline-flex items-center gap-1.5 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-black/20 transition hover:opacity-90"
        >
            {label} <ArrowRight className="h-4 w-4" />
        </button>
    );

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Set up your workspace" />

            <div className="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6 lg:py-12">
                <div className="mb-8 text-center">
                    <span className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-accent/15 text-accent">
                        <Sparkles className="h-6 w-6" />
                    </span>
                    <h1 className="text-2xl font-bold text-body sm:text-3xl">Welcome, {auth.user?.name?.split(' ')[0] || 'there'} 👋</h1>
                    <p className="mt-2 text-sm text-muted">Four quick steps to get your team tracking. You can skip any of them.</p>
                </div>

                <div className="rounded-2xl border border-line bg-surface p-6 shadow-sm sm:p-8">
                    <Stepper steps={steps} current={step} onJump={setStep} />

                    {/* STEP 1 — Workspace type */}
                    {step === 0 && (
                        <div>
                            <h2 className="text-lg font-semibold text-body">What kind of team are you?</h2>
                            <p className="mt-1 text-sm text-muted">This tailors your workspace. You can change it later in Settings.</p>
                            <div className="mt-5 grid gap-3 sm:grid-cols-2">
                                {WORKSPACE_TYPES.map((t) => {
                                    const Icon = t.icon;
                                    const selected = typeForm.data.workspace_type === t.value;
                                    return (
                                        <button
                                            key={t.value}
                                            type="button"
                                            onClick={() => saveType(t.value)}
                                            className={[
                                                'flex items-start gap-3 rounded-xl border p-4 text-left transition',
                                                selected ? 'border-accent bg-accent/10 ring-1 ring-accent' : 'border-line bg-surface-2 hover:border-accent/50',
                                            ].join(' ')}
                                        >
                                            <span className={['mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', selected ? 'bg-accent text-white' : 'bg-surface text-muted'].join(' ')}>
                                                <Icon className="h-5 w-5" />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="flex items-center gap-1.5 text-sm font-semibold text-body">
                                                    {t.label}
                                                    {selected && <CheckCircle2 className="h-4 w-4 text-accent" />}
                                                </span>
                                                <span className="mt-0.5 block text-xs text-subtle">{t.hint}</span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            <NavRow><NextButton /></NavRow>
                        </div>
                    )}

                    {/* STEP 2 — Invite team */}
                    {step === 1 && (
                        <div>
                            <h2 className="text-lg font-semibold text-body">Invite your team</h2>
                            <p className="mt-1 text-sm text-muted">
                                We create each teammate an account and give you a one-time password to share.
                                {seats?.limit != null && <> Your plan includes {seats.limit} seats ({seats.used} used).</>}
                            </p>

                            <form onSubmit={sendInvite} className="mt-5 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                <Field id="invite-name" label="Name" value={inviteForm.data.name} onChange={(e) => inviteForm.setData('name', e.target.value)} placeholder="Jane Doe" error={inviteForm.errors.name} />
                                <Field id="invite-email" label="Work email" type="email" value={inviteForm.data.email} onChange={(e) => inviteForm.setData('email', e.target.value)} placeholder="jane@acme.com" error={inviteForm.errors.email} />
                                <button type="submit" disabled={inviteForm.processing} className="inline-flex h-[42px] items-center justify-center gap-1.5 rounded-lg bg-accent px-4 text-sm font-semibold text-white shadow-sm shadow-black/20 transition hover:opacity-90 disabled:opacity-50">
                                    <UserPlus className="h-4 w-4" /> Add
                                </button>
                            </form>

                            {invited.length > 0 && (
                                <ul className="mt-5 space-y-2">
                                    {invited.map((inv) => (
                                        <li key={inv.email} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface-2 px-4 py-3">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-body">{inv.name} <span className="text-subtle">· {inv.email}</span></p>
                                                <p className="text-xs text-subtle">Temp password: <code className="rounded bg-surface px-1.5 py-0.5 font-mono text-body">{inv.password}</code></p>
                                            </div>
                                            <button type="button" onClick={() => copyCreds(inv)} className="inline-flex items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-xs font-medium text-muted transition hover:bg-surface hover:text-body">
                                                {copied === inv.email ? <><Check className="h-3.5 w-3.5 text-accent" /> Copied</> : <><Copy className="h-3.5 w-3.5" /> Copy login</>}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <NavRow><NextButton label={invited.length ? 'Continue' : 'Skip for now'} /></NavRow>
                        </div>
                    )}

                    {/* STEP 3 — Install tracker */}
                    {step === 2 && (
                        <div>
                            <h2 className="text-lg font-semibold text-body">Install the desktop tracker</h2>
                            <p className="mt-1 text-sm text-muted">The desktop app records hours, screenshots and activity. Install it on each tracked computer.</p>
                            <div className="mt-5 grid gap-3 sm:grid-cols-2">
                                <a href={downloads?.windows || downloads?.page} className="flex items-center gap-3 rounded-xl border border-line bg-surface-2 p-4 transition hover:border-accent/50">
                                    <MonitorDown className="h-6 w-6 text-accent" />
                                    <span>
                                        <span className="block text-sm font-semibold text-body">Download for Windows</span>
                                        <span className="block text-xs text-subtle">{downloads?.windows ? 'Installer ready' : 'See all downloads'}</span>
                                    </span>
                                </a>
                                <a href={downloads?.mac || downloads?.page} className="flex items-center gap-3 rounded-xl border border-line bg-surface-2 p-4 transition hover:border-accent/50">
                                    <MonitorDown className="h-6 w-6 text-accent" />
                                    <span>
                                        <span className="block text-sm font-semibold text-body">Download for macOS</span>
                                        <span className="block text-xs text-subtle">{downloads?.mac ? 'Installer ready' : 'See all downloads'}</span>
                                    </span>
                                </a>
                            </div>
                            <p className="mt-4 text-xs text-subtle">Teammates sign in to the desktop app with the credentials you shared in the previous step.</p>
                            <NavRow><NextButton label="I've installed it" /></NavRow>
                        </div>
                    )}

                    {/* STEP 4 — First client/project */}
                    {step === 3 && (
                        <div>
                            <h2 className="text-lg font-semibold text-body">Create your first {clientTerm.toLowerCase()}</h2>
                            <p className="mt-1 text-sm text-muted">Work is logged against a {clientTerm.toLowerCase()}. Add one to get started — you can add more anytime.</p>

                            <form onSubmit={createClient} className="mt-5 space-y-4">
                                <Field id="client-name" label={`${clientTerm} name`} value={clientForm.data.name} onChange={(e) => clientForm.setData('name', e.target.value)} placeholder={usesProfiles ? 'Acme Corp' : 'Internal Projects'} error={clientForm.errors.name} />
                                <div>
                                    <label htmlFor="work_type" className="mb-1.5 block text-sm font-medium text-body">Work type</label>
                                    <select
                                        id="work_type"
                                        value={clientForm.data.work_type}
                                        onChange={(e) => clientForm.setData('work_type', e.target.value)}
                                        className="w-full rounded-lg border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-body transition focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
                                    >
                                        {Object.entries(workTypes).map(([value, label]) => (
                                            <option key={value} value={value}>{label}</option>
                                        ))}
                                    </select>
                                    {clientForm.errors.work_type && <p className="mt-1.5 text-sm text-red-500">{clientForm.errors.work_type}</p>}
                                </div>
                                <button type="submit" disabled={clientForm.processing} className="inline-flex items-center gap-1.5 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-black/20 transition hover:opacity-90 disabled:opacity-50">
                                    <Briefcase className="h-4 w-4" /> Create {clientTerm.toLowerCase()}
                                </button>
                                {clientCreated && (
                                    <p className="flex items-center gap-1.5 text-sm font-medium text-accent"><CheckCircle2 className="h-4 w-4" /> Created! Add another or continue.</p>
                                )}
                            </form>

                            <NavRow><NextButton label={clientCreated ? 'Continue' : 'Skip for now'} /></NavRow>
                        </div>
                    )}

                    {/* STEP 5 — Finish */}
                    {step === 4 && (
                        <div className="text-center">
                            <span className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-accent/15 text-accent">
                                <Rocket className="h-7 w-7" />
                            </span>
                            <h2 className="text-xl font-semibold text-body">You're ready to go</h2>
                            <p className="mx-auto mt-2 max-w-md text-sm text-muted">
                                Your workspace is set up. Head to the dashboard to see hours roll in as your team starts tracking.
                            </p>
                            <button
                                type="button"
                                onClick={finish}
                                className="mt-6 inline-flex items-center gap-1.5 rounded-lg bg-accent px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-black/20 transition hover:opacity-90"
                            >
                                Go to dashboard <ArrowRight className="h-4 w-4" />
                            </button>
                        </div>
                    )}
                </div>

                <div className="mt-5 text-center">
                    <button type="button" onClick={finish} className="text-sm font-medium text-subtle transition hover:text-body">
                        Skip setup — I'll do this later
                    </button>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
