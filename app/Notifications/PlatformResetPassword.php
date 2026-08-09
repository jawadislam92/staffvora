<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password-reset email for an operator (platform guard) account. Distinct from
 * the framework default so the link targets the PLATFORM reset route
 * (platform.staffvora.com/reset-password/{token}), not the customer one.
 */
class PlatformResetPassword extends Notification
{
    use Queueable;

    public function __construct(public string $token)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('platform.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your Staffvora operator password')
            ->line('A password reset was requested for your Staffvora operator account.')
            ->action('Reset password', $url)
            ->line('This link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
