<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app help / FAQ centre. Content lives in the page component (it's static
 * copy); this just renders it for any signed-in user.
 */
class HelpController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Help/Index', [
            'supportEmail' => config('billing.support_email') ?: config('brand.support_email'),
        ]);
    }
}
