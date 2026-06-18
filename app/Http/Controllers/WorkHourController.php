<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MonitoringSetting;
use App\Models\TrackingAuditLog;
use App\Models\TrackingSession;
use App\Models\UpworkProfile;
use App\Models\User;
use App\Models\WorkHour;
use App\Services\SlackReportService;
use App\Services\TrackingSessionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class WorkHourController extends Controller
{
    private const WORK_TYPES = [
        'tracker',
        'manual',
        'fixed',
        'outside_of_upwork',
        'office_work',
        'test_task',
        'upwork_bidding',
    ];

    private function authorizeWorkHourAccess(WorkHour $workHour): void
    {
        $user = auth()->user();

        if (! $user->hasPermission('work_hours.manage_all') && $workHour->user_id !== $user->id) {
            abort(403, 'You can only manage your own work hour entries.');
        }
    }

    /**
     * Manual work-hour entry is always available to Admin/Super Admin (anyone
     * who can manage all entries). For regular Members it depends on the team
     * `allow_offline_time` setting from the new Settings page.
     */
    private function ensureCanCreateManual(): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin() || $user->hasPermission('work_hours.manage_all')) {
            return;
        }

        if (! MonitoringSetting::current()->allow_offline_time) {
            abort(403, 'Manual work-hour entry is disabled by your administrator.');
        }
    }

    private function filterValues(Request $request, string $key, ?string $legacyKey = null): array
    {
        $value = $request->input($key);

        if ($value === null && $legacyKey) {
            $value = $request->input($legacyKey);
        }

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        return collect($value)
            ->flatten()
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '' && $item !== 'all')
            ->unique()
            ->values()
            ->all();
    }

    private function applyCommonFilters($query, array $filters): void
    {
        if (! empty($filters['workTypes'])) {
            $query->whereIn('work_type', $filters['workTypes']);
        }

        if (! empty($filters['trackers'])) {
            $query->whereIn('tracker', $filters['trackers']);
        }

        if (! empty($filters['clients'])) {
            $query->whereHas('client', function ($q) use ($filters) {
                $q->whereIn('name', $filters['clients']);
            });
        }

        if (! empty($filters['userIds'])) {
            $query->whereIn('user_id', $filters['userIds']);
        }

        if (! empty($filters['designations'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->whereIn('designation', $filters['designations']);
            });
        }
    }

    private function firstLegacyValue(array $values): string
    {
        return $values[0] ?? 'all';
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

    public function index(Request $request)
    {
        $user = auth()->user();
        $query = WorkHour::with('user', 'client');

        // WorkHoursList is for personal work diary - everyone sees only their own entries
        $query->where('user_id', $user->id);

        $filter = $request->input('filter', 'all');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $selectedWorkTypes = $this->filterValues($request, 'workTypes', 'workType');
        $selectedTrackers = $this->filterValues($request, 'trackers', 'tracker');
        $selectedClients = $this->filterValues($request, 'clients', 'client');
        $perPage = $request->input('perPage', 15);

        // Validate perPage to prevent abuse
        $allowedPerPage = [15, 25, 50, 100];
        if (! in_array($perPage, $allowedPerPage)) {
            $perPage = 15;
        }

        // Apply date filter
        if ($filter !== 'all' && $startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        $this->applyCommonFilters($query, [
            'workTypes' => $selectedWorkTypes,
            'trackers' => $selectedTrackers,
            'clients' => $selectedClients,
        ]);

        // Implement pagination with dynamic per page
        $workHours = $query->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

        // Preserve query parameters in pagination links
        $workHours->appends($request->query());

        $availableClients = WorkHour::with('client')
            ->where('user_id', $user->id)
            ->whereHas('client')
            ->get()
            ->pluck('client.name')
            ->unique()
            ->filter()
            ->sort()
            ->values();

        $availableTrackers = WorkHour::where('user_id', $user->id)
            ->whereNotNull('tracker')
            ->distinct()
            ->pluck('tracker')
            ->filter()
            ->sort()
            ->values();

        return Inertia::render('WorkHoursList', [
            'workHours' => $workHours,
            'filter' => $filter,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'workType' => $this->firstLegacyValue($selectedWorkTypes),
            'tracker' => $this->firstLegacyValue($selectedTrackers),
            'client' => $this->firstLegacyValue($selectedClients),
            'perPage' => $perPage,
            'selectedFilters' => [
                'workTypes' => $selectedWorkTypes,
                'trackers' => $selectedTrackers,
                'clients' => $selectedClients,
            ],
            'filterOptions' => [
                'workTypes' => self::WORK_TYPES,
                'trackers' => $availableTrackers,
                'clients' => $availableClients,
            ],
            'flash' => [
                'success' => $request->session()->get('success') ?? '',
                'error' => $request->session()->get('error') ?? '',
            ],
        ]);
    }

    public function create()
    {
        $this->ensureCanCreateManual();

        $clients = Client::with(['upworkProfile', 'upworkProfiles'])
            ->select('id', 'name', 'work_type', 'upwork_profile_id')
            ->orderBy('name')
            ->get();

        $trackers = UpworkProfile::active()
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        return Inertia::render('WorkHourCreate', [
            'trackers' => $trackers,
            'clients' => $clients,
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureCanCreateManual();

        $validated = $request->validate([
            'date' => 'required|date',
            'hours' => 'required|integer|min:0|max:24',
            'minutes' => 'required|integer|min:0|max:59',
            'description' => 'required|string|max:5000',
            'work_type' => ['required', Rule::in(self::WORK_TYPES)],
            'client_id' => 'nullable|integer|exists:clients,id',
            'tracker' => 'nullable|string|max:255',
        ]);

        if ((int) $validated['hours'] === 0 && (int) $validated['minutes'] === 0) {
            return back()->withErrors(['hours' => 'Please enter at least some time.']);
        }

        $validated['user_id'] = $request->user()->id;
        $validated['hours'] = $validated['hours'] + ($validated['minutes'] / 60);
        $validated['source'] = 'manual';
        unset($validated['minutes']);
        WorkHour::create($validated);

        return redirect()->route('work-hours.index')
            ->with('success', 'Work hour entry created successfully.');
    }

    public function edit(WorkHour $workHour)
    {
        $this->authorizeWorkHourAccess($workHour);

        // Load the client relationship
        $workHour->load('client');

        $clients = Client::select('id', 'name')
            ->orderBy('name')
            ->get();

        $trackers = UpworkProfile::active()
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        return Inertia::render('WorkHourEdit', [
            'workHour' => $workHour,
            'trackers' => $trackers,
            'clients' => $clients,
        ]);
    }

    public function update(Request $request, WorkHour $workHour)
    {
        $this->authorizeWorkHourAccess($workHour);

        $validated = $request->validate([
            'date' => 'required|date',
            'hours' => 'required|integer|min:0|max:24',
            'minutes' => 'required|integer|min:0|max:59',
            'description' => 'required|string|max:5000',
            'work_type' => ['required', Rule::in(self::WORK_TYPES)],
            'client_id' => 'nullable|integer|exists:clients,id',
            'tracker' => 'nullable|string|max:255',
        ]);

        if ((int) $validated['hours'] === 0 && (int) $validated['minutes'] === 0) {
            return back()->withErrors(['hours' => 'Please enter at least some time.']);
        }

        $validated['hours'] = $validated['hours'] + ($validated['minutes'] / 60);
        unset($validated['minutes']);
        $workHour->update($validated);

        return redirect()->route('work-hours.index')
            ->with('success', 'Work hour entry updated successfully.');
    }

    public function destroy(Request $request, WorkHour $workHour)
    {
        $this->authorizeWorkHourAccess($workHour);

        $cascaded = $this->deleteWorkHourAndSource($workHour, $request->user());

        return $this->redirectToReturnPath($request, 'work-hours.index', [
            'success' => $cascaded
                ? 'Work entry deleted — its tracking session, screenshots, and activity data were removed too.'
                : 'Work hour entry deleted.',
        ]);
    }

    /**
     * Delete a work entry and, when it mirrors a tracker session, tear down
     * that whole session (screenshots, activity samples) so it also vanishes
     * from the Timeline and Team Performance. Manual entries have no source
     * session and are simply removed. Returns true when a session cascaded.
     */
    private function deleteWorkHourAndSource(WorkHour $workHour, ?User $actor): bool
    {
        $session = $workHour->tracking_session_id
            ? TrackingSession::find($workHour->tracking_session_id)
            : null;

        if (! $session) {
            $workHour->delete();

            return false;
        }

        TrackingAuditLog::record([
            'tracking_session_id' => $session->id,
            'subject_user_id' => $session->user_id,
            'actor_user_id' => $actor?->id,
            'action' => 'session.delete',
            'event_date' => optional($session->started_at)->toDateString(),
            'old_value' => [
                'via' => 'work_diary',
                'total_seconds' => (int) $session->total_seconds,
                'screenshots' => $session->screenshots()->count(),
            ],
            'new_value' => null,
            'reason' => 'Work-diary entry deleted',
        ]);

        // purge() removes the mirrored work-hours row as well.
        app(TrackingSessionService::class)->purge($session);

        return true;
    }

    public function bulkDelete(Request $request)
    {
        if ($request->has('entry_ids') && ! $request->has('ids')) {
            $request->merge(['ids' => $request->input('entry_ids')]);
        }

        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:work_hours,id',
        ]);

        $user = auth()->user();
        $ids = $validated['ids'];

        $query = WorkHour::whereIn('id', $ids);
        if (! $user->hasPermission('work_hours.manage_all')) {
            // Ensure user can only delete their own entries.
            if (WorkHour::whereIn('id', $ids)->where('user_id', $user->id)->count() !== count($ids)) {
                return back()->withErrors([
                    'ids' => 'Some entries could not be deleted. You can only delete your own entries.',
                ]);
            }
            $query->where('user_id', $user->id);
        }

        // Cascade each entry so tracker-synced ones also remove their session,
        // screenshots, and activity data (not just the work_hours row).
        $entries = $query->get();
        foreach ($entries as $entry) {
            $this->deleteWorkHourAndSource($entry, $user);
        }

        return back()->with('success', 'Successfully deleted '.$entries->count().' entries.');
    }

    public function exportPersonal(Request $request)
    {
        $user = auth()->user();
        $query = WorkHour::with('user', 'client');

        // Only show current user's entries
        $query->where('user_id', $user->id);

        $filter = $request->input('filter', 'all');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $selectedWorkTypes = $this->filterValues($request, 'workTypes', 'workType');
        $selectedTrackers = $this->filterValues($request, 'trackers', 'tracker');
        $selectedClients = $this->filterValues($request, 'clients', 'client');
        $idsOnly = $request->input('idsOnly', false);

        // Apply date filter
        if ($filter !== 'all' && $startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        $this->applyCommonFilters($query, [
            'workTypes' => $selectedWorkTypes,
            'trackers' => $selectedTrackers,
            'clients' => $selectedClients,
        ]);

        // If only IDs are requested, return just ID and minimal data for bulk operations
        if ($idsOnly) {
            $workHours = $query->orderByDesc('date')->orderByDesc('id')->get(['id']);
        } else {
            // Get all data without pagination
            $workHours = $query->orderByDesc('date')->orderByDesc('id')->get();
        }

        return response()->json([
            'data' => $workHours,
        ]);
    }

    public function export(Request $request)
    {
        $query = WorkHour::with('user', 'client');
        $filter = $request->input('filter', 'all');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $selectedWorkTypes = $this->filterValues($request, 'workTypes', 'workType');
        $selectedUserIds = $this->filterValues($request, 'userIds', 'userId');
        $selectedDesignations = $this->filterValues($request, 'designations', 'designation');
        $selectedTrackers = $this->filterValues($request, 'trackers', 'tracker');
        $selectedClients = $this->filterValues($request, 'clients', 'client');

        // Apply date filter - only apply if both dates are provided
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        $this->applyCommonFilters($query, [
            'workTypes' => $selectedWorkTypes,
            'userIds' => $selectedUserIds,
            'designations' => $selectedDesignations,
            'trackers' => $selectedTrackers,
            'clients' => $selectedClients,
        ]);

        // Get all data without pagination
        $workHours = $query->orderByDesc('date')->orderByDesc('id')->get();

        return response()->json([
            'data' => $workHours,
        ]);
    }

    public function report(Request $request, SlackReportService $slack)
    {
        $query = WorkHour::query();
        $filter = $request->input('filter', 'all');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');
        $selectedWorkTypes = $this->filterValues($request, 'workTypes', 'workType');
        $selectedUserIds = $this->filterValues($request, 'userIds', 'userId');
        $selectedDesignations = $this->filterValues($request, 'designations', 'designation');
        $selectedTrackers = $this->filterValues($request, 'trackers', 'tracker');
        $selectedClients = $this->filterValues($request, 'clients', 'client');
        $perPage = $request->input('perPage', 15);

        // Fetch all available designation filter options.
        $availableDesignations = User::whereNotNull('designation')
            ->distinct()
            ->pluck('designation')
            ->filter()
            ->sort()
            ->values();

        $availableTrackers = UpworkProfile::active()
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        // One indexed query for the dropdown — the previous shape hydrated
        // every work_hours row with its client just to list distinct names.
        $availableClients = Client::query()
            ->whereIn('id', WorkHour::whereNotNull('client_id')->select('client_id')->distinct())
            ->orderBy('name')
            ->pluck('name')
            ->values();

        // Validate perPage to prevent abuse
        $allowedPerPage = [15, 25, 50, 100];
        if (! in_array($perPage, $allowedPerPage)) {
            $perPage = 15;
        }

        // Apply date filter - only apply if both dates are provided
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        $this->applyCommonFilters($query, [
            'workTypes' => $selectedWorkTypes,
            'userIds' => $selectedUserIds,
            'designations' => $selectedDesignations,
            'trackers' => $selectedTrackers,
            'clients' => $selectedClients,
        ]);

        // Server-side search across all pages — the search box previously
        // only filtered the rows already on screen, silently hiding matches
        // that lived on other pages.
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('tracker', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            });
        }

        // Merge identical entries (same person · date · client · work type ·
        // tracker · source) into one totalled row, so a client logged three
        // times in a day shows once with the summed hours and an "×3" badge.
        // `?detailed=1` falls back to one row per raw entry.
        if ($request->boolean('detailed')) {
            $workHours = $query->with('user', 'client')
                ->orderByDesc('date')->orderByDesc('id')
                ->paginate($perPage);
        } else {
            $workHours = $query
                ->leftJoin('users', 'work_hours.user_id', '=', 'users.id')
                ->leftJoin('clients', 'work_hours.client_id', '=', 'clients.id')
                ->selectRaw("MIN(work_hours.id) as id, work_hours.date, work_hours.user_id, users.name as user_name, work_hours.client_id, clients.name as client_name, work_hours.work_type, work_hours.tracker, work_hours.source, SUM(work_hours.hours) as hours, COUNT(*) as entry_count, GROUP_CONCAT(NULLIF(work_hours.description, '') SEPARATOR ' · ') as description")
                ->groupBy('work_hours.date', 'work_hours.user_id', 'users.name', 'work_hours.client_id', 'clients.name', 'work_hours.work_type', 'work_hours.tracker', 'work_hours.source')
                ->orderByDesc('work_hours.date')->orderBy('users.name')->orderBy('clients.name')
                ->paginate($perPage);

            $workHours->through(fn ($r) => [
                'id' => (int) $r->id,
                'date' => substr((string) $r->date, 0, 10),
                'user' => ['name' => $r->user_name],
                'client' => $r->client_id ? ['name' => $r->client_name] : null,
                'work_type' => $r->work_type,
                'tracker' => $r->tracker,
                'source' => $r->source,
                'hours' => (float) $r->hours,
                'entry_count' => (int) $r->entry_count,
                'description' => $r->description,
            ]);
        }

        // Preserve query parameters in pagination links
        $workHours->appends($request->query());

        $users = User::orderBy('name')->get(['id', 'name', 'include_in_slack_reports']);

        return Inertia::render('WorkHoursReport', [
            'search' => $search,
            'workHours' => $workHours,
            'users' => $users,
            'filter' => $filter,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'workType' => $this->firstLegacyValue($selectedWorkTypes),
            'userId' => $this->firstLegacyValue($selectedUserIds),
            'designation' => $this->firstLegacyValue($selectedDesignations),
            'tracker' => $this->firstLegacyValue($selectedTrackers),
            'client' => $this->firstLegacyValue($selectedClients),
            'perPage' => $perPage,
            'availableDesignations' => $availableDesignations,
            'availableTrackers' => $availableTrackers,
            'availableClients' => $availableClients,
            'selectedFilters' => [
                'workTypes' => $selectedWorkTypes,
                'userIds' => $selectedUserIds,
                'designations' => $selectedDesignations,
                'trackers' => $selectedTrackers,
                'clients' => $selectedClients,
            ],
            'filterOptions' => [
                'workTypes' => self::WORK_TYPES,
                'users' => $users,
                'designations' => $availableDesignations,
                'trackers' => $availableTrackers,
                'clients' => $availableClients,
            ],
            'slackConfigured' => $slack->configured(),
            'slackWeeklyEnabled' => (bool) config('services.slack_reports.weekly_enabled'),
            'flash' => [
                'success' => $request->session()->get('success') ?? '',
                'error' => $request->session()->get('error') ?? '',
            ],
        ]);
    }
}
