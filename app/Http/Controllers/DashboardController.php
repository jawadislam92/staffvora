<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The live dashboard fetches its attendance and tracking summary from the
     * dedicated time-entry endpoints. Keeping this initial response lean avoids
     * calculating an unused legacy analytics payload on every page load.
     */
    public function index(): Response
    {
        $user = auth()->user();

        return Inertia::render('Dashboard', [
            'needsOnboarding' => $user->isSuperAdmin()
                && (bool) $user->organization?->needsOnboarding(),
        ]);
    }
}
