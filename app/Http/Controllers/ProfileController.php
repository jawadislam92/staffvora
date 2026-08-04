<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\UserMonitoringSetting;
use App\Support\ImageGuard;
use App\Support\ImageResizer;
use App\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

            // Defence in depth behind ImageGuard::rules() on ProfileUpdateRequest
            // — same class, same budget, same allow-list, so the two cannot
            // disagree. Header-only, so nothing hostile is ever decoded here.
            //
            // The budget is a TOTAL pixel count, never per-axis: 8000x4999 is
            // 39.99 Mpx and passes "max 8000 wide" in either direction, and
            // AvatarController hands whatever is stored to GD on the next
            // team-page render.
            $image = ImageGuard::inspect($file->getRealPath(), ImageGuard::MAX_DISPLAY_PIXELS, ['jpg', 'png', 'gif']);

            if ($image === null) {
                throw ValidationException::withMessages([
                    'avatar' => 'That image is too large or is not a supported image file.',
                ]);
            }

            // ── NOTHING THE CLIENT SENT IS STORED ────────────────────────────
            //
            // The upload used to be written byte-for-byte (`$file->store()`),
            // and "it is a real image" was the whole test. That is not enough: a
            // file can be a genuine GIF *and* a genuine JavaScript program at
            // once — the GIF header parses as a JS assignment and everything
            // after the GIF terminator is free space for a payload. It passes
            // `mimes:`, it passes finfo, it passes getimagesize(), because it
            // really is a GIF. PNG/HTML and JPEG/PHP polyglots work the same
            // way. Signature checks cannot win that game; there is always
            // another container.
            //
            // So we decode it and write our own encoding instead. What lands on
            // the avatars disk is a function of the PIXELS, and pixels cannot be
            // script — which also means it stays safe on any future path that
            // serves it without `X-Content-Type-Options: nosniff` (a CDN, a
            // presigned S3 URL, a different vhost). The edge header is a second
            // layer, never the control.
            //
            // The decode is bounded by the same two budgets as every other
            // decode in the app (see ImageResizer::sanitize) — no new memory
            // ceiling, none of the existing ones relaxed.
            $clean = ImageResizer::sanitize(
                (string) file_get_contents((string) $file->getRealPath()),
                ImageResizer::SANITIZED_MAX_DIM,
                ImageGuard::MAX_DISPLAY_PIXELS,
                ['jpg', 'png', 'gif'],
            );

            if ($clean === null) {
                // Refuse loudly. Falling back to storing the original bytes is
                // exactly the hole this closes, and a silent failure on a
                // profile form is a support ticket nobody can diagnose.
                throw ValidationException::withMessages([
                    'avatar' => 'That image could not be processed. Please try a smaller or simpler JPG, PNG or GIF.',
                ]);
            }

            // Delete old avatar if exists
            if ($user->avatar) {
                Storage::disk('avatars')->delete($user->avatar);
            }

            // The extension is decided by re-reading OUR OWN output's header,
            // never by the client — the S3 adapter turns the object key's
            // extension into its ContentType, so whoever names the file decides
            // what a browser is later told it is.
            $avatarPath = 'avatars/'.Str::random(40).'.'.$clean['extension'];
            Storage::disk('avatars')->put($avatarPath, $clean['bytes']);

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
