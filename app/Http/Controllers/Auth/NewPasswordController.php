<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /** Wrong-token attempts allowed per address before the flow locks out. */
    private const MAX_ATTEMPTS_PER_EMAIL = 5;

    private const LOCKOUT_SECONDS = 600;

    /**
     * Display the password reset view.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => ['required', 'not_regex:/[\r\n]/', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Per-address lockout, mirroring PasswordResetCodeController::reset.
        //
        // WHY (two separate reasons, do not drop either):
        //  1. This endpoint and the Slack-code flow share ONE
        //     `password_reset_tokens` row, and this one accepts any string as
        //     `token` — so without a limit the 6-digit code is brute-forceable
        //     here in a few hundred requests.
        //  2. Every attempt runs a bcrypt comparison (cost 12 in production,
        //     ~0.2-0.4s of CPU each). A handful of requests per second pins a
        //     core, and this box also serves the operator's other live sites.
        //
        // The route throttle in routes/auth.php caps a single IP; this caps the
        // targeted account no matter how many IPs the attacker rotates through.
        $email = strtolower(trim((string) $request->input('email')));
        $throttleKey = 'pwreset:'.sha1($email);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS_PER_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => [trans('passwords.throttled')],
            ]);
        }

        RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status == Password::PASSWORD_RESET) {
            // A genuine reset clears the counter so the real owner is never
            // locked out of their own account by an attacker's failed guesses.
            RateLimiter::clear($throttleKey);

            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
