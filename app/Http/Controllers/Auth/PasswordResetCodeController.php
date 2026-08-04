<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SlackBotService;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Password reset via 6-digit code delivered through Slack — built for teams
 * whose members don't reliably receive email. The code is DMed straight to
 * the employee's Slack (matched by their account email); if they aren't on
 * Slack with that email, it falls back to the admin-only resets channel.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THROTTLING: BOTH SIDES ARE KEYED ON THE ACCOUNT
 * ─────────────────────────────────────────────────────────────────────────────
 * Both limiters here used to be keyed `{email}|{ip}`, on top of a per-IP route
 * throttle — so neither had an account-wide budget at all. Rotating 40 source
 * addresses bought 200 guesses at a six-digit secret and then the account; and
 * 60 accepted code requests let an attacker spray a victim's Slack DMs.
 *
 *   • VERIFY side: delegated wholesale to NewPasswordController's shared
 *     account budget. Both endpoints unlock the same `password_reset_tokens`
 *     row, so they must draw on ONE counter — otherwise the budget is simply
 *     spent on whichever endpoint is not being watched. Read that class for why
 *     the key is the resolved user id and why the lockout escalates instead of
 *     hard-blocking.
 *
 *   • REQUEST side: a hard per-account cap on how many codes can be sent, since
 *     this is a send/amplification vector. A hard cap is safe here (unlike on the
 *     verify side) because the codes go to the VICTIM's Slack, not the
 *     attacker's: spending the budget hands the owner five working codes rather
 *     than denying them anything.
 *
 * The account key falls back to the address (never address+IP) when nothing
 * resolves, so an unknown address is throttled exactly like a real one and this
 * endpoint stays free of an account-existence oracle.
 */
class PasswordResetCodeController extends Controller
{
    private const CODE_TTL_MINUTES = 10;

    /** Codes that may be sent for one account per window. */
    public const MAX_CODES_PER_WINDOW = 5;

    public const CODE_REQUEST_WINDOW_SECONDS = 900;

    public function store(Request $request, SlackBotService $slack): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $submittedEmail = (string) $request->input('email');

        // This endpoint is anonymous, so no tenant is bound. Resolve the
        // account explicitly unscoped rather than relying on the global scope
        // happening to be a no-op here — and key the budget on what came back,
        // so no spelling of the address can buy a fresh allowance.
        $user = NewPasswordController::resolveAccount($submittedEmail);

        $sendKey = 'pwcode-send:'.NewPasswordController::accountScope($user, $submittedEmail);

        if (RateLimiter::tooManyAttempts($sendKey, self::MAX_CODES_PER_WINDOW)) {
            $seconds = max(1, RateLimiter::availableIn($sendKey));

            // Identical for a real and an unknown address: both are counted on
            // the same kind of key at the same rate, so this cannot be used to
            // ask "does this person use Staffvora?".
            throw new ThrottleRequestsException(
                'Too many reset codes requested. Try again in '.$seconds.' seconds.',
                null,
                ['Retry-After' => $seconds]
            );
        }

        RateLimiter::hit($sendKey, self::CODE_REQUEST_WINDOW_SECONDS);

        // Never reveal whether an account exists.
        $neutralMessage = 'If that account exists, a reset code is on its way via Slack.';

        if (! $user || ! $user->organization_id) {
            return back()->with('status', $neutralMessage);
        }

        // BIND THE OWNING TENANT FOR THE WHOLE DELIVERY.
        //
        // Without this, MonitoringSetting::current() resolved the
        // organization_id = NULL row (whose bot token is null) and
        // SlackBotService fell back to the PLATFORM token — so a customer's
        // password reset code was DMed/posted inside the OPERATOR's own Slack
        // workspace, readable by everyone in it. configured() must be inside
        // the binding too, since it consults the same token resolver.
        app(CurrentOrganization::class)->runFor($user->organization_id, function () use ($user, $slack) {
            if (! $slack->configured()) {
                return;
            }

            $code = (string) random_int(100000, 999999);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($code), 'created_at' => now()]
            );

            $delivered = $slack->dmByEmail(
                $user->email,
                "Your {$this->appName()} password reset code is *{$code}*. It expires in ".self::CODE_TTL_MINUTES.' minutes. If you didn\'t request this, ignore it.'
            );

            if (! $delivered && ($channel = config('services.slack_bot.resets_channel'))) {
                $slack->postToChannel(
                    $channel,
                    "Password reset code for *{$user->name}* ({$user->email}): *{$code}* — expires in ".self::CODE_TTL_MINUTES.' minutes.'
                );
            }
        });

        return back()->with('status', $neutralMessage);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $submittedEmail = (string) $request->input('email');

        $user = NewPasswordController::resolveAccount($submittedEmail);

        // ONE budget, shared with POST /reset-password. Checked before the
        // bcrypt comparison below, which is what bounds the CPU an attacker can
        // burn on this box as well as the number of guesses they get.
        NewPasswordController::guardResetAttempt($user, $submittedEmail);

        // Read the token row by the account's OWN stored address, exactly like
        // DatabaseTokenRepository does — never by the string that was submitted.
        // One more place where a collation could otherwise be the deciding vote.
        $record = $user
            ? DB::table('password_reset_tokens')->where('email', $user->email)->first()
            : null;

        $valid = $record
            && Carbon::parse($record->created_at)->gt(now()->subMinutes(self::CODE_TTL_MINUTES))
            && Hash::check($request->input('code'), $record->token);

        if (! $valid) {
            NewPasswordController::penaliseFailedReset($user, $submittedEmail);

            throw ValidationException::withMessages([
                'code' => 'That code is invalid or expired. Request a new one.',
            ]);
        }

        /** @var User $user */
        $user->forceFill([
            'password' => Hash::make($request->input('password')),
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        NewPasswordController::forgetResetAttempts($user, $submittedEmail);

        return redirect()->route('login')->with('status', 'Password updated — sign in with your new password.');
    }

    private function appName(): string
    {
        return config('app.name', 'Staffvora');
    }
}
