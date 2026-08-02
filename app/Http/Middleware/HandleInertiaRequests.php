<?php

namespace App\Http\Middleware;

use App\Models\MonitoringSetting;
use App\Models\Organization;
use App\Models\TimeEntry;
use App\Models\UserMonitoringSetting;
use App\Support\BusinessTime;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Entitlements;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Explicitly the customer (web) guard: the platform portal's `auth:platform`
        // middleware flips the default guard to `platform`, and a PlatformUser
        // isn't a customer User — sharing it here would call User-only methods.
        $user = $request->user('web');
        $lastActionToday = null;
        $authUser = null;

        if ($user) {
            $authUser = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'role_label' => $user->role_label,
                'avatar' => $user->avatar,
                'avatar_url' => $user->avatar_url,
                'designation' => $user->designation,
                'permissions' => $user->effectivePermissions(),
                'is_super_admin' => $user->isSuperAdmin(),
                'is_platform_admin' => $user->isPlatformAdmin(),
            ];

            try {
                // Shift-anchored "today" (overnight shifts span midnight).
                $today = $user->attendanceDateFor(Carbon::now(BusinessTime::tz()));
                $entry = TimeEntry::query()
                    ->where('user_id', $user->id)
                    ->whereDate('action_date', $today)
                    ->orderByDesc('action_timestamp')
                    ->first();
                $lastActionToday = $entry?->action_type;
            } catch (\Throwable $e) {
                // Silently ignore to avoid breaking responses
                $lastActionToday = null;
            }
        }

        // Only fetch team settings for authenticated pages; guests don't render
        // anything that needs them, so this skips a DB hit on /login and other
        // public routes. Cached for 60s so repeated authenticated requests
        // don't re-query the monitoring_settings table.
        $teamSettings = null;
        $display = ['timezone' => 'Asia/Karachi', 'format' => '12'];
        if ($user) {
            try {
                $teamSettings = Cache::remember('monitoring_settings.shared.'.(app(CurrentOrganization::class)->id() ?? 0), 60, function () {
                    $row = MonitoringSetting::current();

                    return [
                        'allow_offline_time' => (bool) $row->allow_offline_time,
                        'currency_symbol' => $row->currency_symbol,
                        'week_starts_on' => $row->week_starts_on,
                        'display_timezone' => $row->display_timezone ?: 'Asia/Karachi',
                        'time_format' => $row->time_format ?: '12',
                    ];
                });

                // Team default, then apply this user's display override if any.
                $display = [
                    'timezone' => $teamSettings['display_timezone'] ?? 'Asia/Karachi',
                    'format' => $teamSettings['time_format'] ?? '12',
                ];
                $override = UserMonitoringSetting::where('user_id', $user->id)
                    ->where('override_display', true)
                    ->first(['display_timezone', 'time_format']);
                if ($override) {
                    if ($override->display_timezone) {
                        $display['timezone'] = $override->display_timezone;
                    }
                    if ($override->time_format) {
                        $display['format'] = $override->time_format;
                    }
                }
            } catch (\Throwable $e) {
                $teamSettings = null;
            }
        }

        $canCreateManualWorkHour = false;
        if ($user) {
            $canCreateManualWorkHour = $user->isSuperAdmin()
                || $user->hasPermission('work_hours.manage_all')
                || ($teamSettings['allow_offline_time'] ?? false);
        }

        if ($authUser) {
            $authUser['can_create_manual_work_hour'] = $canCreateManualWorkHour;
        }

        // Shared with guests too — the login/welcome pages render the logo.
        // The ?v= cache-buster changes with the stored path so a freshly
        // uploaded logo replaces the browser-cached one immediately.
        $orgId = app(CurrentOrganization::class)->id();
        $branding = ['logo_url' => null, 'name' => null, 'accent_color' => null, 'default_theme' => 'light'];
        try {
            $branding = Cache::remember('branding.shared.'.($orgId ?? 0), 300, function () use ($orgId) {
                $path = MonitoringSetting::current()->branding_logo_path;
                $org = $orgId ? Organization::find($orgId) : null;

                return [
                    'logo_url' => $path ? route('branding.logo').'?v='.substr(md5($path), 0, 8) : null,
                    'name' => $org?->brandName(),
                    'accent_color' => $org?->accent_color,
                    'default_theme' => $org?->default_theme ?: 'light',
                ];
            });
        } catch (\Throwable $e) {
            // Keep rendering with the bundled logo if the table is missing.
        }

        // Resolved theme: per-user override → workspace default → light (D-3).
        $theme = $user?->theme ?: ($branding['default_theme'] ?? 'light');
        $theme = in_array($theme, ['light', 'dark', 'black'], true) ? $theme : 'light';

        // Effective entitlements — the SAME resolution the server gates on:
        // per-company override → active add-ons → plan → catalogue default.
        // This is only so the UI can show locks; every route and write is
        // enforced independently through Organization::can()/entitlement().
        //
        // Cache invalidation has two halves, so an operator change is never
        // stale: the per-company key is dropped whenever that organization is
        // saved (Organization::booted) or its add-ons change, and the payload
        // carries the catalogue STAMP, so one plan/feature/add-on edit
        // invalidates every company at once without enumerating them.
        $entitlements = null;
        if ($user && $orgId) {
            try {
                $key = Entitlements::sharedCacheKey($orgId);
                $stamp = Entitlements::stamp();
                $cached = Cache::get($key);

                if (is_array($cached) && ($cached['stamp'] ?? null) === $stamp) {
                    $entitlements = $cached['map'];
                } else {
                    $entitlements = Organization::find($orgId)?->entitlements();
                    Cache::put($key, ['stamp' => $stamp, 'map' => $entitlements], 60);
                }
            } catch (\Throwable $e) {
                $entitlements = null;
            }
        }

        // Per-workspace TERMINOLOGY only — what the client/profile entities are
        // called and which business timezone to render in.
        //
        // It deliberately carries NO capability flag. `uses_profiles` used to
        // live here and was a SECOND client-side source of truth for access,
        // resolved from `workspace_type` rather than from the plan. The nav and
        // the client/timesheet pages now read `entitlements.upwork_profiles`
        // from the shared map above — the same resolution the server gates on —
        // so there is exactly one answer to "may this workspace see Profiles?".
        //
        // Safe to cache for 5 minutes precisely because nothing here is an
        // access decision; the entitlement map has its own stamp-based
        // invalidation so an operator grant is never served stale.
        $workspace = ['client_term' => 'Project', 'profile_term' => 'Profile', 'timezone' => 'UTC'];
        if ($orgId) {
            try {
                $workspace = Cache::remember('workspace.shared.'.$orgId, 300, function () use ($orgId) {
                    $org = Organization::find($orgId);

                    return [
                        'client_term' => $org?->clientTerm() ?? 'Project',
                        'profile_term' => $org?->profileTerm() ?? 'Profile',
                        'timezone' => $org?->businessTimezone() ?? 'UTC',
                    ];
                });
            } catch (\Throwable $e) {
                // Keep the in-house defaults if the column/table isn't there yet.
            }
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $authUser,
                'lastActionToday' => $lastActionToday,
            ],
            'teamSettings' => $teamSettings,
            'display' => $display,
            'workspace' => $workspace,
            'branding' => $branding,
            'theme' => $theme,
            'entitlements' => $entitlements,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                // One-time teammate credentials surfaced by the onboarding wizard.
                'invited' => $request->session()->get('invited'),
            ],
            'impersonating' => $request->session()->has('platform_impersonator') || $request->session()->has('impersonator_id'),
        ];
    }
}
