<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Notifications\PlatformResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Login/logout + password recovery for the operator portal — the `platform`
 * guard, separate from the customer `web` guard. Invite-only: there is
 * intentionally no register action here.
 */
class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::guard('platform')->check()) {
            return redirect()->route('platform.dashboard');
        }

        return Inertia::render('PlatformAuth/Login');
    }

    public function login(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::guard('platform')->attempt($data, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $request->session()->regenerate();
        Auth::guard('platform')->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('platform.dashboard'));
    }

    public function logout(Request $request): \Illuminate\Http\RedirectResponse
    {
        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }

    // -----------------------------------------------------------------------
    // Password recovery (platform guard)
    //
    // A locked-out operator otherwise needs production SSH (`php artisan
    // platform:owner`). This reuses the framework password broker, pointed at
    // the platform_users provider + its OWN token table, so a customer reset and
    // an operator reset can never collide. The request endpoint is throttled at
    // the route and always answers neutrally — it never reveals whether an
    // operator account exists for a given email.
    // -----------------------------------------------------------------------

    public function showForgotPassword()
    {
        return Inertia::render('PlatformAuth/ForgotPassword');
    }

    public function sendResetLink(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['email' => 'required|email']);

        // Fire the broker but IGNORE its status: INVALID_USER vs RESET_LINK_SENT
        // would leak account existence. Delivery uses a platform-scoped
        // notification so the link points at the operator reset route.
        $this->broker()->sendResetLink(
            $request->only('email'),
            fn ($user, $token) => $user->notify(new PlatformResetPassword($token)),
        );

        return back()->with('success', 'If that email matches an operator account, a password reset link is on its way.');
    }

    public function showResetPassword(Request $request, string $token)
    {
        return Inertia::render('PlatformAuth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:12|confirmed',
        ]);

        $status = $this->broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            },
        );

        if ($status === PasswordBrokerContract::PASSWORD_RESET) {
            return redirect()->route('platform.login')->with('success', 'Your password has been reset. You can sign in now.');
        }

        // INVALID_TOKEN / INVALID_USER — a neutral, non-enumerating error on the
        // token itself. (A valid operator with a good token always succeeds.)
        throw ValidationException::withMessages([
            'email' => ['This password reset link is invalid or has expired.'],
        ]);
    }

    /**
     * The platform password broker, configured at runtime (kept out of
     * config/auth.php so the operator flow is self-contained). Points at the
     * platform_users provider and a dedicated token table.
     */
    private function broker(): PasswordBrokerContract
    {
        config([
            'auth.passwords.platform' => [
                'provider' => 'platform_users',
                'table' => 'platform_password_reset_tokens',
                'expire' => 60,
                'throttle' => 60,
            ],
        ]);

        return Password::broker('platform');
    }
}
