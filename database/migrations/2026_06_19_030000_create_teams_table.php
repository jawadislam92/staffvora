<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customizable teams within an organization. An org owner can rename, add, and
 * remove teams freely; users belong to at most one team. Org-scoped like every
 * other tenant table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name');
            $table->string('color', 9)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'team_id')) {
                $table->unsignedBigInteger('team_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'team_id')) {
                $table->dropColumn('team_id');
            }
        });
        Schema::dropIfExists('teams');
    }
};
