<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvitation;
use App\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * Guest-facing accept flow for an operator invitation. Authorized purely by the
 * single-use token in the URL — no login required to land here (the invitee has
 * no account yet); accepting creates their PlatformUser and signs them in.
 */
class InvitationController extends Controller
{
    public function show(string $token)
    {
        $invite = PlatformInvitation::where('token', $token)->first();

        if (! $invite || ! $invite->isPending()) {
            return redirect()->route('platform.login')
                ->with('error', 'That invitation link is invalid or has expired.');
        }

        return Inertia::render('PlatformAuth/AcceptInvite', [
            'token' => $token,
            'email' => $invite->email,
            'role' => $invite->role,
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $invite = PlatformInvitation::where('token', $token)->first();

        if (! $invite || ! $invite->isPending()) {
            return redirect()->route('platform.login')
                ->with('error', 'That invitation link is invalid or has expired.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        // Guard the race where the email was claimed between mint and accept.
        if (PlatformUser::where('email', $invite->email)->exists()) {
            return redirect()->route('platform.login')
                ->with('error', 'An account already exists for this email. Try signing in.');
        }

        $user = PlatformUser::create([
            'name' => $data['name'],
            'email' => $invite->email,
            'password' => $data['password'], // hashed by the cast
            'role' => $invite->role,
            'invited_by' => $invite->invited_by,
        ]);

        $invite->forceFill(['accepted_at' => now()])->save();

        Auth::guard('platform')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('platform.dashboard')->with('success', 'Welcome to the operator portal.');
    }
}
