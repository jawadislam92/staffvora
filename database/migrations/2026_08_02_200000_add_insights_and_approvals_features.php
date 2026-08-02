<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two new feature keys for the editable catalogue, so the owner can move both
 * between plans from the operator panel without a deploy.
 *
 *  • `insights`            — Insights v1. Priced onto Growth + Enterprise,
 *                            matching the published matrix, where "Team
 *                            performance insights" already sits on Growth+.
 *  • `timesheet_approvals` — Payroll sign-off. Deliberately granted on EVERY
 *                            plan for now. It is a strong paid-tier
 *                            differentiator (Hubstaff gates it on Grow+), but
 *                            that is a commercial decision the owner has not
 *                            made, and shipping a gate that silently removes a
 *                            capability is worse than shipping it open. The KEY
 *                            exists and the route is gated by it, so restricting
 *                            it later is a tick-box in the panel, not a deploy.
 *
 * Both default to FALSE in the catalogue (the default-deny contract): a plan
 * grants them explicitly, so a plan created later starts closed.
 */
return new class extends Migration
{
    private const FEATURES = [
        [
            'key' => 'insights',
            'kind' => 'flag',
            'group' => 'Features',
            'default_value' => 'false',
            'sort' => 150,
            'label' => 'Insights analytics',
            'help' => 'Utilization against each person\'s shift target, the team activity gauge, billable vs non-billable hours, hours by work category, the worked-hours trend and derived highlights.',
        ],
        [
            'key' => 'timesheet_approvals',
            'kind' => 'flag',
            'group' => 'Features',
            'default_value' => 'false',
            'sort' => 160,
            'label' => 'Timesheet approvals',
            'help' => 'Pay periods, submit-and-approve timesheets, and the payroll lock that freezes an approved period\'s time entries against further edits.',
        ],
    ];

    /** Which plans switch each key on. Placeholder positioning — editable in the panel. */
    private const GRANTS = [
        'insights' => ['pro', 'enterprise'],
        'timesheet_approvals' => ['free', 'starter', 'pro', 'enterprise'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }

        $now = now();

        foreach (self::FEATURES as $feature) {
            DB::table('features')->updateOrInsert(
                ['key' => $feature['key']],
                $feature + ['is_active' => true, 'unit' => null, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        if (! Schema::hasTable('plans')) {
            return;
        }

        foreach (self::GRANTS as $key => $planKeys) {
            foreach ($planKeys as $planKey) {
                $plan = DB::table('plans')->where('key', $planKey)->first();
                if (! $plan) {
                    continue;
                }

                $entitlements = json_decode($plan->entitlements ?? '{}', true) ?: [];
                $entitlements[$key] = true;

                DB::table('plans')->where('id', $plan->id)
                    ->update(['entitlements' => json_encode($entitlements), 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('features')) {
            DB::table('features')->whereIn('key', array_column(self::FEATURES, 'key'))->delete();
        }

        if (! Schema::hasTable('plans')) {
            return;
        }

        foreach (DB::table('plans')->get() as $plan) {
            $entitlements = json_decode($plan->entitlements ?? '{}', true) ?: [];
            foreach (array_column(self::FEATURES, 'key') as $key) {
                unset($entitlements[$key]);
            }
            DB::table('plans')->where('id', $plan->id)
                ->update(['entitlements' => json_encode($entitlements)]);
        }
    }
};
