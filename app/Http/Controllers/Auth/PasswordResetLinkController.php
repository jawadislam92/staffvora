<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'not_regex:/[\r\n]/', 'email'],
        ]);

        // Send the link when the account exists. The broker's own per-address
        // throttle still applies, so this can't be used to mail-bomb someone.
        Password::sendResetLink(
            $request->only('email')
        );

        // NEUTRAL BY DESIGN — do NOT restore the "we can't find a user with
        // that email address" branch.
        //
        // This endpoint is anonymous, and email addresses are globally unique
        // across the whole install, so a distinguishable response answers
        // "does this person use Staffvora?" for ANY address on earth — across
        // every tenant, at whatever rate the route throttle allows. The same
        // single answer is returned for a real account, an unknown address and
        // a broker-throttled retry. The reset mail is still genuinely sent when
        // the account exists; only what we TELL the caller changed.
        //
        // The sibling Slack-code flow (PasswordResetCodeController) already
        // answers this way, with this same "if that account exists" wording;
        // keep the two consistent, and don't claim we emailed someone we didn't.
        return back()->with('status', 'If that account exists, a password reset link is on its way.');
    }
}
