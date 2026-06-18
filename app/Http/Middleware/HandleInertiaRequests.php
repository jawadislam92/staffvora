<?php

namespace App\Http\Middleware;

use App\Models\MonitoringSetting;
use App\Models\TimeEntry;
use App\Models\UserMonitoringSetting;
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
        $user = $request->user();
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
            ];

            try {
                // Shift-anchored "today" (overnight shifts span midnight).
                $today = $user->attendanceDateFor(Carbon::now('Asia/Karachi'));
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
                $teamSettings = Cache::remember('monitoring_settings.shared', 60, function () {
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
        $branding = ['logo_url' => null];
        try {
            $branding = Cache::remember('branding.shared', 300, function () {
                $path = MonitoringSetting::current()->branding_logo_path;

                return [
                    'logo_url' => $path ? route('branding.logo').'?v='.substr(md5($path), 0, 8) : null,
                ];
            });
        } catch (\Throwable $e) {
            // Keep rendering with the bundled logo if the table is missing.
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $authUser,
                'lastActionToday' => $lastActionToday,
            ],
            'teamSettings' => $teamSettings,
            'display' => $display,
            'branding' => $branding,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
