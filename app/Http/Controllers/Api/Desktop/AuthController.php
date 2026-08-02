<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Models\User;
use App\Support\OrganizationAccess;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends DesktopController
{
    /**
     * Per-account + per-IP attempt budget, mirroring the web login's 5-attempt
     * lockout (App\Http\Requests\Auth\LoginRequest).
     */
    private const MAX_ATTEMPTS_PER_EMAIL_AND_IP = 5;

    /** Sustained budget for one account across ALL IPs, in one hour. */
    private const MAX_ATTEMPTS_PER_EMAIL_HOURLY = 15;

    /** Budget for one IP across all accounts, per minute (password spraying). */
    private const MAX_ATTEMPTS_PER_IP = 20;

    /**
     * How long a desktop token stays valid before the app must log in again.
     *
     * These tokens used to be immortal: `config('sanctum.expiration')` is null
     * and nothing passed a per-token expiry, so one token minted on a laptop
     * that is later lost, resold or infected authenticated forever — logging
     * hours, clocking in and streaming that person's screen captures with no
     * re-authentication ever required.
     *
     * The expiry is set HERE rather than in config/sanctum.php on purpose: the
     * config key overrides expires_at for EVERY token in the app, so it would
     * silently govern any future non-desktop token (a mobile app, a webhook
     * key) that wants a different lifetime. Per-token expires_at is honoured by
     * Sanctum's guard independently of that config value.
     *
     * 30 days is long enough that a tracker on a working machine effectively
     * never prompts, and short enough that a stolen credential dies on its own.
     * The desktop client already handles this: it wipes the token and drops to
     * the login screen on any 401, so expiry surfaces as a normal re-login.
     */
    public const TOKEN_LIFETIME_DAYS = 30;

    /**
     * A real bcrypt hash of a value nobody knows, compared against when the
     * email does not exist so the failure path costs the same as a real one.
     * Without it, response time is an account-enumeration oracle.
     */
    private const DUMMY_HASH = '$2y$12$RYCJjEYjKZXR9v47xabhNeakQR8Vtj65HT32iB9XJJXqMkxdAZ4T6';

    /**
     * Issue a long-lived API token for the desktop tracker app.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        // Brute force: without this the desktop endpoint is a softer surface
        // than the web form for the exact same credentials — the shared 60/min
        // API bucket allowed ~86k guesses a day from a single IP, and rotating
        // IPs made it unlimited. The email-only bucket is the one that stops
        // distributed credential stuffing.
        $this->ensureIsNotRateLimited($request, $data['email']);

        $user = User::where('email', $data['email'])->first();

        // Always run a hash comparison, even when the account does not exist,
        // so response timing does not reveal which emails are registered.
        $passwordMatches = Hash::check(
            $data['password'],
            $user?->password ?: self::DUMMY_HASH,
        );

        if (! $user || ! $passwordMatches) {
            $this->hit($request, $data['email']);
            $this->logFailure($request, $data['email'], 'invalid_credentials');

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // A deactivated employee must not be able to re-mint a token with the
        // password they still know — otherwise revoking their tokens on
        // deactivation is theatre. Same rule the web login enforces.
        if (! $user->isActive()) {
            $this->hit($request, $data['email']);
            $this->logFailure($request, $data['email'], 'account_deactivated');

            return response()->json([
                'message' => 'Your account has been deactivated. Please contact your administrator.',
                'code' => 'account_deactivated',
            ], 403);
        }

        if ($denial = OrganizationAccess::denialFor($user)) {
            return response()->json([
                'message' => $denial['message'],
                'code' => $denial['code'],
            ], $denial['status']);
        }

        $this->clearLimiters($request, $data['email']);

        // Single-device accounts (the default) drop EVERY existing token on a
        // new login, not just the one matching this device name. device_name is
        // attacker-chosen and unvalidated, so matching on it alone let one
        // account accumulate unlimited live tokens across invented device names
        // — which is exactly what allow_multiple_devices=false is meant to
        // prevent. Multi-device accounts keep the original per-device rotation.
        $stale = $user->tokens();

        if ($user->allow_multiple_devices) {
            $stale->where('name', $data['device_name']);
        }

        $stale->delete();

        $token = $user->createToken(
            $data['device_name'],
            ['desktop-tracker'],
            now()->addDays(self::TOKEN_LIFETIME_DAYS),
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            // Advertised so the client can renew before it dies rather than
            // discovering the expiry as a mid-shift 401.
            'expires_at' => now()->addDays(self::TOKEN_LIFETIME_DAYS)->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'ok']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]);
    }

    /**
     * Three buckets, because any single one is defeatable on its own:
     * email+IP (the web login's rule), email-only (survives IP rotation, which
     * is free), and IP-only (password spraying across many accounts).
     *
     * @return array<string, int> [limiter key => decay seconds]
     */
    private function limiters(Request $request, string $email): array
    {
        $email = Str::transliterate(Str::lower($email));
        $ip = (string) $request->ip();

        return [
            'desktop-login|'.$email.'|'.$ip => 60,
            'desktop-login|'.$email => 3600,
            'desktop-login|ip|'.$ip => 60,
        ];
    }

    /** @return array<string, int> [limiter key => max attempts] */
    private function limiterCaps(Request $request, string $email): array
    {
        $keys = array_keys($this->limiters($request, $email));

        return array_combine($keys, [
            self::MAX_ATTEMPTS_PER_EMAIL_AND_IP,
            self::MAX_ATTEMPTS_PER_EMAIL_HOURLY,
            self::MAX_ATTEMPTS_PER_IP,
        ]);
    }

    private function ensureIsNotRateLimited(Request $request, string $email): void
    {
        foreach ($this->limiterCaps($request, $email) as $key => $max) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            event(new Lockout($request));
            $this->logFailure($request, $email, 'rate_limited');

            throw ValidationException::withMessages([
                'email' => [trans('auth.throttle', [
                    'seconds' => $seconds = RateLimiter::availableIn($key),
                    'minutes' => ceil($seconds / 60),
                ])],
            ])->status(429);
        }
    }

    private function hit(Request $request, string $email): void
    {
        foreach ($this->limiters($request, $email) as $key => $decay) {
            RateLimiter::hit($key, $decay);
        }
    }

    private function clearLimiters(Request $request, string $email): void
    {
        foreach (array_keys($this->limiters($request, $email)) as $key) {
            RateLimiter::clear($key);
        }
    }

    /**
     * A sustained credential-stuffing run against this endpoint used to leave
     * no trace anywhere. It does now.
     */
    private function logFailure(Request $request, string $email, string $reason): void
    {
        Log::warning('Desktop login failed.', [
            'email' => $email,
            'ip' => $request->ip(),
            'reason' => $reason,
            'device_name' => $request->input('device_name'),
        ]);
    }
}
