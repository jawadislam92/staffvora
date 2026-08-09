<?php

namespace App\Http\Controllers;

use App\Models\UpworkProfile;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UpworkProfileController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $status = $request->input('status', 'all');
        if (! in_array($status, ['all', 'active', 'archived'], true)) {
            $status = 'all';
        }

        // Org scoping is automatic (OrganizationScope); this only narrows on
        // the archive state.
        $query = UpworkProfile::query()->orderBy('name');

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'archived') {
            $query->archived();
        }

        return Inertia::render('UpworkProfiles/Index', [
            'profiles' => $query->get(),
            'status' => $status,
        ]);
    }

    /**
     * Build the base index query from the request filters, ignoring the status
     * filter. Used by bulkStatus() so "select all matching" targets the same
     * rows index() lists.
     */
    protected function indexQuery(Request $request)
    {
        return UpworkProfile::query()->orderBy('name');
    }

    /**
     * Update a single profile's active state (serves the inline archive /
     * restore action).
     */
    public function setStatus(Request $request, UpworkProfile $upworkProfile)
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $upworkProfile->update(['is_active' => $validated['is_active']]);

        return redirect()->back()->with(
            'success',
            $validated['is_active'] ? 'Profile restored.' : 'Profile archived.'
        );
    }

    /**
     * Bulk archive/restore profiles by explicit ids or by all rows matching the
     * filters.
     */
    public function bulkStatus(Request $request)
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'all_matching' => 'nullable|boolean',
            'status' => 'nullable|string',
        ]);

        $isActive = $request->boolean('is_active');

        if ($request->boolean('all_matching')) {
            $ids = $this->indexQuery($request)->pluck('id')->all();
        } else {
            $ids = $validated['ids'] ?? [];
        }

        $count = 0;
        if (! empty($ids)) {
            // whereIn still runs through the OrganizationScope, so ids from
            // another tenant can never be flipped.
            $count = UpworkProfile::whereIn('id', $ids)->update(['is_active' => $isActive]);
        }

        $verb = $isActive ? 'Restored' : 'Archived';
        $noun = $count === 1 ? 'profile' : 'profiles';

        return redirect()->back()->with('success', "{$verb} {$count} {$noun}.");
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('UpworkProfiles/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule()],
            'email' => ['nullable', 'not_regex:/[\r\n]/', 'email', 'max:255'],
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $this->guardNameCollision(fn () => UpworkProfile::create($validated));

        return redirect()->route('upwork-profiles.index')
            ->with('success', 'Upwork profile created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(UpworkProfile $upworkProfile)
    {
        // The relation matches WorkHour rows by tracker == profile name. Never
        // hydrate the whole set (a busy profile can carry ~1M rows) — aggregate
        // in the database and only pull a bounded recent slice for the list.
        $base = $upworkProfile->workHours();

        $totals = $base->clone()
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(hours), 0) as hours, COUNT(DISTINCT user_id) as people, MAX(date) as last_date')
            ->first();

        $recent = $upworkProfile->workHours()
            ->with('user:id,name')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'user_id', 'date', 'hours'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'date' => $row->date instanceof \Carbon\Carbon
                    ? $row->date->toDateString()
                    : substr((string) $row->date, 0, 10),
                'hours' => round((float) $row->hours, 2),
                'user_name' => $row->user?->name,
            ]);

        return Inertia::render('UpworkProfiles/Show', [
            'profile' => [
                'id' => $upworkProfile->id,
                'name' => $upworkProfile->name,
                'email' => $upworkProfile->email,
                'description' => $upworkProfile->description,
                'is_active' => (bool) $upworkProfile->is_active,
            ],
            'stats' => [
                'entries' => (int) ($totals->entries ?? 0),
                'hours' => round((float) ($totals->hours ?? 0), 2),
                'people' => (int) ($totals->people ?? 0),
                'last_date' => $totals->last_date
                    ? substr((string) $totals->last_date, 0, 10)
                    : null,
            ],
            'recent' => $recent,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(UpworkProfile $upworkProfile)
    {
        return Inertia::render('UpworkProfiles/Edit', [
            'profile' => $upworkProfile,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, UpworkProfile $upworkProfile)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule($upworkProfile->id)],
            'email' => ['nullable', 'not_regex:/[\r\n]/', 'email', 'max:255'],
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $this->guardNameCollision(fn () => $upworkProfile->update($validated));

        return redirect()->route('upwork-profiles.index')
            ->with('success', 'Upwork profile updated successfully.');
    }

    /**
     * Profile-name uniqueness, scoped to the ACTIVE workspace.
     *
     * The old `unique:upwork_profiles,name` rule bypassed OrganizationScope, so
     * uniqueness was enforced across every customer on the platform. That both
     * let the first tenant to claim "Main"/"Agency" block it for everyone else,
     * and turned the validation message into a cross-tenant existence oracle:
     * submit a candidate name, read the error, learn whether another customer
     * has a profile (usually a client or brand name) by that name.
     *
     * The org id comes from the tenancy singleton rather than the request user,
     * so it is also correct under impersonation and console contexts.
     *
     * Comparison is case-insensitive on purpose. MySQL's utf8mb4_unicode_ci
     * collation collides "Main"/"main" while SQLite does not, so a string-level
     * `unique` rule passes locally and 500s in production. Matching the
     * LOWER(name) pattern already used by UserController::storeShift makes both
     * drivers behave the same way.
     */
    protected function uniqueNameRule(?int $ignoreId = null): \Closure
    {
        $orgId = app(CurrentOrganization::class)->id();

        return function (string $attribute, mixed $value, \Closure $fail) use ($orgId, $ignoreId) {
            $exists = UpworkProfile::withoutOrganizationScope()
                ->where('organization_id', $orgId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($exists) {
                $fail('You already have an Upwork profile with this name.');
            }
        };
    }

    /**
     * Two concurrent creates in the same workspace both pass validation and the
     * second hits the (organization_id, name) unique index. Convert that into
     * the same field error instead of a 500.
     */
    protected function guardNameCollision(\Closure $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'name' => 'You already have an Upwork profile with this name.',
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(UpworkProfile $upworkProfile)
    {
        $upworkProfile->delete();

        return redirect()->route('upwork-profiles.index')
            ->with('success', 'Upwork profile deleted successfully.');
    }
}
