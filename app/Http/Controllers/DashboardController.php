<?php

namespace App\Http\Controllers;

use App\Services\DemoDataService;
use App\Services\GettingStartedChecklist;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The live dashboard fetches its attendance and tracking summary from the
     * dedicated time-entry endpoints. Keeping this initial response lean avoids
     * calculating an unused legacy analytics payload on every page load.
     *
     * Two first-run props ride along, and ONLY for somebody who could act on
     * them (the workspace owner/admin — a member cannot invite people, install
     * software fleet-wide or load sample data, so serving them the checklist
     * would be noise and four wasted queries):
     *
     *  • `gettingStarted` — the four-step checklist, every step's done-state
     *    derived from real rows. See {@see GettingStartedChecklist}.
     *  • `demoData` — whether this workspace currently has sample data loaded,
     *    which is likewise derived, never stored. See {@see DemoDataService}.
     */
    public function index(GettingStartedChecklist $checklist, DemoDataService $demo): Response
    {
        $user = auth()->user();
        $canManage = $user->isSuperAdmin() || $user->hasPermission('users.manage');

        $props = [
            'needsOnboarding' => $user->isSuperAdmin()
                && (bool) $user->organization?->needsOnboarding(),
        ];

        if ($canManage) {
            $loaded = $demo->isLoaded();
            $steps = $checklist->build($user);

            // Seat headroom resolves the plan's entitlements, which is the most
            // expensive question on this page — so it is only asked when the
            // answer is going to be USED: when the offer to load sample data is
            // actually on screen. A finished workspace pays nothing for it.
            $offeringDemo = ! $loaded && ! $steps['complete'];

            $props['gettingStarted'] = $steps;
            $props['demoData'] = array_merge(
                $loaded ? $demo->summary() : ['loaded' => false, 'people' => 0, 'entries' => 0, 'days' => DemoDataService::DAYS],
                ['seat_headroom' => $offeringDemo ? $demo->seatHeadroom() : 0]
            );
        }

        return Inertia::render('Dashboard', $props);
    }
}
