<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;

/**
 * Org-owner management of customizable Teams (groups of users). Distinct from
 * TeamController, which renders the "Team Performance" dashboard. Every query
 * is org-scoped by the global OrganizationScope, and route-model binding on
 * {team} 404s across tenants automatically.
 */
class TeamManagementController extends Controller
{
    public function index()
    {
        return response()->json([
            'teams' => Team::withCount('users')->orderBy('name')->get(['id', 'name', 'color']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:9'],
        ]);

        Team::create($data);

        return back()->with('success', 'Team created.');
    }

    public function update(Request $request, Team $team)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:9'],
        ]);

        $team->update($data);

        return back()->with('success', 'Team updated.');
    }

    public function destroy(Team $team)
    {
        // Detach members first so no user is left pointing at a deleted team.
        $team->users()->update(['team_id' => null]);
        $team->delete();

        return back()->with('success', 'Team removed.');
    }
}
