<?php

namespace App\Http\Controllers;

use App\Models\UpworkProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UpworkProfileController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $profiles = UpworkProfile::orderBy('name')->get();

        return Inertia::render('UpworkProfiles/Index', [
            'profiles' => $profiles,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('UpworkProfiles/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:upwork_profiles,name',
            'email' => ['nullable', 'not_regex:/[\r\n]/', 'email', 'max:255'],
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        UpworkProfile::create($validated);

        return redirect()->route('upwork-profiles.index')
            ->with('success', 'Upwork profile created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(UpworkProfile $upworkProfile)
    {
        return Inertia::render('UpworkProfiles/Show', [
            'profile' => $upworkProfile->load('workHours'),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(UpworkProfile $upworkProfile)
    {
        return Inertia::render('UpworkProfiles/Edit', [
            'profile' => $upworkProfile,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, UpworkProfile $upworkProfile)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:upwork_profiles,name,'.$upworkProfile->id,
            'email' => ['nullable', 'not_regex:/[\r\n]/', 'email', 'max:255'],
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $upworkProfile->update($validated);

        return redirect()->route('upwork-profiles.index')
            ->with('success', 'Upwork profile updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(UpworkProfile $upworkProfile)
    {
        $upworkProfile->delete();

        return redirect()->route('upwork-profiles.index')
            ->with('success', 'Upwork profile deleted successfully.');
    }
}
