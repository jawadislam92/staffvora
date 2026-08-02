<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * An append-only record of what an OPERATOR did: plan edits, feature grants and
 * revokes, plan switches, suspend/resume, trial changes, price changes, add-on
 * grants — and every impersonation.
 *
 * ⚠️ LANDLORD-SIDE, DELIBERATELY NOT TENANT-SCOPED.
 * This is NOT `TrackingAuditLog` (which is the customer's own, tenant-scoped
 * history). This table has no `BelongsToOrganization` trait and no
 * `OrganizationScope`: its `organization_id` is a plain indexed pointer at the
 * TARGET company, not an ownership claim. Nothing on the tenant side may ever
 * query this model — customers must not be able to read operator activity,
 * about themselves or anyone else.
 *
 * APPEND-ONLY IN PRACTICE: updating or deleting a row throws. An audit trail an
 * operator can quietly rewrite is not an audit trail. `actor_name` is
 * denormalised on write for the same reason — deleting the staff account must
 * not erase who did it.
 *
 * @property int|null $platform_user_id
 * @property string|null $actor_name
 * @property string $action
 * @property int|null $organization_id
 * @property string|null $target_type
 * @property int|null $target_id
 * @property array|null $before
 * @property array|null $after
 * @property string|null $reason
 * @property string|null $ip
 */
class PlatformAuditLog extends Model
{
    /**
     * Canonical action strings. Use these constants rather than free text so the
     * history filter stays reliable; any new action should be added here.
     */
    public const ACTION_PLAN_CREATED = 'plan.created';
    public const ACTION_PLAN_UPDATED = 'plan.updated';
    public const ACTION_PLAN_ARCHIVED = 'plan.archived';
    public const ACTION_PLAN_REORDERED = 'plan.reordered';
    public const ACTION_FEATURE_CREATED = 'feature.created';
    public const ACTION_FEATURE_UPDATED = 'feature.updated';
    public const ACTION_ADDON_CREATED = 'addon.created';
    public const ACTION_ADDON_UPDATED = 'addon.updated';
    public const ACTION_ADDON_GRANTED = 'addon.granted';
    public const ACTION_ADDON_REVOKED = 'addon.revoked';
    public const ACTION_TENANT_PLAN_CHANGED = 'tenant.plan_changed';
    public const ACTION_TENANT_OVERRIDES_CHANGED = 'tenant.overrides_changed';
    public const ACTION_TENANT_PRICE_CHANGED = 'tenant.price_changed';
    public const ACTION_TENANT_SUSPENDED = 'tenant.suspended';
    public const ACTION_TENANT_RESUMED = 'tenant.resumed';
    public const ACTION_TENANT_TRIAL_CHANGED = 'tenant.trial_changed';
    public const ACTION_TENANT_PAYMENT_RECORDED = 'tenant.payment_recorded';
    public const ACTION_TENANT_IMPERSONATED = 'tenant.impersonated';

    protected $fillable = [
        'platform_user_id', 'actor_name', 'action', 'organization_id',
        'target_type', 'target_id', 'before', 'after', 'reason', 'ip',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'platform_user_id' => 'integer',
        'organization_id' => 'integer',
        'target_id' => 'integer',
    ];

    /** Append-only: block UPDATE and DELETE at the model layer. */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('platform_audit_logs is append-only; audit rows cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('platform_audit_logs is append-only; audit rows cannot be deleted.');
        });
    }

    /**
     * Record an operator action.
     *
     * Call it with NAMED ARGUMENTS — only `$action` is required, everything else
     * is optional and defaults sensibly:
     *
     *   PlatformAuditLog::record(
     *       action:         PlatformAuditLog::ACTION_TENANT_OVERRIDES_CHANGED,
     *       organizationId: $org->id,
     *       targetType:     'organization',
     *       targetId:       $org->id,
     *       before:         ['entitlement_overrides' => $old],
     *       after:          ['entitlement_overrides' => $new],
     *       reason:         $request->input('reason'),
     *   );
     *
     * The ACTOR and the IP are captured automatically from the current
     * `platform` guard and request when not passed, so a caller can never
     * forget them. `actorName` is denormalised so the row survives the staff
     * account being deleted.
     *
     * @param  string  $action  one of the ACTION_* constants
     * @param  int|null  $organizationId  the TARGET company (plain pointer, not tenant scope)
     * @param  string|null  $targetType  'plan' | 'addon' | 'feature' | 'organization' | …
     * @param  int|null  $targetId  primary key of the target row
     * @param  array<string, mixed>|null  $before  the state before the change
     * @param  array<string, mixed>|null  $after  the state after the change
     * @param  string|null  $reason  free-text justification typed by the operator
     * @param  PlatformUser|int|null  $actor  defaults to the signed-in operator
     * @param  string|null  $actorName  defaults to the actor's name
     * @param  string|null  $ip  defaults to the current request IP
     */
    public static function record(
        string $action,
        ?int $organizationId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        PlatformUser|int|null $actor = null,
        ?string $actorName = null,
        ?string $ip = null,
    ): self {
        if ($actor === null) {
            $actor = Auth::guard('platform')->user();
        }

        $actorId = $actor instanceof PlatformUser ? $actor->id : $actor;

        if ($actorName === null && $actor instanceof PlatformUser) {
            $actorName = $actor->name;
        }
        if ($actorName === null && is_int($actorId)) {
            $actorName = PlatformUser::find($actorId)?->name;
        }

        if ($ip === null && app()->bound('request')) {
            $ip = request()->ip();
        }

        return static::create([
            'platform_user_id' => $actorId,
            'actor_name' => $actorName,
            'action' => $action,
            'organization_id' => $organizationId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $ip,
        ]);
    }

    /** The operator who acted — may be gone; `actor_name` is the durable copy. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'platform_user_id');
    }

    /**
     * The company the action was about. Explicitly unscoped-by-nature:
     * Organization is the scope root and carries no global scope itself.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // --- Scopes (the history tab reads through these; both are indexed) ----

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeAction(Builder $query, string|array $action): Builder
    {
        return is_array($action) ? $query->whereIn('action', $action) : $query->where('action', $action);
    }

    /** Newest first. Always paginate — this table only grows. */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
