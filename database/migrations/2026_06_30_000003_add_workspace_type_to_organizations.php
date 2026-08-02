<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The kind of team this workspace is. It decides which optional, audience-
     * specific features show — most importantly the Upwork "Profiles" concept,
     * which only agencies running multiple Upwork seller accounts need. New
     * workspaces default to 'general' so the product is a plain employee time
     * tracker out of the box (no "Upwork" anywhere); an agency turns it on.
     *
     * Values: general · agency · bpo · freelancer.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'workspace_type')) {
                $table->string('workspace_type', 32)->default('general')->after('timezone');
            }
        });

        // Preserve behaviour for any existing workspace that already uses Upwork
        // profiles — mark it as an agency so its Profiles module stays visible.
        if (Schema::hasTable('upwork_profiles')) {
            $orgIds = DB::table('upwork_profiles')->distinct()->pluck('organization_id')->filter();
            if ($orgIds->isNotEmpty()) {
                DB::table('organizations')->whereIn('id', $orgIds)->update(['workspace_type' => 'agency']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'workspace_type')) {
                $table->dropColumn('workspace_type');
            }
        });
    }
};
