<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
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

/**
 * The token half of the password-reset flow, and the home of the ACCOUNT-WIDE
 * guessing budget that both reset endpoints share.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THE BUDGET LIVES HERE AND IS SHARED
 * ─────────────────────────────────────────────────────────────────────────────
 * `POST /reset-password` and `POST /reset-password/code` verify the SAME secret:
 * one row in `password_reset_tokens`, written by whichever flow issued it. A
 * budget that only one of them enforces is not a budget — the attacker simply
 * spends it on the other endpoint. The previous round gave this controller a
 * per-address lockout and left its sibling on `{email}|{ip}`, and the sibling
 * was walked straight through with 40 rotating IPs. So there is exactly one
 * counter, keyed on the ACCOUNT, and both controllers call into it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THE KEY IS THE USER ID, NOT THE SUBMITTED STRING
 * ─────────────────────────────────────────────────────────────────────────────
 * The old key was `sha1(strtolower(trim($email)))`, while the account itself is
 * found with `where email = ?` — i.e. by the DATABASE's idea of equality.
 * Production runs MySQL `utf8mb4_unicode_ci`, which folds case, accents and
 * width, so `user@x.test`, `usér@x.test`, `úser@x.test` and `ｕser@x.test` are
 * one account but were four separate buckets: five fresh guesses each, forever.
 * The test suite runs on SQLite (BINARY), where those variants resolve to
 * nothing, so the suite structurally could not show it.
 *
 * The fix is not a better PHP normaliser — any normaliser is a second opinion
 * that can disagree with the collation. The key is derived from the account the
 * database ACTUALLY RESOLVED. Guard and lookup now use one and the same
 * identity, so they cannot disagree, on any collation, ever. Only when nothing
 * resolves (no account to protect) do we fall back to a normalised address, and
 * that fallback is address-only — never address+IP — so an unknown address
 * behaves identically to a known one and the endpoint stays free of an
 * account-existence oracle.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY IT ESCALATES INSTEAD OF HARD-BLOCKING
 * ─────────────────────────────────────────────────────────────────────────────
 * The previous per-address lockout was a denial-of-service primitive: five bogus
 * posts from five IPs and the real owner could not use the genuine link from
 * their own inbox for ten minutes, renewable forever. So:
 *
 *   • Only FAILURES cost budget. A request carrying the correct token is never
 *     refused because of attempts that came before it and never leaves a mark.
 *   • Past the free budget the account is not blocked, it is SLOWED: a cooldown
 *     of 15s, then 30s, then 60s — and never more than 60s. The honest owner
 *     waits under a minute; the attacker gets roughly one guess a minute, which
 *     turns a 6-digit code (10-minute TTL) into ~10 guesses per code, i.e. odds
 *     of about 1 in 100,000 of ever landing one.
 *   • The cooldown is checked BEFORE the bcrypt comparison, so the CPU cost an
 *     attacker can impose on this box stays at one hash per minute per account
 *     no matter how many IPs they rotate through.
 */
class NewPasswordController extends Controller
{
    /** Failed attempts allowed against one ACCOUNT before the cooldown starts. */
    public const FREE_FAILURES = 5;

    /** How long the failure counter itself remembers. */
    public const FAILURE_WINDOW_SECONDS = 600;

    /** First cooldown once the free budget is spent. */
    public const COOLDOWN_MIN_SECONDS = 15;

    /**
     * Hard ceiling on the cooldown. This is the number that decides whether the
     * guard is a rate limit or a denial-of-service weapon — keep it small.
     */
    public const COOLDOWN_MAX_SECONDS = 60;

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

        $submittedEmail = (string) $request->input('email');

        // Resolve the account FIRST: everything below is keyed on what the
        // database says this address is, not on the string that was typed.
        $user = self::resolveAccount($submittedEmail);

        // Refuses before any bcrypt work — the CPU bound as well as the
        // brute-force bound. The route throttle in routes/auth.php caps a single
        // IP; this caps the targeted account however many IPs are rotated.
        self::guardResetAttempt($user, $submittedEmail);

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
            self::forgetResetAttempts($user, $submittedEmail);

            return redirect()->route('login')->with('status', __($status));
        }

        self::penaliseFailedReset($user, $submittedEmail);

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }

    // ─── The shared account budget ────────────────────────────────────────────

    /**
     * Find the account an anonymous caller is talking about.
     *
     * Explicitly unscoped: these endpoints are anonymous, so no tenant is bound,
     * and we must not depend on the global organization scope happening to be a
     * no-op here. This is the SAME comparison the reset itself performs, which is
     * the whole point — see the class docblock.
     */
    public static function resolveAccount(string $submittedEmail): ?User
    {
        $email = trim($submittedEmail);

        if ($email === '') {
            return null;
        }

        return app(CurrentOrganization::class)->runUnscoped(
            fn () => User::where('email', $email)->first()
        );
    }

    /**
     * The identity every reset limiter is keyed on.
     *
     * `acct:<id>` when the database resolved an account — collation-proof by
     * construction. `addr:<hash>` only when nothing resolved, so that an unknown
     * address is throttled exactly like a known one and the responses stay
     * indistinguishable.
     */
    public static function accountScope(?User $user, string $submittedEmail): string
    {
        if ($user !== null) {
            return 'acct:'.$user->getKey();
        }

        return 'addr:'.hash('sha256', mb_strtolower(trim($submittedEmail)));
    }

    /**
     * Refuse the attempt when the account is inside a cooldown.
     *
     * @throws ThrottleRequestsException
     */
    public static function guardResetAttempt(?User $user, string $submittedEmail): void
    {
        $cooldownKey = self::cooldownKey($user, $submittedEmail);

        if (! RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            return;
        }

        $seconds = max(1, RateLimiter::availableIn($cooldownKey));

        throw new ThrottleRequestsException(
            'Too many password reset attempts for this account. Try again in '.$seconds.' seconds.',
            null,
            ['Retry-After' => $seconds]
        );
    }

    /**
     * Record one failed attempt and, past the free budget, arm/extend the
     * cooldown. Capped at COOLDOWN_MAX_SECONDS so this can never be used to keep
     * a real owner out of their own account.
     */
    public static function penaliseFailedReset(?User $user, string $submittedEmail): void
    {
        $failureKey = self::failureKey($user, $submittedEmail);

        RateLimiter::hit($failureKey, self::FAILURE_WINDOW_SECONDS);

        $failures = RateLimiter::attempts($failureKey);

        if ($failures <= self::FREE_FAILURES) {
            return;
        }

        $steps = min($failures - self::FREE_FAILURES - 1, 8);
        $seconds = (int) min(
            self::COOLDOWN_MAX_SECONDS,
            self::COOLDOWN_MIN_SECONDS * (2 ** $steps)
        );

        $cooldownKey = self::cooldownKey($user, $submittedEmail);

        // hit() does not extend an existing timer, so clear before re-arming.
        RateLimiter::clear($cooldownKey);
        RateLimiter::hit($cooldownKey, $seconds);
    }

    /**
     * A genuine reset wipes the slate, so an attacker's failed guesses can never
     * follow the real owner into their next reset.
     */
    public static function forgetResetAttempts(?User $user, string $submittedEmail): void
    {
        RateLimiter::clear(self::failureKey($user, $submittedEmail));
        RateLimiter::clear(self::cooldownKey($user, $submittedEmail));
    }

    private static function failureKey(?User $user, string $submittedEmail): string
    {
        return 'pwreset-fail:'.self::accountScope($user, $submittedEmail);
    }

    private static function cooldownKey(?User $user, string $submittedEmail): string
    {
        return 'pwreset-cool:'.self::accountScope($user, $submittedEmail);
    }
}
