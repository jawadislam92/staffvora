import { useEffect } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Briefcase, Building2, CheckCircle2, UserPlus, Users } from 'lucide-react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input } from '@/Components/ui';

const cx = (...a) => a.filter(Boolean).join(' ');

/**
 * The workspace-type HINT chosen at signup.
 *
 * It was a bare <select> of four labels, which told a customer nothing about
 * what they were picking or that it was reversible. Cards with one line of
 * consequence each, and an explicit "changeable later", make the choice legible.
 *
 * ⚠️ NONE OF THIS IS A CAPABILITY. The value seeds starter work categories and
 * decides wording; access is resolved server-side by the entitlement engine
 * alone. In particular the copy below never promises a paid module.
 *
 * Note what the descriptions do NOT say: no "Upwork", no "marketplace". Somebody
 * signing up to track an in-house team should not have to read another
 * industry's vocabulary to get past this field. It appears once — below, only
 * when "Client services agency" is actually selected.
 */
const WORKSPACE_TYPES = [
    { value: 'general', label: 'In-house team', hint: 'Track your own employees. Work is filed under Projects.', icon: Building2 },
    { value: 'agency', label: 'Client services agency', hint: 'Deliver and bill work for external clients.', icon: Briefcase },
    { value: 'bpo', label: 'BPO / Call center', hint: 'Shift-based attendance for large teams.', icon: Users },
    { value: 'freelancer', label: 'Freelancer', hint: 'Just you, tracking your own clients.', icon: UserPlus },
];

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        company: '',
        workspace_type: 'general',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    useEffect(() => () => reset('password', 'password_confirmation'), []);

    const submit = (e) => {
        e.preventDefault();
        post(route('register'));
    };

    return (
        <AuthLayout
            title="Start your free trial"
            description="Create your workspace — no card required."
        >
            <Head title="Create your account" />

            <form onSubmit={submit} className="space-y-5">
                <Field label="Company / workspace name" htmlFor="company" error={errors.company}>
                    <Input
                        id="company"
                        name="company"
                        value={data.company}
                        onChange={(e) => setData('company', e.target.value)}
                        placeholder="Acme Inc"
                        autoComplete="organization"
                        autoFocus
                    />
                </Field>

                <Field
                    label="What kind of team are you?"
                    error={errors.workspace_type}
                    hint="Sets the wording and the starter setup for your workspace. You can change it any time in Settings."
                >
                    <div role="radiogroup" aria-label="Workspace type" className="grid gap-2 sm:grid-cols-2">
                        {WORKSPACE_TYPES.map((t) => {
                            const Icon = t.icon;
                            const selected = data.workspace_type === t.value;
                            return (
                                <button
                                    key={t.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={selected}
                                    onClick={() => setData('workspace_type', t.value)}
                                    className={cx(
                                        'flex items-start gap-2.5 rounded-lg border p-3 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app',
                                        selected
                                            ? 'border-accent bg-accent/10 ring-1 ring-accent'
                                            : 'border-line bg-surface-2 hover:border-accent/50'
                                    )}
                                >
                                    <span className={cx(
                                        'mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md',
                                        selected ? 'bg-accent text-white' : 'bg-surface text-muted'
                                    )}
                                    >
                                        <Icon className="h-4 w-4" />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="flex items-center gap-1 text-sm font-semibold text-body">
                                            {t.label}
                                            {selected && <CheckCircle2 className="h-3.5 w-3.5 text-accent" />}
                                        </span>
                                        <span className="mt-0.5 block text-xs leading-4 text-subtle">{t.hint}</span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    {/* Agency-only, and only once it is chosen: the one place at
                        signup where marketplace vocabulary belongs. It states the
                        add-on is paid and optional so nobody picks "agency"
                        believing they have just bought something. */}
                    {data.workspace_type === 'agency' && (
                        <p className="mt-2 rounded-lg border border-line bg-surface-2 px-3 py-2 text-xs leading-5 text-muted">
                            Agencies can add the <strong className="text-body">Agency toolkit</strong> — Upwork Profile
                            tracking and Machine compliance — as a paid add-on. It is included in your free trial and
                            optional after that; you choose in Billing, with the price shown before you confirm.
                        </p>
                    )}
                    <input type="hidden" name="workspace_type" value={data.workspace_type} />
                </Field>

                <Field label="Your name" htmlFor="name" error={errors.name}>
                    <Input
                        id="name"
                        name="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="Jane Doe"
                        autoComplete="name"
                    />
                </Field>

                <Field label="Work email" htmlFor="email" error={errors.email}>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="jane@acme.com"
                        autoComplete="username"
                    />
                </Field>

                <Field label="Password" htmlFor="password" error={errors.password}>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        placeholder="At least 12 characters"
                        autoComplete="new-password"
                    />
                </Field>

                <Field
                    label="Confirm password"
                    htmlFor="password_confirmation"
                    error={errors.password_confirmation}
                >
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        placeholder="Re-enter your password"
                        autoComplete="new-password"
                    />
                </Field>

                <Button type="submit" block loading={processing}>
                    {processing ? 'Creating your workspace…' : 'Create workspace'}
                </Button>
            </form>

            <p className="mt-6 text-center text-sm text-muted">
                Already have an account?{' '}
                <Link
                    href={route('login')}
                    className="rounded font-medium text-accent transition hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                >
                    Sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
