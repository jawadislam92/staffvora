<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\DemoDataService;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        // Respect the plan's seat limit (null = unlimited). Sample-data
        // teammates are excluded from the count (see User::seatUsage) so a
        // loaded demo can never block a real invite; the reserved demo e-mail
        // namespace is refused at the model layer, so the validated address
        // above cannot imitate one to win a free seat.
        $seatLimit = $this->organization()?->seatLimit();
        if ($seatLimit !== null && User::seatUsage() >= $seatLimit) {
            throw ValidationException::withMessages([
                'email' => "Your plan includes up to {$seatLimit} users. Upgrade to add more.",
            ]);
        }

        // No tenant email-invite pipeline exists yet, and member accounts are
        // admin-provisioned (see UserController). So we mint a strong temporary
        // password and surface it once for the owner to hand over.
        $tempPassword = Str::password(14);

        User::create([
            'name' => $validated['name'],
            'email' => strtolower(trim($validated['email'])),
            'password' => Hash::make($tempPassword),
            'role' => 'member',
            'permissions' => [],
        ]);

        return back()->with('invited', [
            'name' => $validated['name'],
            'email' => strtolower(trim($validated['email'])),
            'password' => $tempPassword,
        ]);
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

        return $id ? \App\Models\Organization::find($id) : null;
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
