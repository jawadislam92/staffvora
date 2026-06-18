<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MonitoringSetting;
use App\Models\UpworkProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('perPage', 10);
            $search = $request->get('search', '');

            // Validate perPage to ensure it's within reasonable limits
            if (! in_array($perPage, [10, 25, 50, 100])) {
                $perPage = 10;
            }

            $query = Client::with(['upworkProfile', 'upworkProfiles'])->orderBy('name');

            // Apply search filter if search term is provided
            if (! empty($search)) {
                $query->where('name', 'like', '%'.$search.'%');
            }

            $clients = $query->paginate($perPage)->appends($request->query());

            // Calculate weekly hours for each client and format as HH:MM
            $startOfWeek = now()->startOfWeek(MonitoringSetting::weekStartDay())->format('Y-m-d');
            $endOfWeek = now()->endOfWeek(MonitoringSetting::weekEndDay())->format('Y-m-d');

            $clients->getCollection()->transform(function ($client) use ($startOfWeek, $endOfWeek) {
                // Get the sum of hours for this week
                $weeklyHours = $client->workHours()
                    ->whereBetween('date', [$startOfWeek, $endOfWeek])
                    ->sum('hours');

                // Convert decimal hours to HH:MM format
                $hours = floor($weeklyHours);
                $minutes = round(($weeklyHours - $hours) * 60);

                // Format as HH:MM
                $client->weekly_hours_worked = sprintf('%02d:%02d', $hours, $minutes);

                return $client;
            });

            return Inertia::render('ClientsList', [
                'clients' => $clients,
                'filters' => [
                    'search' => $search,
                    'perPage' => $perPage,
                ],
                'workTypes' => Client::getWorkTypes(),
            ]);
        } catch (\Exception $e) {
            \Log::error('ClientController index error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function create()
    {
        $upworkProfiles = UpworkProfile::active()->orderBy('name')->get();
        $workTypes = Client::getWorkTypes();

        return Inertia::render('ClientCreate', [
            'upworkProfiles' => $upworkProfiles,
            'workTypes' => $workTypes,
        ]);
    }

    public function store(Request $request)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'work_type' => 'required|string|in:'.implode(',', array_keys(Client::getWorkTypes())),
        ];

        // Make upwork_profile_ids conditionally required for multiple profiles
        if (Client::isProfileRequired($request->work_type)) {
            $rules['upwork_profile_ids'] = 'required|array|min:1';
            $rules['upwork_profile_ids.*'] = 'exists:upwork_profiles,id';
        } else {
            $rules['upwork_profile_ids'] = 'nullable|array';
            $rules['upwork_profile_ids.*'] = 'exists:upwork_profiles,id';
        }

        // Keep backward compatibility for single profile
        if ($request->has('upwork_profile_id') && ! $request->has('upwork_profile_ids')) {
            if (Client::isProfileRequired($request->work_type)) {
                $rules['upwork_profile_id'] = 'required|exists:upwork_profiles,id';
            } else {
                $rules['upwork_profile_id'] = 'nullable|exists:upwork_profiles,id';
            }
        }

        $validated = $request->validate($rules);

        // Create the client first
        $clientData = [
            'name' => $validated['name'],
            'tags' => $validated['tags'] ?? [],
            'work_type' => $validated['work_type'],
            'upwork_profile_id' => $validated['upwork_profile_id'] ?? null, // Keep for backward compatibility
        ];

        $client = Client::create($clientData);

        // Attach multiple profiles if provided
        if (isset($validated['upwork_profile_ids']) && is_array($validated['upwork_profile_ids'])) {
            $client->upworkProfiles()->attach($validated['upwork_profile_ids']);
        }

        return redirect()->route('clients.index')->with('success', 'Client created.');
    }

    public function edit(Client $client)
    {
        $upworkProfiles = UpworkProfile::active()->orderBy('name')->get();
        $workTypes = Client::getWorkTypes();

        return Inertia::render('ClientEdit', [
            'client' => $client->load(['upworkProfile', 'upworkProfiles']),
            'upworkProfiles' => $upworkProfiles,
            'workTypes' => $workTypes,
            'returnTo' => request('return_to'),
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'work_type' => 'required|string|in:'.implode(',', array_keys(Client::getWorkTypes())),
        ];

        // Make upwork_profile_ids conditionally required for multiple profiles
        if (Client::isProfileRequired($request->work_type)) {
            $rules['upwork_profile_ids'] = 'required|array|min:1';
            $rules['upwork_profile_ids.*'] = 'exists:upwork_profiles,id';
        } else {
            $rules['upwork_profile_ids'] = 'nullable|array';
            $rules['upwork_profile_ids.*'] = 'exists:upwork_profiles,id';
        }

        // Keep backward compatibility for single profile
        if ($request->has('upwork_profile_id') && ! $request->has('upwork_profile_ids')) {
            if (Client::isProfileRequired($request->work_type)) {
                $rules['upwork_profile_id'] = 'required|exists:upwork_profiles,id';
            } else {
                $rules['upwork_profile_id'] = 'nullable|exists:upwork_profiles,id';
            }
        }

        $validated = $request->validate($rules);

        // Update the client data
        $clientData = [
            'name' => $validated['name'],
            'tags' => $validated['tags'] ?? [],
            'work_type' => $validated['work_type'],
            'upwork_profile_id' => $validated['upwork_profile_id'] ?? null, // Keep for backward compatibility
        ];

        $client->update($clientData);

        // Sync multiple profiles if provided
        if (isset($validated['upwork_profile_ids']) && is_array($validated['upwork_profile_ids'])) {
            $client->upworkProfiles()->sync($validated['upwork_profile_ids']);
        }

        return $this->redirectToReturnPath($request, 'clients.index', [
            'success' => 'Client updated.',
        ]);
    }

    public function destroy(Request $request, Client $client)
    {
        $client->delete();

        return $this->redirectToReturnPath($request, 'clients.index', [
            'success' => 'Client deleted.',
        ]);
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'client_ids' => 'required|array',
            'client_ids.*' => 'exists:clients,id',
        ]);

        try {
            $deletedCount = Client::whereIn('id', $request->client_ids)->delete();

            return $this->redirectToReturnPath($request, 'clients.index', [
                'success' => "{$deletedCount} client(s) deleted successfully.",
            ]);
        } catch (\Exception $e) {
            \Log::error('Bulk delete error: '.$e->getMessage());

            return $this->redirectToReturnPath($request, 'clients.index', [
                'error' => 'Failed to delete selected clients.',
            ]);
        }
    }

    public function export()
    {
        try {
            $clients = Client::with(['upworkProfile', 'upworkProfiles'])->get();

            $csvData = [];
            $csvData[] = ['ID', 'Name', 'Work Type', 'Upwork Profiles', 'Tags', 'Created At']; // Header

            foreach ($clients as $client) {
                // Get all profiles (both single and multiple)
                $profileNames = [];
                if ($client->upworkProfiles && $client->upworkProfiles->count() > 0) {
                    $profileNames = $client->upworkProfiles->pluck('name')->toArray();
                } elseif ($client->upworkProfile) {
                    $profileNames = [$client->upworkProfile->name];
                }

                $csvData[] = [
                    $client->id,
                    $client->name,
                    Client::getWorkTypes()[$client->work_type] ?? $client->work_type,
                    implode('; ', $profileNames),
                    is_array($client->tags) ? implode(', ', $client->tags) : '',
                    $client->created_at->format('Y-m-d H:i:s'),
                ];
            }

            $filename = 'clients_export_'.date('Y-m-d_H-i-s').'.csv';

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ];

            $callback = function () use ($csvData) {
                $file = fopen('php://output', 'w');
                foreach ($csvData as $row) {
                    fputcsv($file, $row);
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            \Log::error('Client export error: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to export clients.');
        }
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        try {
            $file = $request->file('file');
            $path = $file->getRealPath();

            // Read CSV file
            if (($handle = fopen($path, 'r')) !== false) {
                $header = fgetcsv($handle); // Skip header row
                $imported = 0;
                $errors = [];

                while (($data = fgetcsv($handle)) !== false) {
                    try {
                        // Expected CSV format: Name, Work Type, Upwork Profile, Tags
                        if (count($data) >= 2 && ! empty($data[0])) {
                            $workType = 'tracker_manual'; // Default
                            if (! empty($data[1])) {
                                // Try to match work type
                                $workTypes = array_flip(Client::getWorkTypes());
                                $workType = $workTypes[$data[1]] ?? 'tracker_manual';
                            }

                            $upworkProfileId = null;
                            if (! empty($data[2])) {
                                $profile = UpworkProfile::where('name', $data[2])->first();
                                $upworkProfileId = $profile ? $profile->id : null;
                            }

                            $tags = [];
                            if (! empty($data[3])) {
                                $tags = array_map('trim', explode(',', $data[3]));
                            }

                            Client::create([
                                'name' => $data[0],
                                'work_type' => $workType,
                                'upwork_profile_id' => $upworkProfileId,
                                'tags' => $tags,
                            ]);

                            $imported++;
                        }
                    } catch (\Exception $e) {
                        $errors[] = 'Row '.($imported + 2).': '.$e->getMessage();
                    }
                }
                fclose($handle);

                if ($imported > 0) {
                    $message = "Successfully imported {$imported} clients.";
                    if (count($errors) > 0) {
                        $message .= ' Errors: '.implode(', ', array_slice($errors, 0, 3));
                    }

                    return redirect()->back()->with('success', $message);
                } else {
                    return redirect()->back()->with('error', 'No valid clients found in the file.');
                }
            }

        } catch (\Exception $e) {
            \Log::error('Client import error: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to import clients: '.$e->getMessage());
        }
    }

    private function redirectToReturnPath(Request $request, string $fallbackRoute, array $flash = [])
    {
        $returnTo = $request->input('return_to');
        $redirect = is_string($returnTo)
            && str_starts_with($returnTo, '/')
            && ! str_starts_with($returnTo, '//')
                ? redirect($returnTo)
                : redirect()->route($fallbackRoute);

        foreach ($flash as $key => $value) {
            $redirect->with($key, $value);
        }

        return $redirect;
    }
}
