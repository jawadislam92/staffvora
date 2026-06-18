<?php

namespace App\Http\Controllers;

use App\Models\Designation;
use App\Models\MonitoringSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

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

        if ($sort === 'weekly_hours') {
            $query->withSum([
                'workHours as weekly_hours_sum' => fn ($q) => $q->whereBetween('date', [$startOfWeek, $endOfWeek]),
            ], 'hours')->orderBy('weekly_hours_sum', $dir);
        } else {
            $query->orderBy($sortColumns[$sort] ?? 'name', $dir);
        }

        $users = $query->orderBy('name')
            ->paginate($perPage)
            ->appends($request->query());

        $users->getCollection()->transform(function ($user) use ($startOfWeek, $endOfWeek) {
            // Get the sum of hours for this week
            $weeklyHours = $user->workHours()
                ->whereBetween('date', [$startOfWeek, $endOfWeek])
                ->sum('hours');

            // Convert decimal hours to HH:MM format
            $hours = floor($weeklyHours);
            $minutes = round(($weeklyHours - $hours) * 60);

            // Format as HH:MM
            $user->weekly_hours_worked = sprintf('%02d:%02d', $hours, $minutes);
            $user->role_label = $user->role_label;
            $user->shift_start_display = $user->shift_start_time?->format('g:i A');
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
                'sort' => $sort,
                'dir' => $dir,
            ],
            'permissionGroups' => config('access.permissions'),
            'filterOptions' => [
                'designations' => $allDesignations,
                'roles' => collect(User::ROLES)->map(
                    fn (string $label, string $value) => ['value' => $value, 'label' => $label]
                )->values(),
            ],
            'managedDesignations' => $this->designationOptions(),
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
            'email' => ['required', 'not_regex:/[\r\n]/', 'email', 'unique:users'],
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
        ];

        // Only add avatar validation if file is present
        if ($request->hasFile('avatar')) {
            $rules['avatar'] = 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048';
        }

        $validated = $request->validate($rules);

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
            'allow_multiple_devices' => $request->user()->isSuperAdmin()
                ? (bool) ($validated['allow_multiple_devices'] ?? false)
                : false,
        ];

        // Only add avatar if we have one
        if ($avatarPath) {
            $userData['avatar'] = $avatarPath;
        }

        User::create($userData);
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

    private function accessFormProps(): array
    {
        return [
            'roles' => config('access.roles'),
            'permissionGroups' => config('access.permissions'),
            'canManageAccess' => request()->user()->isSuperAdmin(),
            'designationOptions' => $this->designationOptions()->pluck('name')->values()->all(),
        ];
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
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'set_shift' => ['boolean'],
            'shift_start_time' => ['nullable', 'date_format:H:i'],
            'shift_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'shift_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'clockout_reminder_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
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
