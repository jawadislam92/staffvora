<?php

namespace App\Http\Controllers;

use App\Models\Designation;
use App\Models\MonitoringSetting;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Sanctum\PersonalAccessToken;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('perPage', 10);
        $rawRoles = $request->input('roles', $request->input('role', []));
        $rawDesignations = $request->input('designations', $request->input('designation', []));
        $roles = collect(is_array($rawRoles) ? $rawRoles : [$rawRoles])
            ->map(fn ($role) => (string) $role)
            ->filter(fn ($role) => $role !== '' && $role !== 'all')
            ->unique()
            ->values()
            ->all();
        $designations = collect(is_array($rawDesignations) ? $rawDesignations : [$rawDesignations])
            ->map(fn ($designation) => (string) $designation)
            ->filter(fn ($designation) => $designation !== '' && $designation !== 'all')
            ->unique()
            ->values()
            ->all();

        // Validate perPage to ensure it's within reasonable limits
        if (! in_array($perPage, [10, 25, 50, 100])) {
            $perPage = 10;
        }

        // Start building the query
        $query = User::query();

        // Apply search filter
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        // Apply designation filter.
        if (! empty($designations)) {
            $query->where(function ($designationQuery) use ($designations) {
                $regularDesignations = array_values(array_diff($designations, ['no_designation']));

                if (! empty($regularDesignations)) {
                    $designationQuery->whereIn('designation', $regularDesignations);
                }

                if (in_array('no_designation', $designations, true)) {
                    $method = empty($regularDesignations) ? 'where' : 'orWhere';
                    $designationQuery->{$method}(function ($emptyQuery) {
                        $emptyQuery->whereNull('designation')->orWhere('designation', '');
                    });
                }
            });
        }

        if (! empty($roles)) {
            $query->whereIn('role', $roles);
        }

        // Active / deactivated filter — defaults to "all" so the directory
        // shows everyone (deactivated rows are badged in the UI), with optional
        // narrowing to active-only or archived-only.
        $status = (string) $request->get('status', 'all');
        if (! in_array($status, ['all', 'active', 'archived'], true)) {
            $status = 'all';
        }
        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'archived') {
            $query->archived();
        }

        $startOfWeek = now()->startOfWeek(MonitoringSetting::weekStartDay())->format('Y-m-d');
        $endOfWeek = now()->endOfWeek(MonitoringSetting::weekEndDay())->format('Y-m-d');

        // Sortable columns. Weekly hours sorts on a SQL subquery sum so the
        // order is correct across pages, not just within the visible page.
        $sort = (string) $request->get('sort', 'name');
        $dir = $request->get('dir') === 'desc' ? 'desc' : 'asc';
        $sortColumns = [
            'name' => 'name',
            'designation' => 'designation',
            'shift' => 'shift_start_time',
            'role' => 'role',
        ];

        // This week's hours as ONE correlated subquery on the page query,
        // applied unconditionally. The transform below used to re-query
        // work_hours per rendered row — with perPage up to 100 that was 100
        // extra SUM() aggregates on a table holding hundreds of thousands of
        // rows per tenant, on every single directory page load. The controller
        // already knew the answer: the weekly_hours sort branch computed
        // exactly this and the transform ignored it.
        //
        // Applied once, never twice: adding withSum() here AND in the sort
        // branch would register the weekly_hours_sum alias twice and produce a
        // duplicate/ambiguous subquery.
        $query->withSum([
            'workHours as weekly_hours_sum' => fn ($q) => $q->whereBetween('date', [$startOfWeek, $endOfWeek]),
        ], 'hours');

        if ($sort === 'weekly_hours') {
            $query->orderBy('weekly_hours_sum', $dir);
        } else {
            $query->orderBy($sortColumns[$sort] ?? 'name', $dir);
        }

        // Named shifts are a per-workspace catalog; materialise the starter list
        // the first time this workspace opens the directory.
        Shift::ensureSeeded();

        // Eager-load the assigned named shift so the Shift column costs one
        // query for the page instead of one per row.
        $users = $query->with('shift')
            ->orderBy('name')
            ->paginate($perPage)
            ->appends($request->query());

        $users->getCollection()->transform(function ($user) {
            // Already aggregated by the withSum() above — no per-row query.
            // Coalesced because withSum yields NULL (not 0) for someone with no
            // rows in the window, and that would reach floor()/arithmetic as an
            // implicit null-to-float on every empty row under PHP 8.3.
            $weeklyHours = (float) ($user->weekly_hours_sum ?? 0);

            // Convert decimal hours to HH:MM format
            $hours = floor($weeklyHours);
            $minutes = round(($weeklyHours - $hours) * 60);

            // Format as HH:MM
            $user->weekly_hours_worked = sprintf('%02d:%02d', $hours, $minutes);
            $user->is_active = (bool) ($user->is_active ?? true);
            $user->role_label = $user->role_label;
            // The shift a person actually works: the named shift's start time
            // when it defines one, otherwise their own column.
            $shiftStart = ($user->shift && $user->shift->start_time)
                ? $user->shift->start_time
                : $user->shift_start_time;
            $user->shift_start_display = $shiftStart?->format('g:i A');
            $user->shift_name = $user->shift?->name;
            $user->joining_date_display = $user->joining_date?->format('Y-m-d');

            return $user;
        });

        // Get all unique designations and roles for filter dropdowns.
        $allDesignations = User::whereNotNull('designation')
            ->where('designation', '!=', '')
            ->distinct()
            ->pluck('designation')
            ->sort()
            ->values();

        return Inertia::render('UsersList', [
            'users' => $users,
            'filters' => [
                'search' => $request->get('search', ''),
                'designations' => $designations,
                'roles' => $roles,
                'status' => $status,
                'sort' => $sort,
                'dir' => $dir,
            ],
            'permissionGroups' => config('access.permissions'),
            'filterOptions' => [
                'designations' => $allDesignations,
                'roles' => collect(User::ROLES)->map(
                    fn (string $label, string $value) => ['value' => $value, 'label' => $label]
                )->values(),
                'status' => [
                    ['value' => 'all', 'label' => 'All'],
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'archived', 'label' => 'Deactivated'],
                ],
            ],
            'managedDesignations' => $this->designationOptions(),
            'managedShifts' => $this->shiftOptions(),
        ]);
    }

    public function create()
    {
        return Inertia::render('UserCreate', $this->accessFormProps());
    }

    public function store(Request $request)
    {
        $rules = [
            'name' => 'required|string|max:255',
            // reservedEmailRule keeps the sample-data namespace out of a
            // customer-supplied address. User::booted() refuses it regardless;
            // this is here so the form says so instead of throwing.
            'email' => ['required', 'not_regex:/[\r\n]/', 'email', 'unique:users', User::reservedEmailRule()],
            'password' => ['required', 'string', Password::min(12)],
            'role' => ['nullable', Rule::in(array_keys(User::ROLES))],
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in($this->permissionKeys())],
            'include_in_slack_reports' => 'nullable|boolean',
            'designation' => 'nullable|string|max:255',
            'joining_date' => ['nullable', 'date_format:Y-m-d'],
            'shift_start_time' => ['nullable', 'date_format:H:i'],
            'shift_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'shift_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'clockout_reminder_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'allow_multiple_devices' => 'nullable|boolean',
            'team_id' => ['nullable', Rule::in(Team::pluck('id')->all())],
            // Named shift (optional). Rule::in over the tenant-scoped ids, so a
            // shift belonging to another workspace can never be assigned.
            'shift_id' => ['nullable', Rule::in(Shift::pluck('id')->all())],
        ];

        // Only add avatar validation if file is present
        if ($request->hasFile('avatar')) {
            $rules['avatar'] = 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048';
        }

        $validated = $request->validate($rules);

        // Enforce the plan's seat limit (null = unlimited). Counts only this
        // org's users via the global scope, and only the REAL ones:
        // User::seatUsage() excludes sample-data teammates so a loaded demo can
        // never stand between a customer and a real hire. That exclusion is not
        // an exploit — User::booted() refuses the reserved demo e-mail namespace
        // on every request-reachable write path, so the address validated above
        // can never be made to match it.
        $seatLimit = $request->user()->organization?->seatLimit();
        if ($seatLimit !== null && User::seatUsage() >= $seatLimit) {
            throw ValidationException::withMessages([
                'email' => "Your plan includes up to {$seatLimit} users. Upgrade your plan to add more.",
            ]);
        }

        $avatarPath = null;
        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('avatars', 'avatars');
        }

        $userData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $request->user()->isSuperAdmin() ? ($validated['role'] ?? 'member') : 'member',
            'permissions' => $request->user()->isSuperAdmin()
                ? $this->validatedPermissions($validated['permissions'] ?? [])
                : [],
            'include_in_slack_reports' => $request->user()->isSuperAdmin()
                ? ($validated['include_in_slack_reports'] ?? true)
                : true,
            'designation' => $validated['designation'] ?? null,
            'joining_date' => $validated['joining_date'] ?? null,
            'shift_start_time' => $validated['shift_start_time'] ?? null,
            'shift_grace_minutes' => $validated['shift_grace_minutes'] ?? 15,
            'shift_hours' => $validated['shift_hours'] ?? null,
            'clockout_reminder_hours' => $validated['clockout_reminder_hours'] ?? null,
            'team_id' => $validated['team_id'] ?? null,
            'shift_id' => $validated['shift_id'] ?? null,
            'allow_multiple_devices' => $request->user()->isSuperAdmin()
                ? (bool) ($validated['allow_multiple_devices'] ?? false)
                : false,
        ];

        // Only add avatar if we have one
        if ($avatarPath) {
            $userData['avatar'] = $avatarPath;
        }

        // Stamp admin-created accounts as verified. There is no invite-mail
        // flow here — the admin types the password and hands it over, which IS
        // the vouching step — so these rows would otherwise sit permanently
        // unverified and be the thing that locks the whole existing user base
        // out on the day email verification is switched on for real (see the
        // class docblock on App\Models\User). Landing it now makes that flip a
        // one-word change instead of a production incident.
        //
        // Not mass assigned: email_verified_at is deliberately absent from
        // $fillable, so no request payload can ever set it.
        $user = new User($userData);
        $user->email_verified_at = now();
        $user->save();

        $this->rememberDesignation($userData['designation']);

        return redirect()->route('users.index')->with('success', 'User created successfully!');
    }

    public function edit(User $user)
    {
        $this->guardSuperAdminTarget($user);

        return Inertia::render('UserEdit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'permissions' => $user->permissions ?? [],
                'include_in_slack_reports' => $user->include_in_slack_reports,
                'avatar_url' => $user->avatar_url,
                'designation' => $user->designation,
                'joining_date' => $user->joining_date?->format('Y-m-d'),
                'shift_start_time' => $user->shift_start_time?->format('H:i'),
                'shift_grace_minutes' => $user->shift_grace_minutes ?? 15,
                'shift_hours' => $user->shift_hours !== null ? (float) $user->shift_hours : '',
                'clockout_reminder_hours' => $user->clockout_reminder_hours !== null ? (float) $user->clockout_reminder_hours : '',
                'team_id' => $user->team_id,
                'allow_multiple_devices' => (bool) $user->allow_multiple_devices,
                'return_to' => request('return_to'),
            ],
            ...$this->accessFormProps(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'not_regex:/[\r\n]/',
                'email',
                Rule::unique('users')->ignore($user->id),
                // Renaming a real person into the sample-data namespace would
                // take them off the seat count. Refused here and in the model.
                User::reservedEmailRule(),
            ],
            'password' => ['nullable', 'string', Password::min(12)],
            'role' => ['nullable', Rule::in(array_keys(User::ROLES))],
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in($this->permissionKeys())],
            'include_in_slack_reports' => 'nullable|boolean',
            'designation' => 'nullable|string|max:255',
            'joining_date' => ['nullable', 'date_format:Y-m-d'],
            'shift_start_time' => ['nullable', 'date_format:H:i'],
            'shift_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'shift_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'clockout_reminder_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'allow_multiple_devices' => 'nullable|boolean',
            'team_id' => ['nullable', Rule::in(Team::pluck('id')->all())],
            'shift_id' => ['nullable', Rule::in(Shift::pluck('id')->all())],
        ];

        // Only add avatar validation if file is present
        if ($request->hasFile('avatar')) {
            $rules['avatar'] = 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048';
        }

        $validated = $request->validate($rules);
        $this->guardSuperAdminTarget($user);

        if ($request->user()->is($user)
            && $request->user()->isSuperAdmin()
            && ($validated['role'] ?? 'member') !== 'super_admin') {
            throw ValidationException::withMessages([
                'role' => 'You cannot remove Super Admin access from your own account.',
            ]);
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->designation = $validated['designation'] ?? null;
        $user->joining_date = $validated['joining_date'] ?? null;
        $user->shift_start_time = $validated['shift_start_time'] ?? null;
        $user->shift_grace_minutes = $validated['shift_grace_minutes'] ?? 15;
        $user->shift_hours = $validated['shift_hours'] ?? null;
        $user->clockout_reminder_hours = $validated['clockout_reminder_hours'] ?? null;
        $user->team_id = $validated['team_id'] ?? null;

        // Only touched when the form actually submits the field, so a page that
        // does not yet render the named-shift picker cannot silently clear it.
        if ($request->has('shift_id')) {
            $user->shift_id = $validated['shift_id'] ?: null;
        }

        if ($request->user()->isSuperAdmin()) {
            $user->role = $validated['role'] ?? 'member';
            $user->permissions = $user->role === 'super_admin'
                ? []
                : $this->validatedPermissions($validated['permissions'] ?? []);
            $user->include_in_slack_reports = $validated['include_in_slack_reports'] ?? true;
            $user->allow_multiple_devices = (bool) ($validated['allow_multiple_devices'] ?? false);
        }

        // Only update password if it's provided and not empty
        if (! empty($validated['password']) && $validated['password'] !== '') {
            $user->password = Hash::make($validated['password']);
        }

        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->avatar && Storage::disk('avatars')->exists($user->avatar)) {
                Storage::disk('avatars')->delete($user->avatar);
            }
            $user->avatar = $request->file('avatar')->store('avatars', 'avatars');
        }

        $user->save();
        $this->rememberDesignation($user->designation);

        return $this->redirectToReturnPath($request, 'users.index', [
            'success' => 'User updated successfully!',
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        $this->guardSuperAdminTarget($user);
        $user->delete();

        return $this->redirectToReturnPath($request, 'users.index', [
            'success' => 'User deleted successfully.',
        ]);
    }

    public function storeDesignation(Request $request)
    {
        if (! $request->user()->hasPermission('users.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = $this->normalizeDesignationName($validated['name']);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Enter a designation name.',
            ]);
        }

        if (Designation::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages([
                'name' => 'This designation already exists.',
            ]);
        }

        Designation::create(['name' => $name]);

        return $this->redirectToReturnPath($request, 'users.index', [
            'success' => 'Designation added.',
        ]);
    }

    public function destroyDesignation(Request $request, Designation $designation)
    {
        if (! $request->user()->hasPermission('users.manage')) {
            abort(403);
        }

        $designation->delete();

        return $this->redirectToReturnPath($request, 'users.index', [
            'success' => 'Designation removed from suggestions.',
        ]);
    }

    /**
     * Add a named shift to this workspace's curated list (Morning / Noon /
     * Evening / Night / …). Pick-only elsewhere, so this is the single place a
     * name can be created — no free text, no typos. Names only have to be
     * unique WITHIN the workspace; the duplicate check runs through the
     * tenant-scoped query, so another workspace's "Morning" never collides.
     *
     * Optional timing (start time / length / grace) makes the shift the source
     * of truth for everyone assigned to it; leave it blank to keep the shift a
     * pure label and let each person's own shift_start_time keep driving their
     * schedule.
     */
    public function storeShift(Request $request)
    {
        if (! $request->user()->hasPermission('users.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
        ]);

        $name = $this->normalizeDesignationName($validated['name']);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter a shift name.']);
        }

        if (Shift::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => 'This shift already exists.']);
        }

        Shift::create([
            'name' => $name,
            'start_time' => $validated['start_time'] ?? null,
            'hours' => $validated['hours'] ?? null,
            'grace_minutes' => $validated['grace_minutes'] ?? null,
            'sort_order' => ((int) Shift::max('sort_order')) + 1,
        ]);

        return $this->redirectToReturnPath($request, 'users.index', [
            'success' => 'Shift added.',
        ]);
    }

    /**
     * Remove a named shift. Clears it from everyone who had it first — there are
     * no DB-level foreign keys in this schema (SQLite/MySQL parity), so the
     * application owns the "just clear it from them" behaviour outright.
     */
    public function destroyShift(Request $request, Shift $shift)
    {
        if (! $request->user()->hasPermission('users.manage')) {
            abort(403);
        }

        User::where('shift_id', $shift->id)->update(['shift_id' => null]);
        $shift->delete();

        return $this->redirectToReturnPath($request, 'users.index', [
            'success' => 'Shift removed.',
        ]);
    }

    private function accessFormProps(): array
    {
        return [
            'roles' => config('access.roles'),
            'permissionGroups' => config('access.permissions'),
            'canManageAccess' => request()->user()->isSuperAdmin(),
            'designationOptions' => $this->designationOptions()->pluck('name')->values()->all(),
            'shiftOptions' => $this->shiftOptions(),
            'teams' => Team::orderBy('name')->get(['id', 'name']),
        ];
    }

    /** The curated shift list, ordered for the pick-only dropdown. */
    private function shiftOptions()
    {
        return Shift::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'start_time', 'hours', 'grace_minutes'])
            ->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'name' => $shift->name,
                'start_time' => $shift->start_time?->format('H:i'),
                'hours' => $shift->hours !== null ? (float) $shift->hours : null,
                'grace_minutes' => $shift->grace_minutes,
            ])
            ->values();
    }

    private function designationOptions()
    {
        return Designation::query()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function rememberDesignation(?string $designation): void
    {
        $name = $this->normalizeDesignationName($designation ?? '');

        if ($name === '') {
            return;
        }

        Designation::query()->firstOrCreate(['name' => $name]);
    }

    private function normalizeDesignationName(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $name));
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

    /**
     * Bulk edit selected users. Only the sections the admin explicitly
     * enabled are applied; everything else is left untouched. Permission
     * changes require Super Admin, and non-supers can never modify a
     * Super Admin account.
     */
    public function bulkUpdate(Request $request)
    {
        $actor = $request->user();

        $data = $request->validate([
            // No per-element exists: rule — it fires one SELECT per submitted
            // id (200 extra queries at the cap) to re-derive a check the
            // tenant-scoped whereIn below already performs, and being
            // un-scoped it doubled as a cross-tenant id oracle.
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['integer'],
            'set_shift' => ['boolean'],
            'shift_start_time' => ['nullable', 'date_format:H:i'],
            'shift_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'shift_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'clockout_reminder_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            // Assign (or clear, with a null shift_id) the curated named shift.
            'set_shift_name' => ['boolean'],
            'shift_id' => ['nullable', Rule::in(Shift::pluck('id')->all())],
            'set_designation' => ['boolean'],
            'designation' => ['nullable', 'string', 'max:255'],
            'permissions_add' => ['array'],
            'permissions_add.*' => [Rule::in($this->permissionKeys())],
            'permissions_remove' => ['array'],
            'permissions_remove.*' => [Rule::in($this->permissionKeys())],
            'slack_reports' => ['nullable', Rule::in(['include', 'exclude'])],
        ]);

        $wantsPermissionChanges = ! empty($data['permissions_add']) || ! empty($data['permissions_remove']);
        if ($wantsPermissionChanges && ! $actor->isSuperAdmin()) {
            abort(403, 'Only Super Admins can change permissions.');
        }

        $users = User::whereIn('id', $data['user_ids'])->get();
        $updated = 0;

        foreach ($users as $user) {
            if ($user->isSuperAdmin() && ! $actor->isSuperAdmin()) {
                continue;
            }

            if (! empty($data['set_shift'])) {
                $user->shift_start_time = $data['shift_start_time'] ?? null;
                if (array_key_exists('shift_grace_minutes', $data) && $data['shift_grace_minutes'] !== null) {
                    $user->shift_grace_minutes = $data['shift_grace_minutes'];
                }
                if (array_key_exists('shift_hours', $data)) {
                    $user->shift_hours = $data['shift_hours'];
                }
                if (array_key_exists('clockout_reminder_hours', $data)) {
                    $user->clockout_reminder_hours = $data['clockout_reminder_hours'];
                }
            }

            if (! empty($data['set_shift_name'])) {
                $user->shift_id = $data['shift_id'] ?: null;
            }

            if (! empty($data['set_designation'])) {
                $user->designation = $data['designation'] ?: null;
            }

            if ($wantsPermissionChanges && ! $user->isSuperAdmin()) {
                $user->permissions = collect($user->permissions ?? [])
                    ->merge($data['permissions_add'] ?? [])
                    ->unique()
                    ->reject(fn ($p) => in_array($p, $data['permissions_remove'] ?? [], true))
                    ->values()
                    ->all();
            }

            if (! empty($data['slack_reports'])) {
                $user->include_in_slack_reports = $data['slack_reports'] === 'include';
            }

            if ($user->isDirty()) {
                $user->save();
                $updated++;
            }
        }

        return response()->json(['message' => "Updated {$updated} user(s).", 'updated' => $updated]);
    }

    /**
     * Activate / deactivate a single user. Deactivating someone who left the
     * company blocks their web + desktop login and revokes any live desktop
     * token immediately; their history is untouched. Can't touch a Super Admin
     * (unless you are one) or deactivate your own account.
     */
    public function setStatus(Request $request, User $user)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $this->guardSuperAdminTarget($user);

        if (! $data['is_active'] && $request->user()->is($user)) {
            throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
        }

        $user->is_active = $data['is_active'];
        $user->save();

        if (! $user->is_active) {
            $user->tokens()->delete(); // revoke desktop access immediately
        }

        return $this->redirectToReturnPath($request, 'users.index', [
            'success' => $data['is_active'] ? 'User reactivated.' : 'User deactivated.',
        ]);
    }

    /**
     * Bulk activate / deactivate. Either an explicit list of ids, or — for the
     * first big cleanup — every user matching the current directory filters
     * ("select all N matching"). Skips Super Admins (unless the actor is one)
     * and the actor's own account; revokes desktop tokens for anyone deactivated.
     */
    public function bulkStatus(Request $request)
    {
        $actor = $request->user();

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            // Capped: an uncapped array became a whereIn with tens of thousands
            // of bindings, which MySQL either rejects outright or resolves with
            // a full table scan.
            'ids' => ['nullable', 'array', 'max:200'],
            'ids.*' => ['integer'],
            'all_matching' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string'],
            'roles' => ['nullable', 'array'],
            'designations' => ['nullable', 'array'],
            'status' => ['nullable', 'string'],
        ]);

        $isActive = (bool) $data['is_active'];
        $changed = 0;

        // The array cap cannot bound "select all N matching" — that branch used
        // to pluck EVERY matching id into memory and then save() one row at a
        // time, which for a big customer's first cleanup is tens of thousands
        // of individual UPDATEs in one web request. Both branches now stream
        // through the same chunked, batched path. The OrganizationScope keeps
        // either one inside the acting tenant, so ids from another org resolve
        // to nothing.
        $base = ! empty($data['all_matching'])
            ? $this->filteredUserQuery($request)
            : User::whereIn('id', $data['ids'] ?? []);

        $base->select(['id', 'role', 'is_active'])
            ->chunkById(500, function ($chunk) use ($actor, $isActive, &$changed) {
                $ids = $chunk
                    ->reject(fn (User $user) => $user->isSuperAdmin() && ! $actor->isSuperAdmin())
                    ->reject(fn (User $user) => $actor->is($user))
                    ->reject(fn (User $user) => (bool) $user->is_active === $isActive)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    return;
                }

                // One UPDATE per chunk instead of one per row. No model events
                // ride on is_active, so nothing is lost by skipping them —
                // updated_at is stamped explicitly since a mass update does not
                // touch timestamps on its own.
                User::whereIn('id', $ids)->update([
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);

                // Revoke desktop access immediately, in one statement rather
                // than one per user. getMorphClass() so a future morph map
                // cannot silently orphan these tokens.
                if (! $isActive) {
                    PersonalAccessToken::where('tokenable_type', (new User)->getMorphClass())
                        ->whereIn('tokenable_id', $ids)
                        ->delete();
                }

                $changed += count($ids);
            });

        $verb = $isActive ? 'Reactivated' : 'Deactivated';

        return $this->redirectToReturnPath($request, 'users.index', [
            'success' => "{$verb} {$changed} user(s).",
        ]);
    }

    /**
     * The directory query with the same search / role / designation filters
     * index() applies (status filter intentionally excluded so "select all
     * matching" targets the whole filtered set regardless of active state).
     */
    private function filteredUserQuery(Request $request)
    {
        $rawRoles = $request->input('roles', $request->input('role', []));
        $rawDesignations = $request->input('designations', $request->input('designation', []));
        $roles = collect(is_array($rawRoles) ? $rawRoles : [$rawRoles])
            ->map(fn ($role) => (string) $role)
            ->filter(fn ($role) => $role !== '' && $role !== 'all')->unique()->values()->all();
        $designations = collect(is_array($rawDesignations) ? $rawDesignations : [$rawDesignations])
            ->map(fn ($d) => (string) $d)
            ->filter(fn ($d) => $d !== '' && $d !== 'all')->unique()->values()->all();

        $query = User::query();

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
            });
        }
        if (! empty($designations)) {
            $query->where(function ($dq) use ($designations) {
                $regular = array_values(array_diff($designations, ['no_designation']));
                if (! empty($regular)) {
                    $dq->whereIn('designation', $regular);
                }
                if (in_array('no_designation', $designations, true)) {
                    $method = empty($regular) ? 'where' : 'orWhere';
                    $dq->{$method}(fn ($eq) => $eq->whereNull('designation')->orWhere('designation', ''));
                }
            });
        }
        if (! empty($roles)) {
            $query->whereIn('role', $roles);
        }

        return $query;
    }

    private function permissionKeys(): array
    {
        return collect(config('access.permissions'))
            ->flatMap(fn (array $permissions) => array_keys($permissions))
            ->values()
            ->all();
    }

    private function validatedPermissions(array $permissions): array
    {
        return array_values(array_unique(array_intersect($permissions, $this->permissionKeys())));
    }

    private function guardSuperAdminTarget(User $user): void
    {
        if ($user->isSuperAdmin() && ! request()->user()->isSuperAdmin()) {
            abort(403, 'Only a Super Admin can manage another Super Admin account.');
        }
    }
}
