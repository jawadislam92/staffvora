<?php

namespace App\Http\Controllers;

use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\UserMonitoringSetting;
use App\Models\WorkCategory;
use App\Models\WorkHour;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Hard ceiling on how many work categories one workspace may hold (active +
     * archived). A category list is a short vocabulary, not a data table — the
     * cap keeps every read on this screen bounded and stops a scripted client
     * from growing an unbounded per-tenant table.
     */
    private const MAX_WORK_CATEGORIES = 200;

    private const CATEGORIES = [
        'screenshots',
        'activity',
        'app_url',
        'weekly_limit',
        'auto_pause',
        'offline_time',
        'notify_screenshot',
        'week_starts_on',
        'currency',
        'desktop_app',
        'display',
    ];

    public function index(): Response
    {
        $team = MonitoringSetting::current();

        // Everyone is listed, including Super Admins, so the owner sees the full
        // roster. Super Admins are exempt from monitoring overrides (the tracker
        // doesn't apply them), so their rows render read-only/"Exempt" and
        // updateUser() refuses to write an override for them.
        $users = User::orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        $overrides = UserMonitoringSetting::whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $perUser = $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'overrides' => $this->serializeOverride($overrides->get($u->id)),
        ]);

        return Inertia::render('Settings/Index', [
            'team' => $team->teamPayload(),
            'users' => $perUser,
            'payPeriod' => $this->payPeriodPayload(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAY PERIODS — the cadence the workspace runs payroll on, and therefore
    // the window a timesheet covers. {@see App\Models\PayPeriod}
    //
    // Periods are DERIVED from (cadence, anchor), never stored, so changing
    // this setting reshapes future periods immediately and costs nothing. It
    // does NOT reshape history: an approved timesheet snapshots its own
    // cadence + start + end, so a workspace moving from weekly to monthly keeps
    // every already-approved period exactly as it was signed off.
    //
    // It sits on its own endpoint (not in updateTeam) because it is a payroll
    // policy, not a tracker toggle — and because MonitoringSetting's $fillable
    // is owned elsewhere, the two columns are assigned directly rather than
    // mass-assigned.
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function payPeriodPayload(): array
    {
        $current = PayPeriod::current();

        return [
            'cadence' => PayPeriod::cadence(),
            'anchor_date' => PayPeriod::anchor()?->toDateString(),
            'options' => array_map(
                fn (string $key) => ['key' => $key, 'label' => PayPeriod::CADENCE_LABELS[$key]],
                PayPeriod::CADENCES
            ),
            // A live preview of what the choice actually produces, so an admin
            // sees the real dates before saving rather than guessing.
            'preview' => array_map(
                fn (PayPeriod $period) => $period->toArray(),
                array_reverse(PayPeriod::recent(3))
            ),
            'current' => $current->toArray(),
        ];
    }

    /**
     * Save the workspace's pay-period cadence + anchor.
     *
     * The anchor only means anything for the rolling cadences: semi-monthly and
     * monthly are pinned to the calendar, so an anchor is cleared rather than
     * silently stored where it would never be read.
     */
    public function updatePayPeriod(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'pay_period_cadence' => ['required', Rule::in(PayPeriod::CADENCES)],
            'pay_period_anchor_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $rolling = in_array($data['pay_period_cadence'], [PayPeriod::WEEKLY, PayPeriod::BIWEEKLY], true);

        $team = MonitoringSetting::current();
        // Direct assignment, not update(): these columns are deliberately not in
        // MonitoringSetting::$fillable (that model belongs to the settings
        // surface), and mass assignment would silently drop them.
        $team->pay_period_cadence = $data['pay_period_cadence'];
        $team->pay_period_anchor_date = $rolling ? ($data['pay_period_anchor_date'] ?: null) : null;
        $team->save();

        MonitoringSetting::forgetSharedCache();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'payPeriod' => $this->payPeriodPayload()]);
        }

        return back()->with('success', 'Pay period cadence updated.');
    }

    public function updateTeam(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'screenshots_per_hour' => ['required', 'integer', 'min:0', 'max:60'],
            'blur_screenshots' => ['required', 'boolean'],
            'capture_enabled' => ['required', 'boolean'],
            'activity_tracking_enabled' => ['required', 'boolean'],
            'app_url_tracking_enabled' => ['required', 'boolean'],
            'weekly_time_limit_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'auto_pause_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'allow_offline_time' => ['required', 'boolean'],
            'manual_time_overlap_policy' => ['sometimes', Rule::in(['warn', 'block', 'allow'])],
            'notify_on_screenshot' => ['required', 'boolean'],
            'week_starts_on' => ['required', Rule::in(['monday', 'sunday'])],
            'currency_symbol' => ['required', 'string', 'max:8'],
            'desktop_auto_start' => ['required', 'boolean'],
            'desktop_force_quit_on_idle' => ['required', 'boolean'],
            'display_timezone' => ['required', 'timezone'],
            'time_format' => ['required', Rule::in(['12', '24'])],
            'slack_clockin_enabled' => ['required', 'boolean'],
            'slack_clockout_enabled' => ['required', 'boolean'],
            'slack_attendance_channel' => ['nullable', 'string', 'max:100'],
        ]);

        // Default-deny gating: a tenant can never save a setting beyond what its
        // plan (or a platform per-tenant override) grants. Enforced here on the
        // server, not just hidden in the UI, so the system can't be abused.
        if ($org = $request->user()->organization) {
            $this->enforceEntitlements($org, $data);
        }

        $team = MonitoringSetting::current();

        // Keep the desktop's min/max seconds in sync with screenshots_per_hour
        // so the existing tracker code keeps working unchanged.
        $perHour = (int) $data['screenshots_per_hour'];
        if ($perHour > 0) {
            $avg = (int) round(3600 / $perHour);
            $data['screenshot_interval_min_seconds'] = max(30, (int) round($avg * 0.5));
            $data['screenshot_interval_max_seconds'] = max(60, (int) round($avg * 1.5));
            $data['capture_enabled'] = $data['capture_enabled'] && true;
        } else {
            $data['capture_enabled'] = false;
        }

        $team->update($data);
        MonitoringSetting::forgetSharedCache();

        // Toggles save in the background (axios) — a tiny JSON ack keeps the
        // page from re-rendering all settings props on every flip.
        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Team settings updated.');
    }

    /**
     * Save the per-org attendance policy (Phase 5F): when tracking started,
     * which weekdays are off, holiday dates, and whether a missing working day
     * is auto-marked Absent. Keeps a fresh workspace from branding everyone
     * Absent for days it wasn't even tracking.
     */
    public function updateAttendance(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'attendance_tracking_start_date' => ['nullable', 'date'],
            'attendance_week_off_days' => ['nullable', 'array'],
            'attendance_week_off_days.*' => ['integer', 'between:0,6'],
            'attendance_holidays' => ['nullable', 'array'],
            'attendance_holidays.*' => ['date_format:Y-m-d'],
            'attendance_auto_absent' => ['required', 'boolean'],
        ]);

        $team = MonitoringSetting::current();
        $team->update([
            'attendance_tracking_start_date' => $data['attendance_tracking_start_date'] ?: null,
            'attendance_week_off_days' => array_values(array_unique(array_map('intval', $data['attendance_week_off_days'] ?? []))),
            'attendance_holidays' => array_values(array_unique($data['attendance_holidays'] ?? [])),
            'attendance_auto_absent' => $data['attendance_auto_absent'],
        ]);
        MonitoringSetting::forgetSharedCache();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Attendance policy updated.');
    }

    /**
     * Connect or disconnect this organization's own Slack bot token. The token
     * is write-only (never returned to the client); the UI shows only connected
     * status. Sending `disconnect` clears it; a blank token keeps the current one.
     */
    public function updateSlack(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'slack_bot_token' => ['nullable', 'string', 'max:255'],
            'disconnect' => ['nullable', 'boolean'],
        ]);

        // Connecting your own Slack bot is an integrations feature — gate it.
        if (filled($data['slack_bot_token'] ?? null) && empty($data['disconnect'])) {
            $org = $request->user()->organization;
            if ($org && ! $org->can('integrations')) {
                throw ValidationException::withMessages([
                    'slack_bot_token' => 'Connecting your own Slack bot is not included in your plan.',
                ]);
            }
        }

        $team = MonitoringSetting::current();

        if (! empty($data['disconnect'])) {
            $team->slack_bot_token = null;
        } elseif (filled($data['slack_bot_token'] ?? null)) {
            $team->slack_bot_token = trim($data['slack_bot_token']);
        }

        $team->save();
        MonitoringSetting::forgetSharedCache();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'connected' => filled($team->slack_bot_token)]);
        }

        return back()->with('success', 'Slack connection updated.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'enabled' => ['required', 'boolean'],
            'values' => ['nullable', 'array'],
            'values.screenshots_per_hour' => ['nullable', 'integer', 'min:0', 'max:60'],
            'values.blur_screenshots' => ['nullable', 'boolean'],
            'values.capture_enabled' => ['nullable', 'boolean'],
            'values.activity_tracking_enabled' => ['nullable', 'boolean'],
            'values.app_url_tracking_enabled' => ['nullable', 'boolean'],
            'values.weekly_time_limit_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'values.auto_pause_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'values.allow_offline_time' => ['nullable', 'boolean'],
            'values.notify_on_screenshot' => ['nullable', 'boolean'],
            'values.desktop_auto_start' => ['nullable', 'boolean'],
            'values.desktop_force_quit_on_idle' => ['nullable', 'boolean'],
            'values.display_timezone' => ['nullable', 'timezone'],
            'values.time_format' => ['nullable', Rule::in(['12', '24'])],
        ]);

        abort_if($user->isSuperAdmin(), 422, 'Super Admins are not subject to overrides.');

        $override = UserMonitoringSetting::firstOrNew(['user_id' => $user->id]);

        $flagField = $this->flagFieldFor($data['category']);
        $valueFields = $this->valueFieldsFor($data['category']);

        $override->{$flagField} = (bool) $data['enabled'];

        if ($data['enabled']) {
            $team = MonitoringSetting::current()->teamPayload();
            foreach ($valueFields as $field) {
                $incoming = $data['values'][$field] ?? null;
                $override->{$field} = $incoming ?? $team[$field] ?? null;
            }
        } else {
            foreach ($valueFields as $field) {
                $override->{$field} = null;
            }
        }

        $override->user_id = $user->id;
        $override->save();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Individual setting updated.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WORK CATEGORIES — the workspace's own editable "what the work was" list.
    //
    // Concept B of the split vocabulary (documents/STAFFVORA_WORK_VOCABULARY.md):
    //   A. CAPTURE METHOD — tracked vs manual. System-set, never edited here.
    //   B. WORK CATEGORY  — these rows.
    //   C. BILLABLE       — a flag on the entry, seeded from a category default.
    //
    // ONE MECHANISM, ONE CODE PATH (owner decision Q5). There is deliberately no
    // `if (agency)` branch anywhere below: an Agency workspace and a General one
    // run this exact code, and differ only in which starter ROWS were seeded at
    // signup by WorkCategory::defaultsFor(). Sparking Asia's "Bidding" and
    // "Outside Upwork" are ordinary editable rows like any other customer's.
    //
    // PERMISSION: every route lives inside the existing `permission:monitoring.settings`
    // settings group — the same gate that guards the rest of this controller. No
    // new permission key is introduced.
    //
    // TENANCY: WorkCategory carries BelongsToOrganization, so the OrganizationScope
    // already restricts route-model binding and every query to the active tenant
    // (a foreign id 404s). assertOwned() re-checks the resolved row anyway, and
    // reorder verifies EVERY submitted id before writing a single row.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The management screen. Lists active + archived categories in the
     * workspace's own order, each with a SQL COUNT of the time entries filed
     * against it (so an admin sees the blast radius before archiving).
     */
    public function workCategories(): Response
    {
        // First visit to a workspace that has never had a category materialises
        // its starter set — the same one shared list, no branch.
        WorkCategory::ensureSeeded();

        $categories = WorkCategory::query()
            ->ordered()
            ->limit(self::MAX_WORK_CATEGORIES)
            ->get(['id', 'organization_id', 'name', 'slug', 'is_billable_default', 'sort_order', 'is_active']);

        $usage = $this->workCategoryUsageCounts();

        return Inertia::render('Settings/WorkCategories', [
            'categories' => $categories
                ->map(fn (WorkCategory $category) => $this->serializeWorkCategory(
                    $category,
                    (int) ($usage[(int) $category->id] ?? 0)
                ))
                ->values()
                ->all(),
            'maxCategories' => self::MAX_WORK_CATEGORIES,
        ]);
    }

    /** Add a category to the end of the workspace's list. */
    public function storeWorkCategory(Request $request): RedirectResponse|JsonResponse
    {
        $organizationId = app(CurrentOrganization::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', $this->workCategoryNameRule($organizationId)],
            'is_billable_default' => ['sometimes', 'boolean'],
        ]);

        // COUNT, never a fetch — and the ceiling is what keeps every read on
        // this screen (and the picker on the time-entry form) bounded.
        if (WorkCategory::query()->count() >= self::MAX_WORK_CATEGORIES) {
            throw ValidationException::withMessages([
                'name' => 'You have reached the maximum of '.self::MAX_WORK_CATEGORIES.' work categories. Archive one you no longer use first.',
            ]);
        }

        $category = WorkCategory::create([
            'name' => trim($data['name']),
            // Slugs are the stable key the legacy backfill/seed maps use. A
            // workspace-created category is not part of that map, so it stays
            // null rather than risking a collision with a reserved slug.
            'slug' => null,
            'is_billable_default' => $request->boolean('is_billable_default'),
            'sort_order' => ((int) WorkCategory::query()->max('sort_order')) + 1,
            'is_active' => true,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'category' => $this->serializeWorkCategory($category, 0),
            ], 201);
        }

        return back()->with('success', 'Work category added.');
    }

    /**
     * Rename, change the default-billable flag, or archive/restore. The slug is
     * deliberately never touched: a renamed row keeps its history and keeps
     * resolving for the legacy `work_type` readers.
     */
    public function updateWorkCategory(Request $request, WorkCategory $workCategory): RedirectResponse|JsonResponse
    {
        $this->assertOwnsWorkCategory($workCategory);

        $organizationId = app(CurrentOrganization::class)->id();

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60', $this->workCategoryNameRule($organizationId, $workCategory->id)],
            'is_billable_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_active', $data) && ! $request->boolean('is_active')) {
            $this->assertNotLastActiveWorkCategory($workCategory);
        }

        if (array_key_exists('name', $data)) {
            $workCategory->name = trim($data['name']);
        }
        if (array_key_exists('is_billable_default', $data)) {
            $workCategory->is_billable_default = $request->boolean('is_billable_default');
        }
        if (array_key_exists('is_active', $data)) {
            $workCategory->is_active = $request->boolean('is_active');
        }

        $workCategory->save();

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'category' => $this->serializeWorkCategory($workCategory, $this->workCategoryUsage($workCategory)),
            ]);
        }

        return back()->with('success', 'Work category updated.');
    }

    /**
     * ARCHIVE (the default) — a category that time is filed against is NEVER
     * hard-deleted, because every historical timesheet, report and Slack digest
     * still has to render its name. Archiving hides it from the pickers used to
     * log NEW time and leaves history untouched.
     *
     * A permanent delete is only honoured for a category with ZERO entries, and
     * the zero is proven by a SQL COUNT on the (organization_id, work_category_id)
     * index — never by loading rows.
     */
    public function destroyWorkCategory(Request $request, WorkCategory $workCategory): RedirectResponse|JsonResponse
    {
        $this->assertOwnsWorkCategory($workCategory);

        $entries = $this->workCategoryUsage($workCategory);
        $permanent = $request->boolean('permanent');

        if ($permanent && $entries > 0) {
            throw ValidationException::withMessages([
                'permanent' => 'This category is used by '.$entries.' time '.($entries === 1 ? 'entry' : 'entries').', so it can only be archived — deleting it would break historical reports.',
            ]);
        }

        // Applies to BOTH paths: removing the only active category (even an
        // unused one) would leave the workspace with nowhere to file new time.
        $this->assertNotLastActiveWorkCategory($workCategory);

        if ($permanent) {
            $workCategory->delete();
        } else {
            $workCategory->update(['is_active' => false]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'deleted' => $permanent,
                'archived' => ! $permanent,
                'entries_count' => $entries,
            ]);
        }

        return back()->with('success', $permanent ? 'Work category deleted.' : 'Work category archived.');
    }

    /**
     * Persist a new display order (drag or the up/down arrows). The full ordered
     * id list is submitted; EVERY id is proved to belong to the current
     * workspace before a single row is written, so a foreign id can neither be
     * re-sorted nor used to probe another tenant.
     */
    public function reorderWorkCategories(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1', 'max:'.self::MAX_WORK_CATEGORIES],
            'order.*' => ['required', 'integer', 'min:1'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['order'])));

        // One bounded query (org-scoped) confirms ownership of the whole batch.
        $owned = WorkCategory::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        abort_if(count($owned) !== count($ids), 404);

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                // Still org-scoped by the global scope — belt and braces.
                WorkCategory::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        // Mass updates skip model events, so the memoised catalogue (which
        // carries sort_order) has to be dropped by hand.
        WorkCategory::flushResolverCache();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Work category order saved.');
    }

    /**
     * How many time entries each category holds, for the whole workspace, in ONE
     * grouped SQL COUNT. Rides work_hours_org_category_idx (organization_id,
     * work_category_id); returns at most one row per category, so the result is
     * bounded no matter how many time rows the workspace has. Rows are never
     * loaded.
     *
     * @return array<int, int> category id => entry count
     */
    private function workCategoryUsageCounts(): array
    {
        $rows = WorkHour::query()
            ->whereNotNull('work_category_id')
            ->groupBy('work_category_id')
            ->selectRaw('work_category_id, COUNT(*) as entries_count')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->work_category_id] = (int) $row->entries_count;
        }

        return $counts;
    }

    /** The same COUNT for one category (index-backed, no rows loaded). */
    private function workCategoryUsage(WorkCategory $category): int
    {
        return (int) WorkHour::query()->where('work_category_id', $category->id)->count();
    }

    /**
     * Re-verify the tenant of a route-bound category. The OrganizationScope
     * already 404s a foreign id during binding; this makes the boundary explicit
     * and survives any future call site that resolves the model differently.
     */
    private function assertOwnsWorkCategory(WorkCategory $category): void
    {
        $organizationId = app(CurrentOrganization::class)->id();

        abort_if($organizationId !== null && (int) $category->organization_id !== $organizationId, 404);
    }

    /**
     * A workspace must always keep at least one active category, otherwise there
     * is nothing to file new time against.
     */
    private function assertNotLastActiveWorkCategory(WorkCategory $category): void
    {
        if (! $category->is_active) {
            return;
        }

        $remaining = WorkCategory::query()->active()->whereKeyNot($category->id)->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'is_active' => 'Keep at least one active work category — your team needs somewhere to file new time.',
            ]);
        }
    }

    /** Category names are unique within a workspace (never globally). */
    private function workCategoryNameRule(?int $organizationId, ?int $ignoreId = null): Unique
    {
        $rule = Rule::unique('work_categories', 'name')
            ->where(fn ($query) => $query->where('organization_id', $organizationId));

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }

    /** @return array<string, mixed> */
    private function serializeWorkCategory(WorkCategory $category, int $entriesCount): array
    {
        return [
            'id' => (int) $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'is_billable_default' => (bool) $category->is_billable_default,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
            'entries_count' => $entriesCount,
        ];
    }

    /**
     * Reject any team setting that exceeds the org's effective entitlements
     * (plan defaults merged with platform per-tenant overrides). Throws a
     * ValidationException keyed by field so the form shows an inline
     * "upgrade to unlock" reason.
     */
    private function enforceEntitlements(Organization $org, array $data): void
    {
        $errors = [];

        // Screenshot monitoring: the feature itself, then the per-hour ceiling.
        if (! $org->can('monitoring')) {
            if (($data['capture_enabled'] ?? false) || (int) ($data['screenshots_per_hour'] ?? 0) > 0) {
                $errors['screenshots_per_hour'] = 'Screenshot monitoring is not included in your plan.';
            }
        } elseif (! $org->entitlementAllows('max_screenshots_per_hour', (int) ($data['screenshots_per_hour'] ?? 0))) {
            $max = $org->entitlement('max_screenshots_per_hour');
            $errors['screenshots_per_hour'] = "Your plan allows up to {$max} screenshots per hour. Upgrade to increase the rate.";
        }

        // Auto-pause floor: lower minutes = more aggressive; plans set a floor.
        $pause = (int) ($data['auto_pause_minutes'] ?? 0);
        if ($pause > 0 && ! $org->entitlementAllows('min_auto_pause_minutes', $pause)) {
            $min = $org->entitlement('min_auto_pause_minutes');
            $errors['auto_pause_minutes'] = "Your plan's minimum auto-pause is {$min} minutes. Upgrade for a tighter idle limit.";
        }

        // Feature flags.
        if (($data['activity_tracking_enabled'] ?? false) && ! $org->can('activity_tracking')) {
            $errors['activity_tracking_enabled'] = 'Activity tracking is not included in your plan.';
        }
        if (($data['app_url_tracking_enabled'] ?? false) && ! $org->can('app_url_tracking')) {
            $errors['app_url_tracking_enabled'] = 'App & URL tracking is not included in your plan.';
        }
        if (! empty($data['weekly_time_limit_hours']) && ! $org->can('weekly_limit')) {
            $errors['weekly_time_limit_hours'] = 'Weekly time limits are not included in your plan.';
        }
        if ((($data['slack_clockin_enabled'] ?? false) || ($data['slack_clockout_enabled'] ?? false)) && ! $org->can('attendance_alerts')) {
            $errors['slack_clockin_enabled'] = 'Attendance Slack alerts are not included in your plan.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function flagFieldFor(string $category): string
    {
        return [
            'screenshots' => 'override_screenshots',
            'activity' => 'override_activity',
            'app_url' => 'override_app_url',
            'weekly_limit' => 'override_weekly_limit',
            'auto_pause' => 'override_auto_pause',
            'offline_time' => 'override_offline_time',
            'notify_screenshot' => 'override_notify_screenshot',
            'desktop_app' => 'override_desktop_app',
            'display' => 'override_display',
            'week_starts_on' => 'override_screenshots', // no per-user override
            'currency' => 'override_screenshots',        // no per-user override
        ][$category];
    }

    /** @return string[] */
    private function valueFieldsFor(string $category): array
    {
        return [
            'screenshots' => ['screenshots_per_hour', 'blur_screenshots', 'capture_enabled'],
            'activity' => ['activity_tracking_enabled'],
            'app_url' => ['app_url_tracking_enabled'],
            'weekly_limit' => ['weekly_time_limit_hours'],
            'auto_pause' => ['auto_pause_minutes'],
            'offline_time' => ['allow_offline_time'],
            'notify_screenshot' => ['notify_on_screenshot'],
            'desktop_app' => ['desktop_auto_start', 'desktop_force_quit_on_idle'],
            'display' => ['display_timezone', 'time_format'],
            'week_starts_on' => [],
            'currency' => [],
        ][$category];
    }

    /** @return array<string, mixed> */
    private function serializeOverride(?UserMonitoringSetting $row): array
    {
        if (! $row) {
            return [
                'override_screenshots' => false,
                'override_activity' => false,
                'override_app_url' => false,
                'override_weekly_limit' => false,
                'override_auto_pause' => false,
                'override_offline_time' => false,
                'override_notify_screenshot' => false,
                'override_desktop_app' => false,
                'override_display' => false,
            ];
        }

        return [
            'override_screenshots' => (bool) $row->override_screenshots,
            'override_activity' => (bool) $row->override_activity,
            'override_app_url' => (bool) $row->override_app_url,
            'override_weekly_limit' => (bool) $row->override_weekly_limit,
            'override_auto_pause' => (bool) $row->override_auto_pause,
            'override_offline_time' => (bool) $row->override_offline_time,
            'override_notify_screenshot' => (bool) $row->override_notify_screenshot,
            'override_desktop_app' => (bool) $row->override_desktop_app,
            'override_display' => (bool) $row->override_display,
            'display_timezone' => $row->display_timezone,
            'time_format' => $row->time_format,
            'screenshots_per_hour' => $row->screenshots_per_hour,
            'blur_screenshots' => $row->blur_screenshots,
            'capture_enabled' => $row->capture_enabled,
            'activity_tracking_enabled' => $row->activity_tracking_enabled,
            'app_url_tracking_enabled' => $row->app_url_tracking_enabled,
            'weekly_time_limit_hours' => $row->weekly_time_limit_hours,
            'auto_pause_minutes' => $row->auto_pause_minutes,
            'allow_offline_time' => $row->allow_offline_time,
            'manual_time_overlap_policy' => $row->manual_time_overlap_policy ?: 'warn',
            'notify_on_screenshot' => $row->notify_on_screenshot,
            'desktop_auto_start' => $row->desktop_auto_start,
            'desktop_force_quit_on_idle' => $row->desktop_force_quit_on_idle,
        ];
    }
}
