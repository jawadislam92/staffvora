<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MonitoringSetting;
use App\Models\UpworkProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('perPage', 10);
            $search = $request->get('search', '');
            $status = $request->get('status', 'all');

            // Validate perPage to ensure it's within reasonable limits
            if (! in_array($perPage, [10, 25, 50, 100])) {
                $perPage = 10;
            }

            // Validate status filter
            if (! in_array($status, ['all', 'active', 'archived'], true)) {
                $status = 'all';
            }

            $startOfWeek = now()->startOfWeek(MonitoringSetting::weekStartDay())->format('Y-m-d');
            $endOfWeek = now()->endOfWeek(MonitoringSetting::weekEndDay())->format('Y-m-d');

            $query = Client::with(['upworkProfile', 'upworkProfiles'])
                // SCALE: this week's hours used to be a SUM() query PER ROW
                // inside the transform below — perPage accepts 100, so one page
                // load fired up to 100 extra aggregates against work_hours, a
                // table with hundreds of thousands of rows per tenant. One
                // correlated subquery in the paginated query replaces all of
                // them. (Paired with the new work_hours (client_id, date) index.)
                ->withSum(
                    ['workHours as weekly_hours_sum' => fn ($q) => $q->whereBetween('date', [$startOfWeek, $endOfWeek])],
                    'hours'
                )
                ->orderBy('name');

            // Apply search filter if search term is provided
            if (! empty($search)) {
                $query->where('name', 'like', '%'.$search.'%');
            }

            // Apply status filter (default 'all' shows active + archived together)
            if ($status === 'active') {
                $query->active();
            } elseif ($status === 'archived') {
                $query->archived();
            }

            $clients = $query->paginate($perPage)->appends($request->query());

            $clients->getCollection()->transform(function ($client) {
                // withSum yields NULL (not 0) for a client with no rows in the
                // window; coalescing keeps floor()/arithmetic off an implicit
                // null-to-float deprecation on every empty row.
                $weeklyHours = (float) ($client->weekly_hours_sum ?? 0);

                // Convert decimal hours to HH:MM format
                $hours = floor($weeklyHours);
                $minutes = round(($weeklyHours - $hours) * 60);

                $client->weekly_hours_worked = sprintf('%02d:%02d', $hours, $minutes);
                $client->is_active = (bool) ($client->is_active ?? true);

                return $client;
            });

            return Inertia::render('ClientsList', [
                'clients' => $clients,
                'filters' => [
                    'search' => $search,
                    'perPage' => $perPage,
                    'status' => $status,
                ],
                'filterOptions' => [
                    'status' => [
                        ['value' => 'all', 'label' => 'All'],
                        ['value' => 'active', 'label' => 'Active'],
                        ['value' => 'archived', 'label' => 'Archived'],
                    ],
                ],
                'workTypes' => Client::getWorkTypes($request->user()?->organization),
            ]);
        } catch (\Exception $e) {
            \Log::error('ClientController index error: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function create(Request $request)
    {
        $organization = $request->user()?->organization;
        $upworkProfiles = $organization?->usesProfiles()
            ? UpworkProfile::active()->orderBy('name')->get()
            : collect();
        $workTypes = Client::getWorkTypes($organization);

        return Inertia::render('ClientCreate', [
            'upworkProfiles' => $upworkProfiles,
            'workTypes' => $workTypes,
        ]);
    }

    public function store(Request $request)
    {
        $organization = $request->user()?->organization;
        $usesProfiles = (bool) $organization?->usesProfiles();
        $workTypes = Client::getWorkTypes($organization);
        $profileExists = Rule::exists('upwork_profiles', 'id')
            ->where(fn ($query) => $query->where('organization_id', $organization?->id));
        $rules = [
            'name' => 'required|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'work_type' => ['required', 'string', Rule::in(array_keys($workTypes))],
        ];

        // Make upwork_profile_ids conditionally required for multiple profiles
        if (Client::isProfileRequired($request->work_type, $organization)) {
            $rules['upwork_profile_ids'] = 'required|array|min:1';
            $rules['upwork_profile_ids.*'] = $profileExists;
        } else {
            $rules['upwork_profile_ids'] = 'nullable|array';
            $rules['upwork_profile_ids.*'] = $profileExists;
        }

        // Keep backward compatibility for single profile
        if ($request->has('upwork_profile_id') && ! $request->has('upwork_profile_ids')) {
            if (Client::isProfileRequired($request->work_type, $organization)) {
                $rules['upwork_profile_id'] = ['required', $profileExists];
            } else {
                $rules['upwork_profile_id'] = ['nullable', $profileExists];
            }
        }

        $validated = $request->validate($rules);

        // Create the client first
        $clientData = [
            'name' => $validated['name'],
            'tags' => $validated['tags'] ?? [],
            'work_type' => $validated['work_type'],
            'upwork_profile_id' => $usesProfiles ? ($validated['upwork_profile_id'] ?? null) : null,
        ];

        $client = Client::create($clientData);

        // Attach multiple profiles if provided
        if ($usesProfiles && isset($validated['upwork_profile_ids']) && is_array($validated['upwork_profile_ids'])) {
            $client->upworkProfiles()->attach($validated['upwork_profile_ids']);
        }

        return redirect()->route('clients.index')->with('success', 'Client created.');
    }

    public function edit(Request $request, Client $client)
    {
        $organization = $request->user()?->organization;
        $upworkProfiles = $organization?->usesProfiles()
            ? UpworkProfile::active()->orderBy('name')->get()
            : collect();
        $workTypes = Client::getWorkTypes($organization);

        return Inertia::render('ClientEdit', [
            'client' => $client->load(['upworkProfile', 'upworkProfiles']),
            'upworkProfiles' => $upworkProfiles,
            'workTypes' => $workTypes,
            'returnTo' => request('return_to'),
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $organization = $request->user()?->organization;
        $usesProfiles = (bool) $organization?->usesProfiles();
        $workTypes = Client::getWorkTypes($organization);
        $profileExists = Rule::exists('upwork_profiles', 'id')
            ->where(fn ($query) => $query->where('organization_id', $organization?->id));
        $rules = [
            'name' => 'required|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'work_type' => ['required', 'string', Rule::in(array_keys($workTypes))],
        ];

        // Make upwork_profile_ids conditionally required for multiple profiles
        if (Client::isProfileRequired($request->work_type, $organization)) {
            $rules['upwork_profile_ids'] = 'required|array|min:1';
            $rules['upwork_profile_ids.*'] = $profileExists;
        } else {
            $rules['upwork_profile_ids'] = 'nullable|array';
            $rules['upwork_profile_ids.*'] = $profileExists;
        }

        // Keep backward compatibility for single profile
        if ($request->has('upwork_profile_id') && ! $request->has('upwork_profile_ids')) {
            if (Client::isProfileRequired($request->work_type, $organization)) {
                $rules['upwork_profile_id'] = ['required', $profileExists];
            } else {
                $rules['upwork_profile_id'] = ['nullable', $profileExists];
            }
        }

        $validated = $request->validate($rules);

        // Update the client data
        $clientData = [
            'name' => $validated['name'],
            'tags' => $validated['tags'] ?? [],
            'work_type' => $validated['work_type'],
            'upwork_profile_id' => $usesProfiles ? ($validated['upwork_profile_id'] ?? null) : null,
        ];

        $client->update($clientData);

        // Sync multiple profiles if provided
        if ($usesProfiles && isset($validated['upwork_profile_ids']) && is_array($validated['upwork_profile_ids'])) {
            $client->upworkProfiles()->sync($validated['upwork_profile_ids']);
        } elseif (! $usesProfiles) {
            $client->upworkProfiles()->sync([]);
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

    /**
     * Archive / restore a single client. Archiving hides it from the pickers
     * used to log NEW work; every historical hour and report keeps showing.
     */
    public function setStatus(Request $request, Client $client)
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $client->update(['is_active' => $request->boolean('is_active')]);

        return $this->redirectToReturnPath($request, 'clients.index', [
            'success' => $request->boolean('is_active') ? 'Client restored.' : 'Client archived.',
        ]);
    }

    /**
     * Bulk archive / restore. Either the explicit ticked ids, or every client
     * matching the current list filters ("select all N matching").
     */
    public function bulkStatus(Request $request)
    {
        $request->validate([
            'is_active' => 'required|boolean',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'all_matching' => 'nullable|boolean',
            'search' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        try {
            if ($request->boolean('all_matching')) {
                // Rebuild the SAME query index() uses from the provided filters,
                // applying BOTH the search term AND the status scope, so the
                // affected set matches the count and rows the user saw.
                $search = $request->get('search', '');
                $status = $request->get('status', 'all');

                $query = Client::query();
                if (! empty($search)) {
                    $query->where('name', 'like', '%'.$search.'%');
                }

                // Apply the identical status scope index() used.
                if ($status === 'active') {
                    $query->active();
                } elseif ($status === 'archived') {
                    $query->archived();
                }

                $ids = $query->pluck('id')->all();
            } else {
                $ids = $request->input('ids', []);
            }

            $isActive = $request->boolean('is_active');
            // The OrganizationScope applies here too, so ids belonging to
            // another tenant simply match nothing.
            $count = Client::whereIn('id', $ids)->update(['is_active' => $isActive]);

            $action = $isActive ? 'Restored' : 'Archived';

            return $this->redirectToReturnPath($request, 'clients.index', [
                'success' => "{$action} {$count} client(s).",
            ]);
        } catch (\Exception $e) {
            \Log::error('Bulk status update error: '.$e->getMessage());

            return $this->redirectToReturnPath($request, 'clients.index', [
                'error' => 'Failed to update selected clients.',
            ]);
        }
    }

    public function export(Request $request)
    {
        try {
            $workTypes = Client::getWorkTypes($request->user()?->organization);
            $clients = Client::with(['upworkProfile', 'upworkProfiles'])->get();

            $csvData = [];
            $csvData[] = ['ID', 'Name', 'Work Type', 'Profiles', 'Tags', 'Created At']; // Header

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
                    $workTypes[$client->work_type] ?? $client->work_type,
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
            $organization = $request->user()?->organization;
            $usesProfiles = (bool) $organization?->usesProfiles();
            $workTypes = array_flip(Client::getWorkTypes($organization));
            $file = $request->file('file');
            $path = $file->getRealPath();

            // Read CSV file
            if (($handle = fopen($path, 'r')) !== false) {
                $header = fgetcsv($handle); // Skip header row
                $imported = 0;
                $errors = [];

                while (($data = fgetcsv($handle)) !== false) {
                    try {
                        // Expected CSV format: Name, Work Type, optional Profile, Tags
                        if (count($data) >= 2 && ! empty($data[0])) {
                            $workType = 'tracker_manual'; // Default
                            if (! empty($data[1])) {
                                // Try to match work type
                                $workType = $workTypes[$data[1]] ?? 'tracker_manual';
                            }

                            $upworkProfileId = null;
                            if ($usesProfiles && ! empty($data[2])) {
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
