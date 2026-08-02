<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;

/**
 * Resolves whether a tenant user may use the product right now.
 *
 * Keeping this decision in one place prevents the web app and desktop API from
 * drifting into different billing/suspension behaviour.
 */
final class OrganizationAccess
{
    /**
     * @return array{code: string, message: string, status: int}|null
     */
    public static function denialFor(User $user): ?array
    {
        // Fail CLOSED. A user with no organization would otherwise run every
        // tenant query with no organization predicate (OrganizationScope is a
        // no-op when nothing is bound), which is unrestricted cross-customer
        // read/write. Such a row should not exist — see `php artisan tenancy:audit`.
        if (! $user->organization_id) {
            return [
                'code' => 'workspace_missing',
                'message' => 'This account is not attached to a workspace. Contact support for help.',
                'status' => 403,
            ];
        }

        $organization = Organization::query()->find($user->organization_id);

        if (! $organization) {
            return [
                'code' => 'workspace_missing',
                'message' => 'Your workspace is no longer available. Contact support for help.',
                'status' => 403,
            ];
        }

        if ($organization->status === Organization::STATUS_SUSPENDED) {
            return [
                'code' => 'workspace_suspended',
                'message' => 'This workspace has been suspended. Contact support to restore access.',
                'status' => 403,
            ];
        }

        if (! $organization->isActive()) {
            return self::subscriptionDenial();
        }

        $subscription = $organization->subscription;

        if (! $subscription || ! $subscription->isUsable()) {
            return self::subscriptionDenial();
        }

        return null;
    }

    /** @return array{code: string, message: string, status: int} */
    private static function subscriptionDenial(): array
    {
        return [
            'code' => 'subscription_required',
            'message' => 'Your trial or subscription has ended. Choose a plan to continue using Staffvora.',
            'status' => 402,
        ];
    }
}
