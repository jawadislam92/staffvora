<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SlackBotService;
use Carbon\Carbon;
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
 */
class PasswordResetCodeController extends Controller
{
    private const CODE_TTL_MINUTES = 10;

    public function store(Request $request, SlackBotService $slack): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $email = strtolower(trim($request->input('email')));
        $throttleKey = 'pwcode:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many code requests. Try again in a few minutes.',
            ]);
        }
        RateLimiter::hit($throttleKey, 600);

        $user = User::where('email', $email)->first();

        // Never reveal whether an account exists.
        $neutralMessage = 'If that account exists, a reset code is on its way via Slack.';

        if (! $user || ! $slack->configured()) {
            return back()->with('status', $neutralMessage);
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

        return back()->with('status', $neutralMessage);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $email = strtolower(trim($request->input('email')));
        $throttleKey = 'pwcode-verify:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Request a new code in a few minutes.',
            ]);
        }
        RateLimiter::hit($throttleKey, 600);

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        $valid = $record
            && Carbon::parse($record->created_at)->gt(now()->subMinutes(self::CODE_TTL_MINUTES))
            && Hash::check($request->input('code'), $record->token);

        $user = User::where('email', $email)->first();

        if (! $valid || ! $user) {
            throw ValidationException::withMessages([
                'code' => 'That code is invalid or expired. Request a new one.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($request->input('password')),
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('password_reset_tokens')->where('email', $email)->delete();
        RateLimiter::clear($throttleKey);

        return redirect()->route('login')->with('status', 'Password updated — sign in with your new password.');
    }

    private function appName(): string
    {
        return config('app.name', 'TimeTracker');
    }
}
