<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\DemoDataService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run setup wizard (tracker item B1). After a self-serve signup the owner
 * lands here to: confirm the workspace type, invite the team, install the
 * desktop tracker, and create their first client/project. Only the workspace
 * owner/admin onboards — members are sent straight to the dashboard. Finishing
 * (or skipping) stamps organizations.onboarding_completed_at so the flow never
 * reappears.
 */
class OnboardingController extends Controller
{
    private const WORKSPACE_TYPES = ['general', 'agency', 'bpo', 'freelancer'];

    public function __construct(private CurrentOrganization $current) {}

    public function show(Request $request, DemoDataService $demo): Response|RedirectResponse
    {
        $user = $request->user();

        // Onboarding is a workspace-setup task: only the owner/admin runs it.
        if (! $this->canOnboard($user)) {
            return redirect()->route('dashboard');
        }

        $org = $this->organization();
        $demoLoaded = $demo->isLoaded();

        return Inertia::render('Onboarding', [
            'demoData' => [
                'loaded' => $demoLoaded,
                'seat_headroom' => $demoLoaded ? 0 : $demo->seatHeadroom(),
            ],
            'workspaceType' => $org?->workspace_type ?: 'general',
            'clientTerm' => $org?->clientTerm() ?? 'Client',
            'usesProfiles' => (bool) $org?->usesProfiles(),
            'workTypes' => Client::getWorkTypes($org),
            'progress' => $this->progress($user),
            'seats' => [
                // Real people only — a loaded demo must not read as seats spent.
                'used' => User::seatUsage(),
                'limit' => $org?->seatLimit(),
            ],
            'downloads' => $this->desktopDownloads(),
            'completed' => $org && ! $org->needsOnboarding(),
        ]);
    }

    /** Step 1 — confirm/change the workspace type (re-presets the UI). */
    public function workspaceType(Request $request): RedirectResponse
    {
        $this->authorizeOnboarding($request);

        $validated = $request->validate([
            'workspace_type' => ['required', Rule::in(self::WORKSPACE_TYPES)],
        ]);

        $org = $this->organization();
        $org->update(['workspace_type' => $validated['workspace_type']]);

        // The workspace capability/terminology prop is cached per org; bust it so
        // the nav + labels update immediately for this request and the next.
        Cache::forget('workspace.shared.'.$org->id);

        return back()->with('success', 'Workspace type saved.');
    }

    /** Step 2 — invite a teammate (creates a member account with a temp password). */
    public function invite(Request $request): RedirectResponse
    {
        $this->authorizeOnboarding($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // The sample-data namespace is refused: an invited "teammate" in it
            // would not count against the plan's seats. User::booted() is the
            // floor; this makes the form say so rather than throw.
            'email' => ['required', 'not_regex:/[\r\n]/', 'email', 'max:255', 'unique:users,email', User::reservedEmailRule()],
        ]);

        // No tenant email-invite pipeline exists yet, and member accounts are
        // admin-provisioned (see UserController). So we mint a strong temporary
        // password and surface it once for the owner to hand over.
        $tempPassword = Str::password(14);

        // Respect the plan's seat limit (null = unlimited). Sample-data
        // teammates are excluded from the count (see User::seatUsage) so a
        // loaded demo can never block a real invite; the reserved demo e-mail
        // namespace is refused at the model layer, so the validated address
        // above cannot imitate one to win a free seat.
        //
        // The check and the INSERT share one transaction and one lock: reading
        // the count and creating the row as two separate steps is the race that
        // let concurrent invites all pass the same stale count.
        $email = strtolower(trim($validated['email']));

        $invitee = User::withSeatGuard(
            $this->organization(),
            fn () => User::create([
                'name' => $validated['name'],
                'email' => $email,
                'password' => Hash::make($tempPassword),
                'role' => 'member',
                'permissions' => [],
            ]),
            'Your plan includes up to :limit users. Upgrade to add more.',
        );

        // ADDITIVE: on top of the temp-password fallback above (which always
        // happens — the account exists and the admin sees the flash message
        // below either way), also try to email the new teammate a branded
        // "set your password" link. This reuses the framework's own
        // password-reset token/broker — the same audited primitive behind
        // Auth\PasswordResetLinkController — not a new token surface.
        $this->sendInvitationEmail($invitee, $request->user());

        return back()->with('invited', [
            'name' => $validated['name'],
            'email' => $email,
            'password' => $tempPassword,
        ]);
    }

    /**
     * Best-effort branded invite email. Never allowed to fail the invite
     * itself — the account is already created and the admin already has the
     * temp-password fallback in the 'invited' flash payload by the time this
     * runs, so a broken mail transport degrades to "admin hands over the
     * temp password" rather than a 500.
     *
     * The link is built the same defensive way as
     * Auth\PasswordResetLinkController::store(): the root is pinned to the
     * configured APP_URL for the duration of the call rather than trusted
     * from the current request, so this endpoint (also mailing a working
     * credential to a human) can't be steered by a spoofed Host/forwarded
     * header either.
     */
    private function sendInvitationEmail(User $invitee, ?User $inviter): void
    {
        $pinnedRoot = rtrim(trim((string) config('app.url')), '/');

        try {
            if ($pinnedRoot !== '') {
                URL::forceRootUrl($pinnedRoot);
                URL::forceScheme(parse_url($pinnedRoot, PHP_URL_SCHEME) ?: null);
            }

            $token = Password::broker()->createToken($invitee);

            $setPasswordUrl = url(route('password.reset', [
                'token' => $token,
                'email' => $invitee->email,
            ], false));

            $invitee->notify(new TeamInvitationNotification(
                inviterName: $inviter?->name ?? 'A workspace admin',
                organizationName: $this->organization()?->name ?? 'your workspace',
                setPasswordUrl: $setPasswordUrl,
            ));
        } catch (\Throwable $e) {
            // Log the failure, never the token or URL — those are live
            // credentials for this account.
            Log::warning('Team invitation email failed to send.', [
                'user_id' => $invitee->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($pinnedRoot !== '') {
                URL::forceRootUrl(null);
                URL::forceScheme(null);
            }
        }
    }

    /** Step 4 — create the first client/project. */
    public function storeClient(Request $request): RedirectResponse
    {
        $this->authorizeOnboarding($request);
        $organization = $this->organization();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'work_type' => ['required', Rule::in(array_keys(Client::getWorkTypes($organization)))],
        ]);

        Client::create([
            'name' => $validated['name'],
            'work_type' => $validated['work_type'],
            'tags' => [],
        ]);

        return back()->with('success', 'First '.strtolower($organization?->clientTerm() ?? 'project').' created.');
    }

    /**
     * SAMPLE-DATA DEMO MODE — load.
     *
     * Same gate as the rest of the wizard (owner/admin only): this writes user
     * rows and occupies plan seats, which is not something a member may do. All
     * the guarantees — labelling, seat headroom, refusing a second copy — live
     * in {@see DemoDataService}, so the console command and this endpoint can
     * never drift apart.
     */
    public function loadDemoData(Request $request, DemoDataService $demo): RedirectResponse
    {
        $this->authorizeOnboarding($request);

        $result = $demo->load();

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** SAMPLE-DATA DEMO MODE — remove. Leaves nothing behind; see the service. */
    public function removeDemoData(Request $request, DemoDataService $demo): RedirectResponse
    {
        $this->authorizeOnboarding($request);

        $result = $demo->remove();

        return back()->with('success', $result['message']);
    }

    /** Finish (or skip) — stamp completion and head to the dashboard. */
    public function complete(Request $request): RedirectResponse
    {
        $this->authorizeOnboarding($request);

        $org = $this->organization();
        if ($org && $org->needsOnboarding()) {
            $org->update(['onboarding_completed_at' => now()]);
        }

        return redirect()->route('dashboard')->with('success', "You're all set — welcome aboard!");
    }

    private function organization()
    {
        $id = $this->current->id();

        return $id ? Organization::find($id) : null;
    }

    private function canOnboard(?User $user): bool
    {
        return $user && ($user->isSuperAdmin() || $user->hasPermission('users.manage'));
    }

    private function authorizeOnboarding(Request $request): void
    {
        abort_unless($this->canOnboard($request->user()), 403);
    }

    /** A small checklist state so the UI can show what's done. */
    private function progress(User $user): array
    {
        return [
            // Sample teammates don't count as having invited anyone — ticking
            // that step off because a demo is loaded would tell the owner a job
            // is done that isn't.
            'has_team' => User::query()
                ->excludingDemoIdentities()
                ->where('id', '!=', $user->id)
                ->exists(),
            'has_client' => Client::exists(),
        ];
    }

    /**
     * Which desktop installers are actually present.
     *
     * This used to keep its own hardcoded list of installer filenames, pinned
     * at 0.3.4, alongside a comment claiming it mirrored DesktopDownloadController
     * — which it had stopped doing. The effect was that the wizard's Download
     * buttons vanished for any build whose filename it had never heard of, i.e.
     * every future release. There is now exactly one place that decides what is
     * published, and this asks it.
     */
    private function desktopDownloads(): array
    {
        return [
            'windows' => DesktopDownloadController::windowsRelease()
                ? route('desktop-downloads.windows') : null,
            'mac' => DesktopDownloadController::macRelease()
                ? route('desktop-downloads.mac') : null,
            'page' => route('desktop-downloads.index'),
        ];
    }
}
