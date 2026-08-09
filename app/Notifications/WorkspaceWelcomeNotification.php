<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once, right after a brand-new workspace is provisioned (self-serve
 * signup or `tenant:create`). Tells the owner what Staffvora is and gives
 * them exactly one next step, rather than dropping them cold into an empty
 * dashboard.
 *
 * ShouldQueue: today QUEUE_CONNECTION=sync so this still runs inline, but if
 * that ever moves to a real queue this email stops being on the signup
 * request's critical path for free. Either way, the caller wraps the
 * ->notify() call in a try/catch — see TenantProvisioner/RegisteredUserController
 * wiring — so a broken mail transport can never turn into a failed signup.
 */
class WorkspaceWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $organizationName) {}

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
        $dashboardUrl = route('dashboard');
        $onboardingUrl = route('onboarding.show');

        return (new MailMessage)
            ->subject("Welcome to {$brand} — {$this->organizationName} is ready")
            ->greeting("Welcome to {$brand}, {$notifiable->name}!")
            ->line("\"{$this->organizationName}\" is set up and ready to go. {$brand} is a calm, honest time tracker for remote teams — it measures the work, not the worker, so your reports stay focused on hours and progress rather than surveillance.")
            ->line('**Your next step:** finish the quick setup wizard to invite your team and get the desktop tracker installed.')
            ->action('Finish setup', $onboardingUrl)
            ->line("Prefer to explore first? [Open your dashboard]({$dashboardUrl}) any time — the setup wizard will be waiting when you're ready.")
            ->salutation("— The {$brand} team");
    }
}
