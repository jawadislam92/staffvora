<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The database queue backing store.
 *
 * `failed_jobs` has existed since the framework skeleton, but `jobs` never did —
 * which meant `QUEUE_CONNECTION=database` could not be switched on at all: every
 * dispatch would have thrown "no such table: jobs". That is why the whole app ran
 * on the `sync` driver, and why `GenerateScreenshotThumbnail` (a 39-320 ms GD
 * decode/resize costing 28-208 MB, measured) executed inside the desktop agent's
 * screenshot upload request instead of on a worker.
 *
 * Standard Laravel shape. No db-level foreign keys (house rule), guarded by
 * hasTable so re-running is safe, and identical on SQLite and MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index('jobs_queue_idx');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
