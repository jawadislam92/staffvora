<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\RegionPricing;
use App\Services\TenantProvisioner;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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
            'workspace_type' => ['nullable', Rule::in(['general', 'agency', 'bpo', 'freelancer'])],
            // The customer's own country choice (ISO-3166-1 alpha-2). Optional,
            // and PRICING/VISIBILITY only — see the region note below.
            'country' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
        ]);

        $email = strtolower(trim($validated['email']));

        // REGION: the customer states their country; if they don't, an edge
        // header (Cloudflare & co.) may pre-fill one. There is deliberately NO
        // IP-geolocation dependency — see App\Services\RegionPricing.
        //
        // SECURITY: this value is attacker-controlled, so it may only decide
        // WHICH PUBLIC PLANS are offered and AT WHAT PRICE. It never grants a
        // capability and never reaches the entitlement resolver, so claiming a
        // country buys nothing except a different (operator-authored) price list.
        $country = RegionPricing::countryFromRequest($request);

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
            workspaceType: $validated['workspace_type'] ?? 'general',
            country: $country,
        );

        event(new Registered($result['owner']));

        Auth::login($result['owner']);
        $request->session()->regenerate();

        // Drop the new owner into the first-run setup wizard (B1) rather than a
        // cold, empty dashboard. They can skip it; it never reappears once done.
        return redirect()->route('onboarding.show');
    }
}
