<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Workspaces created before the humane-attendance default was applied at
     * signup have a NULL attendance tracking-start, so the monthly grid
     * back-fills "Absent" for days before the workspace even existed. Anchor
     * each to its organization's creation date — but only where it's unset, so
     * we never override a tenant's explicit choice.
     */
    public function up(): void
    {
        $rows = DB::table('monitoring_settings')
            ->join('organizations', 'monitoring_settings.organization_id', '=', 'organizations.id')
            ->whereNull('monitoring_settings.attendance_tracking_start_date')
            ->select('monitoring_settings.id', 'organizations.created_at')
            ->get();

        foreach ($rows as $row) {
            DB::table('monitoring_settings')
                ->where('id', $row->id)
                ->update([
                    'attendance_tracking_start_date' => Carbon::parse($row->created_at)->toDateString(),
                ]);
        }
    }

    public function down(): void
    {
        // No-op: back-filled values are indistinguishable from intentional ones.
    }
};
