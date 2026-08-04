<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
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
        //
        // THE LINK ROOT COMES FROM CONFIGURATION, NOT FROM THE REQUEST.
        //
        // Everything the app mails is built from the live request root, i.e.
        // getSchemeAndHttpHost() . getBaseUrl(). TrustHosts validates the host
        // half; the base-path half is whatever Symfony assembled, and the last
        // round proved that half can be steered (X-Forwarded-Prefix: @evil.test
        // turned the real domain into URL userinfo and handed the victim, token
        // and all, to the attacker). That specific header is no longer trusted
        // and is stripped in TrustProxies — but this endpoint is the one that
        // mails a working credential to a human, so it does not rely on any
        // header-level guard being correct. For the duration of the send, the
        // root is APP_URL and nothing about the request can influence it.
        //
        // This also covers the case TrustHosts cannot: APP_ENV=local disables
        // host validation entirely, and the repo .env is local.
        $pinnedRoot = rtrim(trim((string) config('app.url')), '/');

        try {
            if ($pinnedRoot !== '') {
                URL::forceRootUrl($pinnedRoot);
                URL::forceScheme(parse_url($pinnedRoot, PHP_URL_SCHEME) ?: null);
            }

            Password::sendResetLink(
                $request->only('email')
            );
        } finally {
            if ($pinnedRoot !== '') {
                URL::forceRootUrl(null);
                URL::forceScheme(null);
            }
        }

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
