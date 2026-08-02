<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvitation;
use App\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Owner-only staff management for the operator portal: list staff, invite by
 * email (Admin/Support), and revoke staff or pending invites. The Owner account
 * is bootstrap-managed (php artisan platform:owner) and can't be invited or
 * deleted here.
 */
class StaffController extends Controller
{
    public function index()
    {
        $me = Auth::guard('platform')->id();

        return Inertia::render('PlatformPortal/Staff', [
            'staff' => PlatformUser::orderBy('name')->get()
                ->sortByDesc(fn (PlatformUser $u) => $u->rank())
                ->values()
                ->map(fn (PlatformUser $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'last_login_at' => $u->last_login_at?->diffForHumans(),
                    'is_self' => $u->id === $me,
                ]),
            'invitations' => PlatformInvitation::whereNull('accepted_at')->latest()->get()
                ->filter->isPending()
                ->map(fn (PlatformInvitation $i) => [
                    'id' => $i->id,
                    'email' => $i->email,
                    'role' => $i->role,
                    'accept_url' => $i->acceptUrl(),
                    'expires_at' => $i->expires_at?->toDayDateTimeString(),
                ])->values(),
            'roles' => ['admin', 'support'],
        ]);
    }

    public function invite(Request $request)
    {
        $data = $request->validate([
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('platform_users', 'email'),
            ],
            'role' => ['required', Rule::in(['admin', 'support'])],
        ]);

        // Replace any earlier pending invite for the same address.
        PlatformInvitation::where('email', $data['email'])->whereNull('accepted_at')->delete();

        $invite = PlatformInvitation::mint($data['email'], $data['role'], Auth::guard('platform')->id());

        // MAIL is log-driver in prod, so the link is also surfaced in the UI. Once
        // real SMTP is configured this delivers a proper invitation email.
        try {
            Mail::raw(
                "You've been invited to the Staffvora operator portal as {$invite->role}.\n\n".
                "Accept and set your password:\n{$invite->acceptUrl()}\n\nThis link expires in 7 days.",
                fn ($m) => $m->to($invite->email)->subject('Your Staffvora operator invitation')
            );
        } catch (\Throwable $e) {
            // Non-fatal — the Owner can still copy the link from the Staff page.
        }

        return back()->with('success', "Invitation created for {$invite->email}. Share the link below.");
    }

    public function revoke(PlatformUser $platformUser)
    {
        abort_if($platformUser->id === Auth::guard('platform')->id(), 403, 'You cannot remove your own account.');
        abort_if($platformUser->isOwner(), 403, 'Owner accounts are managed from the server.');

        $platformUser->delete();

        return back()->with('success', "Removed {$platformUser->name}.");
    }

    public function revokeInvitation(PlatformInvitation $invitation)
    {
        $invitation->delete();

        return back()->with('success', 'Invitation revoked.');
    }
}
