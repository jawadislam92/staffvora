<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * upwork_profiles.name and designations.name were declared UNIQUE globally, long
 * before the product became multi-tenant. Under tenancy that single index is a
 * cross-tenant constraint by construction:
 *
 *  - functional denial — the first workspace to create "Main" or "Admin"
 *    permanently blocks the name for all 499 other customers, and
 *    Designation::create() (UserController) throws an unhandled QueryException
 *    when a tenant adds a designation another tenant already seeded;
 *  - existence oracle — "The name has already been taken" tells a $5 trial
 *    account that some other customer owns a profile/agency by that name.
 *
 * Both are replaced with (organization_id, name).
 *
 * Caveat kept deliberately: organization_id is nullable, and in BOTH MySQL and
 * SQLite a composite unique treats NULLs as distinct, so pre-tenancy rows with a
 * NULL organization_id (including the nine designations seeded by
 * 2026_06_10_000003 at migrate time) are exempt from the constraint. That is
 * correct here — those rows are not owned by any workspace — but it means the
 * constraint must not be relied on for un-stamped rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->scopeNameUnique('upwork_profiles', 'upwork_profiles_name_unique', 'upwork_profiles_org_name_unique');
        $this->scopeNameUnique('designations', 'designations_name_unique', 'designations_org_name_unique');
    }

    public function down(): void
    {
        // Intentionally not restoring the global unique index: re-adding it would
        // fail the moment two workspaces legitimately share a name, which is the
        // whole point of this migration. Only the composite is dropped.
        $this->dropIndexIfExists('upwork_profiles', 'upwork_profiles_org_name_unique');
        $this->dropIndexIfExists('designations', 'designations_org_name_unique');
    }

    private function scopeNameUnique(string $table, string $globalIndex, string $compositeIndex): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
            return;
        }

        $this->dropIndexIfExists($table, $globalIndex);

        // De-duplicate before indexing, or the CREATE UNIQUE INDEX itself fails.
        // Only same-org duplicates can exist here, and only if the global index
        // was already absent in this environment.
        $this->deduplicate($table);

        if (! Schema::hasIndex($table, $compositeIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($compositeIndex) {
                $blueprint->unique(['organization_id', 'name'], $compositeIndex);
            });
        }
    }

    private function deduplicate(string $table): void
    {
        $duplicates = DB::table($table)
            ->select('organization_id', 'name', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->whereNotNull('organization_id')
            ->groupBy('organization_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            // Rename the losers rather than delete them — this is customer data.
            // Done row by row so the suffix expression stays driver-neutral
            // (MySQL has no `||` concatenation in ANSI-off mode).
            $losers = DB::table($table)
                ->where('organization_id', $duplicate->organization_id)
                ->where('name', $duplicate->name)
                ->where('id', '!=', $duplicate->keep_id)
                ->pluck('id');

            foreach ($losers as $id) {
                DB::table($table)->where('id', $id)->update([
                    'name' => mb_substr($duplicate->name.' ('.$id.')', 0, 255),
                ]);
            }
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name) {
            $blueprint->dropUnique($name);
        });
    }
};
