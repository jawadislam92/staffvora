<?php

namespace App\Listeners;

use App\Notifications\WorkspaceWelcomeNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;

/**
 * Fires the branded "workspace created" welcome email off the same
 * `Registered` event the framework already dispatches at the end of
 * self-serve signup (RegisteredUserController::store()) — the same event
 * SendEmailVerificationNotification listens on today, which is a permanent
 * no-op here because User does not implement MustVerifyEmail (see the doc
 * block on App\Models\User). Piggy-backing on it means the welcome email
 * costs zero changes to the signup/provisioning controllers themselves.
 *
 * try/catch, not ShouldQueue-and-hope: a broken mail transport must never
 * turn a successful signup into a 500. The notification itself also
 * implements ShouldQueue so it stops being on the request's critical path
 * for free the day QUEUE_CONNECTION moves off "sync".
 */
class SendWorkspaceWelcomeEmail
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof \App\Models\User) {
            return;
        }

        try {
            $organizationName = $user->organization?->name ?? 'your new workspace';

            $user->notify(new WorkspaceWelcomeNotification($organizationName));
        } catch (\Throwable $e) {
            Log::warning('Workspace welcome email failed to send.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
