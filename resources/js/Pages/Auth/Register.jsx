import { useEffect } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

function Field({ id, label, type = 'text', value, onChange, error, placeholder, autoComplete, autoFocus = false }) {
    return (
        <div>
            <label htmlFor={id} className="block text-sm font-semibold text-[#374151] mb-2">{label}</label>
            <input
                id={id}
                type={type}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="w-full px-4 py-3 border-2 border-[#e5e7eb] rounded-xl bg-[#f9fafb] text-[#111827] placeholder:text-[#9ca3af] transition-all duration-200 focus:bg-white focus:outline-none"
                onFocus={(e) => { e.target.style.borderColor = '#f97316'; e.target.style.boxShadow = '0 0 0 4px rgba(249, 115, 22, 0.12)'; }}
                onBlur={(e) => { e.target.style.borderColor = '#e5e7eb'; e.target.style.boxShadow = 'none'; }}
                placeholder={placeholder}
                autoComplete={autoComplete}
                autoFocus={autoFocus}
            />
            {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
        </div>
    );
}

export default function Register() {
    const appName = usePage().props?.branding?.name || 'Tracksuite';
    const { data, setData, post, processing, errors, reset } = useForm({
        company: '',
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
        <div className="min-h-screen flex items-center justify-center relative overflow-hidden py-10" style={{ background: 'linear-gradient(135deg, #282a2a 0%, #1f2020 50%, #161717 100%)' }}>
            <Head title="Create your account" />

            <div className="absolute inset-0 overflow-hidden">
                <div className="absolute -top-1/2 -left-1/2 w-full h-full bg-gradient-to-r from-yellow-400 to-orange-500 rounded-full opacity-20 animate-float"></div>
                <div className="absolute -bottom-1/2 -right-1/2 w-full h-full rounded-full opacity-30 animate-float-delayed" style={{ background: 'linear-gradient(45deg, #282a2a, #1f2020)' }}></div>
            </div>

            <div className="relative z-10 w-full max-w-md mx-4">
                <div className="glass-effect shadow-2xl rounded-3xl p-8">
                    <div className="text-center mb-8">
                        <div className="inline-block p-3 rounded-2xl shadow-lg mb-4 animate-float" style={{ background: 'linear-gradient(135deg, #282a2a, #404343)' }}>
                            <ApplicationLogo size="16" />
                        </div>
                        <h1 className="text-3xl font-bold gradient-text mb-2">Start your free trial</h1>
                        <p className="text-gray-600 text-sm">Create your {appName} workspace — no card required.</p>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        <Field id="company" label="Company / workspace name" value={data.company} onChange={(v) => setData('company', v)} error={errors.company} placeholder="Acme Inc" autoComplete="organization" autoFocus />
                        <Field id="name" label="Your name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} placeholder="Jane Doe" autoComplete="name" />
                        <Field id="email" label="Work email" type="email" value={data.email} onChange={(v) => setData('email', v)} error={errors.email} placeholder="jane@acme.com" autoComplete="username" />
                        <Field id="password" label="Password" type="password" value={data.password} onChange={(v) => setData('password', v)} error={errors.password} placeholder="At least 12 characters" autoComplete="new-password" />
                        <Field id="password_confirmation" label="Confirm password" type="password" value={data.password_confirmation} onChange={(v) => setData('password_confirmation', v)} error={errors.password_confirmation} placeholder="Re-enter your password" autoComplete="new-password" />

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full bg-gradient-to-r from-orange-400 to-yellow-500 font-bold text-gray-900 py-3 px-6 rounded-xl transition-all duration-200 transform hover:scale-105 hover:from-orange-500 hover:to-yellow-600 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none shadow-lg"
                        >
                            {processing ? 'Creating your workspace…' : 'Create workspace'}
                        </button>
                    </form>

                    <div className="mt-6 text-center">
                        <p className="text-sm text-gray-600">
                            Already have an account?{' '}
                            <Link href={route('login')} className="font-semibold hover:underline" style={{ color: '#c2410c' }}>Sign in</Link>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
