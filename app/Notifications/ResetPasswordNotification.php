<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Branded replacement for the framework's default "Reset Password
 * Notification" mail. Deliberately extends {@see ResetPassword} rather than
 * writing a fresh notification from scratch:
 *
 *  - it inherits the exact token/URL machinery the security audit already
 *    hardened (constructor takes the SAME broker-issued token; resetUrl()
 *    calls the SAME route('password.reset', ...) the app has always used) —
 *    only the rendered *mail message* changes;
 *  - it stays a synchronous (non-queued) notification on purpose. Whoever
 *    sends it — currently PasswordResetLinkController::store() — wraps the
 *    send in URL::forceRootUrl()/forceScheme() for the duration of the call
 *    to defeat Host/X-Forwarded-Prefix spoofing on the link a human is about
 *    to click. Queuing this (even onto the "sync" connection) would be one
 *    config change away from that pin no longer covering the URL-generation
 *    call, so — unlike the welcome/invite notifications below — this class
 *    must never gain `implements ShouldQueue`.
 */
class ResetPasswordNotification extends ResetPassword
{
    /**
     * {@inheritDoc}
     */
    protected function buildMailMessage($url)
    {
        $brand = config('brand.name', config('app.name'));
        $expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject("Reset your {$brand} password")
            ->greeting('Reset your password')
            ->line("We received a request to reset the password on your {$brand} account. If that was you, click below to choose a new one.")
            ->action('Reset password', $url)
            ->line("This link expires in {$expireMinutes} minutes, and can only be used once.")
            ->line("If you didn't ask to reset your password, you can safely ignore this email — your password will not change.")
            ->salutation("— The {$brand} team");
    }
}
