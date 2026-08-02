<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Models\Client;
use App\Models\MonitoringSetting;
use App\Models\UpworkProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetaController extends DesktopController
{
    public function clients(Request $request): JsonResponse
    {
        $organization = $request->user()?->organization;
        $usesProfiles = (bool) $organization?->usesProfiles();
        $workTypes = Client::getWorkTypes($organization);

        // Profiles can be linked either via the legacy `clients.upwork_profile_id`
        // column or the newer `client_upwork_profile` many-to-many pivot.
        // The web Clients page uses the pivot; only loading the legacy column
        // misses every client whose profile lives in the pivot, leaving the
        // desktop "Tracker" chip stuck on "Not attached".
        $clients = Client::query()
            ->with(['upworkProfile:id,name', 'upworkProfiles:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'work_type', 'upwork_profile_id'])
            ->map(function (Client $client) use ($usesProfiles, $workTypes) {
                $primaryProfile = $usesProfiles
                    ? ($client->upworkProfile ?? $client->upworkProfiles->first())
                    : null;

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'work_type' => $client->work_type,
                    'work_type_label' => $workTypes[$client->work_type] ?? null,
                    'upwork_profile_id' => $primaryProfile?->id,
                    'upwork_profile_name' => $primaryProfile?->name,
                    // All linked profiles, so the desktop could later let the
                    // user pick when more than one is attached.
                    'upwork_profiles' => ($usesProfiles ? $client->upworkProfiles : collect())
                        ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
                        ->values(),
                ];
            });

        return response()->json(['clients' => $clients]);
    }

    public function workTypes(Request $request): JsonResponse
    {
        return response()->json([
            'work_types' => collect(Client::getWorkTypes($request->user()?->organization))
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function upworkProfiles(Request $request): JsonResponse
    {
        if (! $request->user()?->organization?->usesProfiles()) {
            return response()->json(['profiles' => []]);
        }

        $profiles = UpworkProfile::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['profiles' => $profiles]);
    }

    public function settings(Request $request): JsonResponse
    {
        $settings = MonitoringSetting::current();
        $effective = $settings->effectiveForUser($request->user());
        $organization = $request->user()?->organization;

        if ($organization) {
            $monitoring = $organization->can('monitoring');
            $planScreenshotCap = $organization->entitlement('max_screenshots_per_hour');
            $configuredScreenshotRate = (int) ($effective['screenshots_per_hour'] ?? 0);

            $effective['screenshots_per_hour'] = $planScreenshotCap === null
                ? $configuredScreenshotRate
                : min($configuredScreenshotRate, (int) $planScreenshotCap);
            $effective['capture_enabled'] = $monitoring
                && (bool) ($effective['capture_enabled'] ?? false)
                && $effective['screenshots_per_hour'] > 0;
            $effective['activity_tracking_enabled'] = $organization->can('activity_tracking')
                && (bool) ($effective['activity_tracking_enabled'] ?? false);
            $effective['app_url_tracking_enabled'] = $organization->can('app_url_tracking')
                && (bool) ($effective['app_url_tracking_enabled'] ?? false);
            $effective['require_active_window_metadata'] = $effective['app_url_tracking_enabled']
                && (bool) ($effective['require_active_window_metadata'] ?? false);
            $effective['weekly_time_limit_hours'] = $organization->can('weekly_limit')
                ? ($effective['weekly_time_limit_hours'] ?? null)
                : null;
            $effective['slack_clockin_enabled'] = $organization->can('attendance_alerts')
                && (bool) ($effective['slack_clockin_enabled'] ?? false);
            $effective['slack_clockout_enabled'] = $organization->can('attendance_alerts')
                && (bool) ($effective['slack_clockout_enabled'] ?? false);
            $effective['slack_connected'] = $organization->can('integrations')
                && (bool) ($effective['slack_connected'] ?? false);
        }

        return response()->json([
            'settings' => $effective,
            'workspace' => [
                'client_term' => $organization?->clientTerm() ?? 'Project',
                'profile_term' => $organization?->profileTerm() ?? 'Profile',
                'uses_profiles' => (bool) $organization?->usesProfiles(),
                // Told to the agent up front so a workspace WITHOUT Machine
                // Compliance never scans its employees' machines at all. Without
                // this the agent only learns it isn't entitled by collecting a
                // full inventory and getting a 403 back — pointless work, and a
                // real privacy problem in an employee-monitoring product: you do
                // not enumerate someone's installed software to then discard it.
                // Both are derived from the ONE entitlement decision.
                'machine_compliance' => (bool) $organization?->can('machine_compliance'),
            ],
        ]);
    }
}
