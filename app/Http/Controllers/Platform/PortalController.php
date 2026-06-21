<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformUser;
use Inertia\Inertia;

/**
 * The operator portal's own pages (dashboard, later: staff management). The
 * tenant/plan/website console pages get mounted under the portal in milestone 2.
 */
class PortalController extends Controller
{
    public function dashboard(): \Inertia\Response
    {
        return Inertia::render('PlatformPortal/Dashboard', [
            'stats' => [
                'tenants' => Organization::count(),
                'staff' => PlatformUser::count(),
            ],
        ]);
    }
}
