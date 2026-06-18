import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

const inputClasses = 'form-input w-full px-4 py-3 border-2 border-gray-200 rounded-xl transition-all duration-200 bg-gray-50 focus:bg-white';

function FieldError({ message }) {
    if (!message) return null;
    return (
        <p className="mt-2 text-sm text-red-600 flex items-center">
            <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
            </svg>
            {message}
        </p>
    );
}

export default function ForgotPassword({ status }) {
    const [step, setStep] = useState(1);

    const requestForm = useForm({ email: '' });
    const resetForm = useForm({ email: '', code: '', password: '', password_confirmation: '' });

    const requestCode = (e) => {
        e.preventDefault();
        requestForm.post(route('password.code.request'), {
            preserveScroll: true,
            onSuccess: () => {
                resetForm.setData('email', requestForm.data.email);
                setStep(2);
            },
        });
    };

    const submitReset = (e) => {
        e.preventDefault();
        resetForm.post(route('password.code.reset'), { preserveScroll: true });
    };

    return (
        <div className="min-h-screen flex items-center justify-center relative overflow-hidden" style={{ background: 'linear-gradient(135deg, #282a2a 0%, #1f2020 50%, #161717 100%)' }}>
            <Head title="Forgot Password" />

            <div className="absolute inset-0 overflow-hidden">
                <div className="absolute -top-1/2 -left-1/2 w-full h-full bg-gradient-to-r from-yellow-400 to-orange-500 rounded-full opacity-20 animate-float"></div>
                <div className="absolute -bottom-1/2 -right-1/2 w-full h-full rounded-full opacity-30 animate-float-delayed" style={{ background: 'linear-gradient(45deg, #282a2a, #1f2020)' }}></div>
            </div>

            <div className="relative z-10 w-full max-w-md mx-4">
                <div className="glass-effect shadow-2xl rounded-3xl p-8">
                    <div className="text-center mb-8">
                        <div className="inline-block p-3 rounded-2xl shadow-lg mb-4" style={{ background: 'linear-gradient(135deg, #282a2a, #404343)' }}>
                            <ApplicationLogo size="16" />
                        </div>
                        <h1 className="text-3xl font-bold gradient-text mb-2">Reset Password</h1>
                        <p className="text-gray-600 text-sm">
                            {step === 1
                                ? 'Enter your account email and we will send a 6-digit code to your Slack.'
                                : 'Enter the code from Slack and choose a new password.'}
                        </p>
                    </div>

                    {status && (
                        <div className="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl">
                            <p className="text-green-700 text-sm font-medium">{status}</p>
                        </div>
                    )}

                    {step === 1 ? (
                        <form onSubmit={requestCode} className="space-y-6">
                            <div>
                                <label htmlFor="email" className="block text-sm font-semibold text-gray-700 mb-2">
                                    Email Address
                                </label>
                                <input
                                    id="email"
                                    type="email"
                                    value={requestForm.data.email}
                                    onChange={(e) => requestForm.setData('email', e.target.value)}
                                    className={inputClasses}
                                    placeholder="you@sparkingasia.com"
                                    autoComplete="username"
                                    autoFocus
                                    required
                                />
                                <FieldError message={requestForm.errors.email} />
                            </div>

                            <button
                                type="submit"
                                disabled={requestForm.processing}
                                className="btn-primary w-full rounded-xl bg-gradient-to-r from-orange-400 to-yellow-500 py-3 font-bold text-gray-900 shadow-lg transition hover:scale-105 hover:from-orange-500 hover:to-yellow-600 disabled:opacity-50"
                            >
                                Send Code to Slack
                            </button>

                            <div className="flex items-center justify-between text-sm">
                                <Link href={route('login')} className="text-gray-600 hover:text-gray-900 font-medium">
                                    Back to sign in
                                </Link>
                                <button type="button" onClick={() => setStep(2)} className="text-gray-600 hover:text-gray-900 font-medium">
                                    I already have a code
                                </button>
                            </div>
                        </form>
                    ) : (
                        <form onSubmit={submitReset} className="space-y-5">
                            <div>
                                <label htmlFor="reset-email" className="block text-sm font-semibold text-gray-700 mb-2">
                                    Email Address
                                </label>
                                <input
                                    id="reset-email"
                                    type="email"
                                    value={resetForm.data.email}
                                    onChange={(e) => resetForm.setData('email', e.target.value)}
                                    className={inputClasses}
                                    autoComplete="username"
                                    required
                                />
                                <FieldError message={resetForm.errors.email} />
                            </div>

                            <div>
                                <label htmlFor="code" className="block text-sm font-semibold text-gray-700 mb-2">
                                    6-Digit Code
                                </label>
                                <input
                                    id="code"
                                    type="text"
                                    inputMode="numeric"
                                    maxLength={6}
                                    value={resetForm.data.code}
                                    onChange={(e) => resetForm.setData('code', e.target.value.replace(/\D/g, ''))}
                                    className={`${inputClasses} tracking-[0.5em] text-center text-lg font-bold`}
                                    placeholder="••••••"
                                    autoFocus
                                    required
                                />
                                <FieldError message={resetForm.errors.code} />
                            </div>

                            <div>
                                <label htmlFor="new-password" className="block text-sm font-semibold text-gray-700 mb-2">
                                    New Password
                                </label>
                                <input
                                    id="new-password"
                                    type="password"
                                    value={resetForm.data.password}
                                    onChange={(e) => resetForm.setData('password', e.target.value)}
                                    className={inputClasses}
                                    autoComplete="new-password"
                                    placeholder="At least 12 characters"
                                    required
                                />
                                <FieldError message={resetForm.errors.password} />
                            </div>

                            <div>
                                <label htmlFor="confirm-password" className="block text-sm font-semibold text-gray-700 mb-2">
                                    Confirm New Password
                                </label>
                                <input
                                    id="confirm-password"
                                    type="password"
                                    value={resetForm.data.password_confirmation}
                                    onChange={(e) => resetForm.setData('password_confirmation', e.target.value)}
                                    className={inputClasses}
                                    autoComplete="new-password"
                                    required
                                />
                            </div>

                            <button
                                type="submit"
                                disabled={resetForm.processing}
                                className="btn-primary w-full rounded-xl bg-gradient-to-r from-orange-400 to-yellow-500 py-3 font-bold text-gray-900 shadow-lg transition hover:scale-105 hover:from-orange-500 hover:to-yellow-600 disabled:opacity-50"
                            >
                                Set New Password
                            </button>

                            <div className="flex items-center justify-between text-sm">
                                <button type="button" onClick={() => setStep(1)} className="text-gray-600 hover:text-gray-900 font-medium">
                                    Request a new code
                                </button>
                                <Link href={route('login')} className="text-gray-600 hover:text-gray-900 font-medium">
                                    Back to sign in
                                </Link>
                            </div>
                        </form>
                    )}

                    <p className="mt-8 text-center text-xs text-gray-500">
                        No Slack access? Ask an admin — they can reset your password from the Users page.
                    </p>
                </div>
            </div>
        </div>
    );
}
