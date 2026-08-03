<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\TimeEntry;
use App\Models\TrackingSession;
use App\Models\User;
use App\Models\WorkCategory;

/**
 * THE "GET STARTED" CHECKLIST.
 *
 * Four things a new workspace has to do before Staffvora tells it anything
 * useful: get people in, get the tracker on their machines, tell the product
 * how the team works, and take the first punch.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EVERY DONE-STATE IS DERIVED, NOT STORED
 * ─────────────────────────────────────────────────────────────────────────────
 * There is no `onboarding_steps` column and no per-step flag, deliberately. A
 * stored flag drifts: a workspace that removes its last teammate, or uninstalls
 * the tracker, or has its data wiped, would keep a green tick it no longer
 * deserves — and the customer would rightly stop believing the rest of the UI.
 * Each step here asks the database the question it is actually claiming to
 * answer, every time. Cost is four EXISTS probes, each on an indexed column,
 * and only for people who could act on the answer.
 *
 * DISMISSAL is the one thing that IS remembered, and it is remembered in the
 * browser (per person, per workspace). That is legitimate: dismissal is a
 * statement about what someone wants to look at, not a claim about the data —
 * so it cannot lie about progress. Re-opening it is one click away, and the
 * card comes back on its own if it was never completed and the browser storage
 * is cleared.
 */
class GettingStartedChecklist
{
    /**
     * @return array{steps: array<int, array<string, mixed>>, done: int, total: int, complete: bool}
     */
    public function build(User $user): array
    {
        $steps = [
            [
                'key' => 'invite_team',
                'title' => 'Invite your team',
                'description' => 'Add the people whose time you want to track. Everyone gets their own login.',
                'cta' => 'Invite people',
                'route' => 'users.create',
                'permission' => 'users.manage',
                'done' => $this->hasTeammates($user),
            ],
            [
                'key' => 'install_desktop',
                'title' => 'Install the desktop app',
                'description' => 'The desktop tracker records hours and activity on each computer. Optional, but it is where the richest data comes from.',
                'cta' => 'Get the installer',
                'route' => 'desktop-downloads.index',
                'permission' => null,
                'done' => $this->hasDesktopActivity(),
            ],
            [
                'key' => 'configure_work',
                'title' => 'Set your shifts and work categories',
                'description' => 'Shifts tell Staffvora when people are expected in; work categories are the words your reports are grouped by.',
                'cta' => 'Open settings',
                'route' => 'settings.work-categories',
                'permission' => 'monitoring.settings',
                'done' => $this->hasConfiguredWork(),
            ],
            [
                'key' => 'first_clock_in',
                'title' => 'Clock in for the first time',
                'description' => 'Use the clock control in the top bar. The first punch is what turns the dashboard, attendance and reports on.',
                'cta' => 'Go to attendance',
                'route' => 'employee-attendance.index',
                'permission' => 'attendance.view',
                'done' => $this->hasClockIn(),
            ],
        ];

        // A step's BUTTON is only offered to somebody the destination would
        // actually let in — the same permission the route itself enforces. An
        // onboarding checklist that hands you a 403 is worse than no checklist.
        $steps = array_map(function (array $step) use ($user) {
            $step['can'] = $step['permission'] === null || $user->hasPermission($step['permission']);
            unset($step['permission']);

            return $step;
        }, $steps);

        $done = count(array_filter($steps, fn ($s) => $s['done']));

        return [
            'steps' => $steps,
            'done' => $done,
            'total' => count($steps),
            'complete' => $done === count($steps),
        ];
    }

    /** Somebody other than the person asking is in the workspace. */
    private function hasTeammates(User $user): bool
    {
        return User::query()->where('id', '!=', $user->id)->exists();
    }

    /**
     * The desktop app has actually reported in — a registered machine, or a
     * tracking session it opened. Asked of the evidence the app leaves behind,
     * never of an "I installed it" button, because that button is exactly the
     * kind of claim that goes stale.
     */
    private function hasDesktopActivity(): bool
    {
        return Machine::query()->exists() || TrackingSession::query()->exists();
    }

    /**
     * The workspace has shaped its own working model: at least one person is on
     * a shift, or the work-category list has been changed from the starter set
     * this product ships (a category added, or a starter one renamed).
     *
     * The starter categories are seeded automatically, so their bare existence
     * proves nothing and must not tick this step.
     */
    private function hasConfiguredWork(): bool
    {
        if (User::query()->whereNotNull('shift_id')->exists()) {
            return true;
        }

        if (User::query()->whereNotNull('shift_start_time')->exists()) {
            return true;
        }

        $defaults = [];
        foreach (array_merge(WorkCategory::GENERAL_DEFAULTS, WorkCategory::AGENCY_EXTRA_DEFAULTS) as $definition) {
            $defaults[$definition['slug']] = $definition['name'];
        }

        foreach (WorkCategory::query()->get(['slug', 'name']) as $category) {
            if (! array_key_exists($category->slug, $defaults)) {
                return true; // A category this workspace invented.
            }

            if ($defaults[$category->slug] !== $category->name) {
                return true; // A starter category this workspace renamed.
            }
        }

        return false;
    }

    /** Anybody has ever clocked in. */
    private function hasClockIn(): bool
    {
        return TimeEntry::query()->where('action_type', 'clock_in')->exists();
    }
}
