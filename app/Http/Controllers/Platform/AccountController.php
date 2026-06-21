<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** The signed-in operator's own account — change name + password. */
class AccountController extends Controller
{
    public function edit()
    {
        $user = Auth::guard('platform')->user();

        return Inertia::render('PlatformPortal/Account', [
            'account' => $user->only(['name', 'email', 'role']),
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::guard('platform')->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }

        $user->forceFill([
            'name' => $data['name'],
            'password' => $data['password'], // hashed by the cast
        ])->save();

        return back()->with('success', 'Account updated.');
    }
}
