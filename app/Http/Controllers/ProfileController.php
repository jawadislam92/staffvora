<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\UserMonitoringSetting;
use App\Support\ImageGuard;
use App\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            // The RAW per-user display override (not the effective values in the
            // shared `display` prop, which already collapse workspace default +
            // override). The form needs to know whether this person actually has
            // an override so it can show "Workspace default" as the selection.
            'displayPreferences' => $this->displayPreferences($request),
        ]);
    }

    /**
     * The current user's own display override, shaped for the profile form.
     *
     * @return array{override: bool, timezone: ?string, format: ?string}
     */
    private function displayPreferences(Request $request): array
    {
        $blank = ['override' => false, 'timezone' => null, 'format' => null];

        $user = $request->user();
        if (! $user) {
            return $blank;
        }

        try {
            $setting = UserMonitoringSetting::forUser($user->id);
        } catch (\Throwable $e) {
            // Never break the profile page over an optional settings row.
            return $blank;
        }

        if (! $setting || ! $setting->override_display) {
            return $blank;
        }

        return [
            'override' => true,
            'timezone' => $setting->display_timezone,
            'format' => $setting->time_format,
        ];
    }

    /**
     * Update the user's own display preferences (timezone + 12/24h). Self-service
     * — every employee can pick the zone they READ times in; it only changes how
     * times display, never the stored instant and never how business days bucket
     * (attendance still uses the organization's business timezone via BusinessTime).
     *
     * Saved as a per-user override on UserMonitoringSetting, which
     * HandleInertiaRequests reads into the shared `display` prop, so every page
     * re-renders in the chosen zone on the very next request.
     *
     * An empty display_timezone means "follow the workspace default": the
     * override row stays (so the 12/24h choice is still honoured) but the zone
     * column is nulled, and the middleware's `if ($override->display_timezone)`
     * check falls through to the workspace value.
     */
    public function updateDisplay(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // 'timezone' validates against PHP's real tz database identifiers.
            'display_timezone' => ['nullable', 'string', 'timezone'],
            'time_format' => ['required', Rule::in(['12', '24'])],
        ]);

        $user = $request->user();
        $timezone = $data['display_timezone'] ?? null;
        $timezone = $timezone === '' ? null : $timezone;

        $setting = UserMonitoringSetting::forUser($user->id)
            ?? new UserMonitoringSetting(['user_id' => $user->id]);

        // Stamp the tenant on rows that predate organization_id, so the next
        // scoped read finds them (new rows get stamped by BelongsToOrganization).
        if (empty($setting->organization_id)) {
            $orgId = app(CurrentOrganization::class)->id();
            if ($orgId !== null) {
                $setting->organization_id = $orgId;
            }
        }

        $setting->fill([
            'user_id' => $user->id,
            'override_display' => true,
            'display_timezone' => $timezone,
            'time_format' => $data['time_format'],
        ])->save();

        return Redirect::route('profile.edit')->with('success', 'Display preferences updated.');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validatedData = $request->validated();

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');

            // Second half of the decompression-bomb guard (the `dimensions`
            // rule on ProfileUpdateRequest is the first). A total-pixel budget
            // is the only shape of check that works: 7999x7999 passes any
            // per-axis bound and still costs GD ~256 MB when AvatarController
            // downscales it on the next team-page render. Header-only, so
            // nothing hostile is ever decoded here.
            $image = ImageGuard::inspect($file->getRealPath(), ImageGuard::MAX_PIXELS, ['jpg', 'png', 'gif', 'webp']);

            if ($image === null) {
                throw ValidationException::withMessages([
                    'avatar' => 'That image is too large or is not a supported image file.',
                ]);
            }

            // Delete old avatar if exists
            if ($user->avatar) {
                Storage::disk('avatars')->delete($user->avatar);
            }

            // Store new avatar. store() names the file from hashName(), whose
            // extension comes from guessExtension() (finfo on the CONTENT), so
            // the client never gets to pick the stored extension — see
            // ImageGuard for why that matters.
            $avatarPath = $file->store('avatars', 'avatars');
            $validatedData['avatar'] = $avatarPath;
        } elseif ($request->has('avatar') && $request->input('avatar') === null) {
            // Handle avatar removal
            if ($user->avatar) {
                Storage::disk('avatars')->delete($user->avatar);
            }
            $validatedData['avatar'] = null;
        }

        $user->fill($validatedData);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return Redirect::route('profile.edit')->with('success', 'Profile updated successfully.');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
