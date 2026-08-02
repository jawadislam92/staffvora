<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks whether a workspace has been through the first-run setup wizard.
     * NULL = the owner still needs onboarding (the freshly provisioned default);
     * a timestamp = setup was finished (or skipped). Drives the post-signup
     * redirect into /onboarding and the "finish setup" dashboard nudge.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'onboarding_completed_at')) {
                $table->timestamp('onboarding_completed_at')->nullable()->after('workspace_type');
            }
        });

        // Existing workspaces predate the wizard — treat them as already set up
        // so we never drop an established team into a first-run flow.
        DB::table('organizations')
            ->whereNull('onboarding_completed_at')
            ->update(['onboarding_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'onboarding_completed_at')) {
                $table->dropColumn('onboarding_completed_at');
            }
        });
    }
};
