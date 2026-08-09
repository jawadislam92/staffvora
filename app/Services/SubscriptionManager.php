<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * THE ONE PLACE A COMPANY'S SUBSCRIPTION CHANGES.
 *
 * The operator console could always suspend, resume, set a trial, record a
 * payment and override individual entitlements — but there was no way to move a
 * company from one plan to another, which made the pricing structure
 * unmanageable. This service is that missing control, and it is deliberately
 * ONE service so plan / cycle / status / trial can never drift into four
 * competing implementations.
 *
 * ─── IT IS NOT A SECOND ACCESS PATH ──────────────────────────────────────────
 * Nothing here decides access. It writes `subscriptions.plan_id` (and cycle,
 * status, trial dates) and then invalidates the derived caches.
 * `Organization::can()` — per-company override → active add-ons → plan →
 * catalogue default (deny) — remains the ONLY authority, so a per-company
 * override still beats whatever plan the operator moves the company onto. The
 * preview below is computed by that very same resolver
 * ({@see Organization::entitlementsUnderPlan()}), not by a parallel rule set.
 *
 * ─── SEAT SAFETY: REFUSE, THEN ALLOW ON AN EXPLICIT ACKNOWLEDGEMENT ──────────
 * Moving a company DOWN to a plan whose effective seat ceiling is below their
 * current active-user count is REFUSED by default with a validation error that
 * names both numbers. The operator can proceed by ticking the acknowledgement,
 * which is recorded in the audit entry.
 *
 * That is safe because the seat ceiling is enforced only where a seat is
 * CLAIMED ({@see User::withSeatGuard()} / {@see User::seatHeadroom()}): nobody
 * is signed out, no user row is deactivated, and the customer keeps every
 * person they already have. They simply cannot add or reactivate anyone until
 * they are back under the ceiling. Silently locking a customer out of their own
 * users would be unacceptable; silently letting an accidental downgrade through
 * is how support tickets are born — hence refuse-by-default.
 *
 * ─── AUDIT ───────────────────────────────────────────────────────────────────
 * Every distinct kind of change writes its OWN `platform_audit_logs` row
 * (who / what / when / before → after), so the History tab reads like a story
 * rather than one opaque blob.
 */
class SubscriptionManager
{
    /**
     * Audit action strings for the two changes the shared catalogue does not yet
     * name. `PlatformAuditLog::record()` takes free text and the audit UI falls
     * back to a humanised label, so these work today; promoting them to
     * constants on PlatformAuditLog (and to labels in
     * Platform\AuditController::LABELS) is a cosmetic follow-up.
     */
    public const ACTION_CYCLE_CHANGED = 'tenant.billing_cycle_changed';

    public const ACTION_STATUS_CHANGED = 'tenant.subscription_status_changed';

    /** Default trial length when a plan does not name one. */
    private const DEFAULT_TRIAL_DAYS = 14;

    public function __construct(private CurrentOrganization $current) {}

    // -----------------------------------------------------------------------
    // Preview — "what will actually change if I click apply?"
    // -----------------------------------------------------------------------

    /**
     * What moving `$organization` onto `$target` would do, resolved through the
     * ONE entitlement engine.
     *
     * Losing a capability the company is using is exactly the surprise that
     * generates support tickets, so a LOSS here is a genuine loss: a flag that
     * is on today and off afterwards. A capability an override or an add-on
     * grants does not appear as a loss, because the resolver keeps granting it —
     * that is the point of overrides winning over the plan.
     *
     * @param  int|null  $activeUsers  seat-consuming users; counted when omitted
     * @return array<string, mixed>
     */
    public function impactOf(Organization $organization, Plan $target, ?int $activeUsers = null): array
    {
        $registry = Entitlements::registry();
        $now = $organization->entitlementsWithSources();
        $then = $organization->entitlementsUnderPlan($target);

        $gains = [];
        $losses = [];
        $limits = [];

        foreach ($registry as $key => $entry) {
            $before = $now[$key]['value'] ?? null;
            $after = $then[$key]['value'] ?? null;

            if ($entry['kind'] === 'flag') {
                if (! $before && $after) {
                    $gains[] = ['key' => $key, 'label' => $entry['label']];
                } elseif ($before && ! $after) {
                    $losses[] = [
                        'key' => $key,
                        'label' => $entry['label'],
                        'help' => $entry['help'] ?? null,
                        // Which layer is granting it TODAY — always 'plan' here
                        // (an override/add-on grant survives the switch), kept so
                        // the UI can say so rather than imply it.
                        'source_now' => $now[$key]['source'] ?? 'plan',
                    ];
                }

                continue;
            }

            if ($before === $after) {
                continue;
            }

            $limits[] = [
                'key' => $key,
                'label' => $entry['label'],
                'unit' => $entry['unit'] ?? null,
                'kind' => $entry['kind'],
                'from' => $before,
                'to' => $after,
                'tightens' => $this->tightens($entry['kind'], $before, $after),
            ];
        }

        $seatLimitAfter = $this->limitFrom($then, 'max_users');
        $users = $activeUsers ?? User::seatUsageFor($organization->id);

        return [
            'plan_key' => $target->key,
            'plan_name' => $target->name,
            'archived' => $target->isArchived(),
            'visibility' => $target->visibility,
            'is_current' => $organization->activePlan()?->id === $target->id,
            'price_cents' => $target->price_cents,
            'price_cents_yearly' => $target->price_cents_yearly,
            'currency' => $target->currency ?: 'USD',
            'seat_limit_now' => $organization->seatLimit(),
            'seat_limit' => $seatLimitAfter,
            'retention_days_now' => $this->limitFrom($now, 'max_retention_days'),
            'retention_days' => $this->limitFrom($then, 'max_retention_days'),
            'active_users' => $users,
            'gains' => $gains,
            'losses' => $losses,
            'limits' => $limits,
            // Non-null = the move needs an explicit acknowledgement.
            'seat_overage' => ($seatLimitAfter !== null && $users > $seatLimitAfter)
                ? ['users' => $users, 'limit' => $seatLimitAfter, 'excess' => $users - $seatLimitAfter]
                : null,
        ];
    }

    /**
     * The impact of every plan the operator may assign, for the switcher's
     * before-you-apply panel. Bounded by construction — the plan catalogue is a
     * handful of operator-authored rows — and the seat count is read ONCE.
     *
     * @param  Collection<int, Plan>  $plans
     * @return array<string, array<string, mixed>> keyed by plan key
     */
    public function impactMap(Organization $organization, $plans): array
    {
        $users = User::seatUsageFor($organization->id);
        $out = [];

        foreach ($plans as $plan) {
            $out[$plan->key] = $this->impactOf($organization, $plan, $users);
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Apply
    // -----------------------------------------------------------------------

    /**
     * Apply a subscription change. Any subset of plan / billing cycle / status
     * may be present; each one that actually moves is audited separately.
     *
     * @param  array{plan?:string|null, billing_cycle?:string|null, status?:string|null, acknowledge_seat_overage?:bool}  $intent
     * @return array<int, string> human-readable notes for the flash message
     *
     * @throws ValidationException on an unacknowledged seat downgrade
     */
    public function apply(Organization $organization, array $intent, ?string $reason = null, bool $force = false): array
    {
        $subscription = $this->subscriptionFor($organization);

        if (! $subscription) {
            throw ValidationException::withMessages([
                'plan' => 'This company has no subscription row to change. Record a payment or re-provision it first.',
            ]);
        }

        $notes = [];

        if (! empty($intent['plan'])) {
            $note = $this->changePlan(
                $organization,
                $subscription,
                (string) $intent['plan'],
                $reason,
                $force || (bool) ($intent['acknowledge_seat_overage'] ?? false),
            );
            if ($note) {
                $notes[] = $note;
            }
        }

        if (! empty($intent['billing_cycle'])) {
            $note = $this->changeCycle($organization, $subscription, (string) $intent['billing_cycle'], $reason);
            if ($note) {
                $notes[] = $note;
            }
        }

        if (! empty($intent['status'])) {
            $note = $this->changeStatus($organization, $subscription, (string) $intent['status'], $reason);
            if ($note) {
                $notes[] = $note;
            }
        }

        $this->forgetCaches($organization);

        return $notes;
    }

    /**
     * Move the company onto another plan.
     *
     * Archived and hidden plans ARE assignable: a customer may legitimately sit
     * on a legacy plan, and refusing to put them back on it would make the
     * console unable to correct its own mistake.
     */
    private function changePlan(
        Organization $organization,
        Subscription $subscription,
        string $planKey,
        ?string $reason,
        bool $acknowledgeSeatOverage,
    ): ?string {
        $target = Plan::where('key', $planKey)->first();

        if (! $target) {
            throw ValidationException::withMessages(['plan' => 'That plan no longer exists.']);
        }

        $before = $organization->activePlan();

        if ($before && $before->id === $target->id) {
            return null; // Nothing moved; do not write a no-op audit row.
        }

        $impact = $this->impactOf($organization, $target);

        if ($impact['seat_overage'] !== null && ! $acknowledgeSeatOverage) {
            $o = $impact['seat_overage'];

            throw ValidationException::withMessages([
                'plan' => sprintf(
                    '%s has %d active user%s but %s allows %d. Nobody would be signed out — they simply could not add or reactivate anyone until they are back under %d — but confirm the downgrade explicitly before it is applied.',
                    $organization->name,
                    $o['users'],
                    $o['users'] === 1 ? '' : 's',
                    $target->name,
                    $o['limit'],
                    $o['limit'],
                ),
            ]);
        }

        $this->current->runFor($organization, fn () => $subscription->update(['plan_id' => $target->id]));

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_TENANT_PLAN_CHANGED,
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            before: [
                'plan' => $before?->key,
                'plan_name' => $before?->name,
                'seat_limit' => $impact['seat_limit_now'],
                'retention_days' => $impact['retention_days_now'],
            ],
            after: [
                'plan' => $target->key,
                'plan_name' => $target->name,
                'seat_limit' => $impact['seat_limit'],
                'retention_days' => $impact['retention_days'],
                'gained' => array_column($impact['gains'], 'key'),
                'lost' => array_column($impact['losses'], 'key'),
                // Only present when the operator knowingly went below the seat
                // count — the single most support-visible consequence.
                'seat_overage_acknowledged' => $impact['seat_overage'],
            ],
            reason: $reason,
        );

        return sprintf('Plan changed to %s.', $target->name);
    }

    private function changeCycle(Organization $organization, Subscription $subscription, string $cycle, ?string $reason): ?string
    {
        if (! in_array($cycle, Subscription::CYCLES, true)) {
            throw ValidationException::withMessages(['billing_cycle' => 'Unknown billing cycle.']);
        }

        $before = $subscription->cycle();

        if ($before === $cycle) {
            return null;
        }

        $this->current->runFor($organization, fn () => $subscription->update(['billing_cycle' => $cycle]));

        PlatformAuditLog::record(
            action: self::ACTION_CYCLE_CHANGED,
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            before: ['billing_cycle' => $before],
            after: ['billing_cycle' => $cycle],
            reason: $reason,
        );

        return sprintf('Billing cycle set to %s.', $cycle);
    }

    /**
     * Set the subscription's own state. `trialing` is deliberately NOT settable
     * here — a trial is a date as well as a state, so it belongs to
     * {@see setTrial()}, which is the single trial concept.
     */
    private function changeStatus(Organization $organization, Subscription $subscription, string $status, ?string $reason): ?string
    {
        if (! in_array($status, Subscription::OPERATOR_SETTABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Use the trial controls to put a company back on trial.',
            ]);
        }

        $before = ['status' => $subscription->status, 'organization_status' => $organization->status];

        if ($subscription->status === $status) {
            return null;
        }

        $this->current->runFor($organization, function () use ($subscription, $status) {
            $attributes = ['status' => $status];

            // Converting a trial to a paid state ends the trial for real, so the
            // account does not keep a live trial date behind a paid status.
            if ($status === Subscription::STATUS_ACTIVE && $subscription->onTrial()) {
                $attributes['trial_ends_at'] = now();
            }

            $subscription->update($attributes);
        });

        $this->syncOrganizationStatus($organization, $status);

        PlatformAuditLog::record(
            action: self::ACTION_STATUS_CHANGED,
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            before: $before,
            after: ['status' => $status, 'organization_status' => $organization->fresh()?->status],
            reason: $reason,
        );

        return sprintf('Subscription marked %s.', str_replace('_', ' ', $status));
    }

    // -----------------------------------------------------------------------
    // Trial — the SAME concept as the plan control, not a competing one
    // -----------------------------------------------------------------------

    /**
     * Start / extend / end a trial. This is the only writer of trial dates, and
     * it keeps the organization AND the subscription in step: a company on trial
     * is `trialing` on both rows, and ending a trial converts it to `active` on
     * both. Previously the org was moved and the subscription's own status was
     * left behind, so the console could show "Active" next to a live trial date.
     *
     * @param  'extend'|'end'|'set'  $action
     * @return array{ends_at: ?CarbonInterface, status: string}
     */
    public function setTrial(
        Organization $organization,
        string $action,
        ?int $days = null,
        ?string $date = null,
        ?string $reason = null,
    ): array {
        $subscription = $this->subscriptionFor($organization);

        $before = [
            'trial_ends_at' => $organization->trial_ends_at?->toIso8601String(),
            'status' => $organization->status,
            'subscription_status' => $subscription?->status,
        ];

        if ($action === 'end') {
            $end = now();
            $organization->update(['trial_ends_at' => $end]);
            $this->current->runFor($organization, fn () => $subscription?->update([
                'trial_ends_at' => $end,
                'status' => Subscription::STATUS_ACTIVE,
            ]));
            $this->syncOrganizationStatus($organization, Subscription::STATUS_ACTIVE);

            $this->recordTrialChange($organization, $before, 'end', $reason);
            $this->forgetCaches($organization);

            return ['ends_at' => $end, 'status' => Subscription::STATUS_ACTIVE];
        }

        // Extending an already-future trial adds to it; extending a lapsed one
        // (or one that never existed) starts from today, so "+14 days" always
        // means fourteen days of trial from now on.
        $base = $organization->trial_ends_at && $organization->trial_ends_at->isFuture()
            ? $organization->trial_ends_at->copy()
            : Carbon::now();

        $end = $action === 'extend'
            ? $base->addDays($days ?: $this->trialDaysFor($organization))
            : Carbon::parse($date)->endOfDay();

        $organization->update(['trial_ends_at' => $end]);
        $this->current->runFor($organization, fn () => $subscription?->update([
            'trial_ends_at' => $end,
            'status' => Subscription::STATUS_TRIALING,
        ]));
        $this->syncOrganizationStatus($organization, Subscription::STATUS_TRIALING);

        $this->recordTrialChange($organization, $before, $action, $reason);
        $this->forgetCaches($organization);

        return ['ends_at' => $end, 'status' => Subscription::STATUS_TRIALING];
    }

    /** The trial length the company's own plan names, else the house default. */
    private function trialDaysFor(Organization $organization): int
    {
        $days = $organization->activePlan()?->trial_days;

        return is_int($days) && $days > 0 ? $days : self::DEFAULT_TRIAL_DAYS;
    }

    private function recordTrialChange(Organization $organization, array $before, string $action, ?string $reason): void
    {
        $fresh = $organization->fresh();

        PlatformAuditLog::record(
            action: PlatformAuditLog::ACTION_TENANT_TRIAL_CHANGED,
            organizationId: $organization->id,
            targetType: 'organization',
            targetId: $organization->id,
            before: $before,
            after: [
                'trial_ends_at' => $fresh?->trial_ends_at?->toIso8601String(),
                'status' => $fresh?->status,
                'subscription_status' => $this->subscriptionFor($organization)?->status,
                'via' => $action,
            ],
            reason: $reason,
        );
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Keep the ORGANIZATION's status in step with the subscription's, for the
     * states the organization actually models.
     *
     * Two deliberate exceptions, so this stays a mirror rather than a second
     * state machine:
     *   • a SUSPENDED workspace is never un-suspended by a billing change —
     *     suspension is an operator decision that outranks billing;
     *   • `canceled` has no organization equivalent and is NOT mapped onto
     *     `past_due` (they mean different things to a customer). Access is
     *     already refused correctly by `Subscription::isUsable()`, which
     *     `OrganizationAccess` consults on every request.
     */
    private function syncOrganizationStatus(Organization $organization, string $subscriptionStatus): void
    {
        if ($organization->status === Organization::STATUS_SUSPENDED) {
            return;
        }

        $target = match ($subscriptionStatus) {
            Subscription::STATUS_TRIALING => Organization::STATUS_TRIAL,
            Subscription::STATUS_ACTIVE => Organization::STATUS_ACTIVE,
            Subscription::STATUS_PAST_DUE => Organization::STATUS_PAST_DUE,
            default => null,
        };

        if ($target !== null && $organization->status !== $target) {
            $organization->update(['status' => $target]);
        }
    }

    /** This company's subscription, read across the tenant scope. */
    private function subscriptionFor(Organization $organization): ?Subscription
    {
        return $organization->subscription;
    }

    /**
     * Drop everything derived from this company's access so the change is live
     * on the very NEXT request — the resolved shared `entitlements` prop, the
     * per-instance add-on memo, the branding/workspace/settings props, and the
     * catalogue stamp every cached map is validated against.
     *
     * A subscription write fires no Organization model event, so without this
     * an operator's plan switch would keep serving the old map for up to a
     * minute. That is precisely the "I changed their plan and nothing happened"
     * report.
     */
    private function forgetCaches(Organization $organization): void
    {
        // Also bumps the global catalogue epoch, which invalidates every cached
        // resolved map (they are stored with the stamp they were built from).
        $organization->forgetResolvedEntitlements();
        Entitlements::forgetForOrganization($organization->id);

        try {
            Cache::forget('branding.shared.'.$organization->id);
            Cache::forget('workspace.shared.'.$organization->id);
            Cache::forget('monitoring_settings.shared.'.$organization->id);
        } catch (\Throwable $e) {
            // A cache store must never take a billing write down with it.
        }

        // The relation is memoised on the instance; drop it so anything later in
        // THIS request (the Inertia response, the flash message) reads the new
        // plan rather than the one we just replaced.
        $organization->unsetRelation('subscription');
        $organization->refresh();
    }

    /** A numeric entitlement out of a resolved map, null = unlimited/absent. */
    private function limitFrom(array $resolved, string $key): ?int
    {
        $value = $resolved[$key]['value'] ?? null;

        return is_int($value) ? $value : null;
    }

    /** Is the move in the restrictive direction for this kind of limit? */
    private function tightens(string $kind, int|bool|null $before, int|bool|null $after): bool
    {
        // null = unlimited ceiling / no floor: the most permissive value there is.
        if ($after === null) {
            return false;
        }
        if ($before === null) {
            return true;
        }

        return $kind === 'min' ? (int) $after > (int) $before : (int) $after < (int) $before;
    }
}
