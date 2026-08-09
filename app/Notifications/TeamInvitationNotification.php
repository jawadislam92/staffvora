<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The headline email: "‹inviter› added you to ‹workspace› on Staffvora",
 * with a secure set-your-password link.
 *
 * That link is built from a genuine password-reset broker token (see
 * OnboardingController::invite()) — the SAME primitive and the SAME
 * `password.reset` route the just-hardened reset flow uses, not a new token
 * surface. This class only renders the mail; it never mints or reads a
 * token itself.
 *
 * ShouldQueue for the same reason as the welcome email: harmless no-op today
 * (QUEUE_CONNECTION=sync), free upgrade if that ever changes. The invite
 * still creates the member account and shows the admin the temp-password
 * fallback UI unconditionally — this notification is additive, and the
 * caller catches failures so a broken mail transport never blocks an invite.
 */
class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $inviterName,
        private readonly string $organizationName,
        private readonly string $setPasswordUrl,
    ) {}

    /**
     * @param  mixed  $notifiable
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $brand = config('brand.name', config('app.name'));
        $expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject("{$this->inviterName} added you to {$this->organizationName} on {$brand}")
            ->greeting("You've been added to {$this->organizationName}")
            ->line("{$this->inviterName} added you as a teammate on **{$this->organizationName}**'s {$brand} workspace.")
            ->line("Set a password to sign in — it's the same secure link technology as a password reset, so it only works once and only for this account.")
            ->action('Set your password', $this->setPasswordUrl)
            ->line("This link expires in {$expireMinutes} minutes. If it expires, use \"Forgot your password?\" on the sign-in page with this email address to get a new one.")
            ->line("If you weren't expecting this, you can ignore this email — no account access is granted until a password is set.")
            ->salutation("— The {$brand} team");
    }
}
