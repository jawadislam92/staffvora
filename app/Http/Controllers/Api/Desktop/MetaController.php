<?php

namespace App\Http\Controllers\Api\Desktop;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MonitoringSetting;
use App\Models\UpworkProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetaController extends Controller
{
    public function clients(Request $request): JsonResponse
    {
        // Profiles can be linked either via the legacy `clients.upwork_profile_id`
        // column or the newer `client_upwork_profile` many-to-many pivot.
        // The web Clients page uses the pivot; only loading the legacy column
        // misses every client whose profile lives in the pivot, leaving the
        // desktop "Tracker" chip stuck on "Not attached".
        $clients = Client::query()
            ->with(['upworkProfile:id,name', 'upworkProfiles:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'work_type', 'upwork_profile_id'])
            ->map(function (Client $client) {
                $primaryProfile = $client->upworkProfile ?? $client->upworkProfiles->first();

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'work_type' => $client->work_type,
                    'work_type_label' => Client::getWorkTypes()[$client->work_type] ?? null,
                    'upwork_profile_id' => $primaryProfile?->id,
                    'upwork_profile_name' => $primaryProfile?->name,
                    // All linked profiles, so the desktop could later let the
                    // user pick when more than one is attached.
                    'upwork_profiles' => $client->upworkProfiles
                        ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
                        ->values(),
                ];
            });

        return response()->json(['clients' => $clients]);
    }

    public function workTypes(): JsonResponse
    {
        return response()->json([
            'work_types' => collect(Client::getWorkTypes())
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function upworkProfiles(): JsonResponse
    {
        $profiles = UpworkProfile::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['profiles' => $profiles]);
    }

    public function settings(Request $request): JsonResponse
    {
        $settings = MonitoringSetting::current();
        $effective = $settings->effectiveForUser($request->user());

        return response()->json([
            'settings' => $effective,
        ]);
    }
}
