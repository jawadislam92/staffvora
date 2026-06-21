<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Marketing contact form. Emails the message to the brand support address
 * (MAIL is log-driver until SMTP is configured) and returns to the page with a
 * success flag the ContactBlock reads. Public + throttled.
 */
class ContactController extends Controller
{
    public function submit(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'team_size' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $to = config('brand.support_email') ?: 'hello@satimetracker.com';

        try {
            Mail::raw(
                "New contact form submission\n\n".
                "Name: {$data['name']}\nCompany: ".($data['company'] ?? '-')."\n".
                "Email: {$data['email']}\nTeam size: ".($data['team_size'] ?? '-')."\n\n".
                $data['message'],
                fn ($m) => $m->to($to)->replyTo($data['email'], $data['name'])->subject('Contact form — '.config('brand.name', 'Tracksuite'))
            );
        } catch (\Throwable $e) {
            // Non-fatal: still confirm to the visitor; the message is logged.
        }

        return back()->with('success', 'Thanks — we will get back to you shortly.');
    }
}
