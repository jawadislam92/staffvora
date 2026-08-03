<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Marketing contact form. Emails the message to the brand support address ONLY
 * and returns to the page with a success flag the ContactBlock reads.
 * Public + throttled.
 *
 * SECURITY INVARIANT — the only recipient of anything this action sends is the
 * operator's own support address, which comes from config, never from the
 * request. It previously also auto-acknowledged the submitter, which turned a
 * public form into a spam/phishing amplifier: an anonymous caller chose the
 * recipient AND wrote the body, and the mail left staffvora.com with our
 * reputation and SPF/DKIM behind it, with no proof the address was theirs.
 * Do not reintroduce a mail whose recipient is derived from user input.
 */
class ContactController extends Controller
{
    public function submit(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            // not_regex guards the Reply-To header below against CRLF
            // injection, matching the auth controllers.
            'email' => ['required', 'not_regex:/[\r\n]/', 'email', 'max:190'],
            'team_size' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        // Persist the lead first so it's never lost, even if email fails.
        \App\Models\ContactSubmission::create($data + ['ip' => $request->ip()]);

        $to = config('brand.support_email') ?: 'hello@staffvora.com';

        try {
            Mail::raw(
                "New contact form submission\n\n".
                "Name: {$data['name']}\nCompany: ".($data['company'] ?? '-')."\n".
                "Email: {$data['email']}\nTeam size: ".($data['team_size'] ?? '-')."\n\n".
                $data['message'],
                fn ($m) => $m->to($to)->replyTo($data['email'], $data['name'])->subject('Contact form — '.config('brand.name', 'Staffvora'))
            );
        } catch (\Throwable $e) {
            // Non-fatal: still confirm to the visitor; the message is logged.
        }

        // NO auto-acknowledgement — see the class docblock. The on-page
        // confirmation below is the visitor's receipt. If an acknowledgement
        // email is ever wanted back, it needs a verified-address step first
        // (double opt-in), not a mail to whatever was typed into the box.

        return back()->with('success', 'Thanks — we will get back to you shortly.');
    }
}
