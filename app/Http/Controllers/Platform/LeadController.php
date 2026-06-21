<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Leads inbox — contact-form submissions from the marketing site. Platform-admin
 * only (routes are in the platform admin group).
 */
class LeadController extends Controller
{
    public function index(): \Inertia\Response
    {
        return Inertia::render('Platform/Leads/Index', [
            'leads' => ContactSubmission::orderByDesc('id')->limit(500)->get()->map(fn (ContactSubmission $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'company' => $c->company,
                'email' => $c->email,
                'team_size' => $c->team_size,
                'message' => $c->message,
                'read' => (bool) $c->read_at,
                'date' => $c->created_at?->format('M j, Y g:i a'),
            ]),
            'unread' => ContactSubmission::whereNull('read_at')->count(),
        ]);
    }

    public function markRead(ContactSubmission $lead): RedirectResponse
    {
        $lead->update(['read_at' => $lead->read_at ?: now()]);

        return back();
    }

    public function destroy(ContactSubmission $lead): RedirectResponse
    {
        $lead->delete();

        return back()->with('success', 'Lead deleted.');
    }
}
