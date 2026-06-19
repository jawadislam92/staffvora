<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Services\TenantProvisioner;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-serve signup: each registration creates a whole tenant (organization +
 * owner + trial + default team), then logs the owner in. This is the net-new
 * "sellable" entry point — registration was removed from the internal tool.
 */
class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $validated = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'not_regex:/[\r\n]/'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $email = strtolower(trim($validated['email']));

        if ($provisioner->emailTaken($email)) {
            throw ValidationException::withMessages([
                'email' => 'An account with this email already exists.',
            ]);
        }

        $result = $provisioner->provision(
            orgName: $validated['company'],
            ownerName: $validated['name'],
            email: $email,
            plainPassword: $validated['password'],
        );

        event(new Registered($result['owner']));

        Auth::login($result['owner']);
        $request->session()->regenerate();

        return redirect(RouteServiceProvider::HOME);
    }
}
