<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Persists a user's personal theme choice (light/dark/black). The workspace
 * default lives on the Organization; this is the per-user override.
 */
class ThemeController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(['light', 'dark', 'black'])],
        ]);

        $user = $request->user();
        $user->theme = $data['theme'];
        $user->save();

        return response()->json(['ok' => true]);
    }
}
