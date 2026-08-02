<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * THE OPERATOR AUDIT TRAIL — "who changed this company's access, and when".
 *
 * ROLE GATE: `platform.role:admin`. Support may open a tenant page but not the
 * record of what staff have done to it; the trail is also how an operator's own
 * behaviour is reviewed, so it is not a support-level read.
 *
 * ⚠️ LANDLORD-SIDE ONLY. `platform_audit_logs` has no `BelongsToOrganization`
 * trait and no `OrganizationScope`: its `organization_id` is a pointer at the
 * TARGET company, not an ownership claim. Nothing on the customer side may ever
 * read it — these routes live only on the platform host behind `auth:platform`.
 *
 * APPEND-ONLY: the model throws on update and delete. There is deliberately no
 * write endpoint here — rows are only ever created as a side effect of the
 * action they describe.
 *
 * SCALE: this table only grows, so every read is paginated and bounded, ordered
 * on the `(organization_id, created_at)` / `(action, created_at)` /
 * `(platform_user_id, created_at)` indexes the migration created.
 */
class AuditController extends Controller
{
    /** Rows per page. Kept small — before/after JSON payloads are not tiny. */
    public const PER_PAGE = 25;

    /** The cross-tenant activity log with filters. */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'organization_id' => ['nullable', 'integer'],
            'platform_user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = PlatformAuditLog::query()
            ->with(['organization:id,name,slug'])
            ->latestFirst();

        if (! empty($filters['action'])) {
            $query->action($filters['action']);
        }
        if (! empty($filters['organization_id'])) {
            $query->forOrganization((int) $filters['organization_id']);
        }
        if (! empty($filters['platform_user_id'])) {
            $query->where('platform_user_id', (int) $filters['platform_user_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $entries = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Platform/Audit', [
            'entries' => [
                'data' => collect($entries->items())->map(fn (PlatformAuditLog $log) => self::row($log, true))->all(),
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
            'filters' => $filters,
            'actions' => self::actionCatalogue(),
            // Small, bounded pickers — never the whole tenant table.
            'operators' => PlatformUser::orderBy('name')->limit(200)->get(['id', 'name', 'role']),
            'organizations' => Organization::orderBy('name')->limit(500)->get(['id', 'name']),
        ]);
    }

    /**
     * One audit row, flattened for the UI.
     *
     * @param  bool  $withOrganization  include the target company (the global log
     *                                  needs it; the tenant History tab does not)
     */
    public static function row(PlatformAuditLog $log, bool $withOrganization = false): array
    {
        $row = [
            'id' => $log->id,
            'action' => $log->action,
            'label' => self::label($log->action),
            'actor' => $log->actor_name ?: 'System',
            'actor_id' => $log->platform_user_id,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'before' => $log->before,
            'after' => $log->after,
            'reason' => $log->reason,
            'ip' => $log->ip,
            'at' => $log->created_at?->toIso8601String(),
        ];

        if ($withOrganization) {
            $row['organization'] = $log->organization
                ? ['id' => $log->organization->id, 'name' => $log->organization->name]
                : null;
        }

        return $row;
    }

    /**
     * Every action string the console writes, with a human label — drives the
     * filter dropdown and the row titles. Keeping it in one place means a new
     * action never renders as a raw slug.
     *
     * @return array<int, array{value:string, label:string}>
     */
    public static function actionCatalogue(): array
    {
        return collect(self::LABELS)
            ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    private const LABELS = [
        PlatformAuditLog::ACTION_PLAN_CREATED => 'Plan created',
        PlatformAuditLog::ACTION_PLAN_UPDATED => 'Plan updated',
        PlatformAuditLog::ACTION_PLAN_ARCHIVED => 'Plan archived',
        PlatformAuditLog::ACTION_PLAN_REORDERED => 'Plans reordered',
        PlatformAuditLog::ACTION_FEATURE_CREATED => 'Feature added',
        PlatformAuditLog::ACTION_FEATURE_UPDATED => 'Feature updated',
        PlatformAuditLog::ACTION_ADDON_CREATED => 'Add-on created',
        PlatformAuditLog::ACTION_ADDON_UPDATED => 'Add-on updated',
        PlatformAuditLog::ACTION_ADDON_GRANTED => 'Add-on granted',
        PlatformAuditLog::ACTION_ADDON_REVOKED => 'Add-on revoked',
        PlatformAuditLog::ACTION_TENANT_PLAN_CHANGED => 'Plan switched',
        PlatformAuditLog::ACTION_TENANT_OVERRIDES_CHANGED => 'Access overrides changed',
        PlatformAuditLog::ACTION_TENANT_PRICE_CHANGED => 'Price changed',
        PlatformAuditLog::ACTION_TENANT_SUSPENDED => 'Suspended',
        PlatformAuditLog::ACTION_TENANT_RESUMED => 'Resumed',
        PlatformAuditLog::ACTION_TENANT_TRIAL_CHANGED => 'Trial changed',
        PlatformAuditLog::ACTION_TENANT_PAYMENT_RECORDED => 'Payment recorded',
        PlatformAuditLog::ACTION_TENANT_IMPERSONATED => 'Signed in as customer',
    ];

    private static function label(string $action): string
    {
        return self::LABELS[$action] ?? ucfirst(str_replace(['.', '_'], ' ', $action));
    }
}
