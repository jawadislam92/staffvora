<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Named shifts + per-day shift overrides.
 *
 * `shifts`
 *   A curated, admin-managed list of shift NAMES (Morning / Noon / Evening /
 *   Night / …) that a user is assigned to via users.shift_id (pick-only, so no
 *   typos). In the single-tenant source app a shift was a pure LABEL and all
 *   timing lived on the user. Here the shift may ALSO carry the timing
 *   (start_time / hours / grace_minutes), all nullable: when a shift defines a
 *   start time it becomes the source of truth for that person's schedule, and
 *   the existing per-user `shift_start_time` column stays the fallback. The
 *   seeded defaults deliberately carry NO timing, so seeding + backfilling is
 *   behaviour-neutral for every existing row.
 *
 * `shift_overrides`
 *   A one-day shift change for a single user — "on Friday I start at 8am for 6h
 *   because I'm off Saturday". One row per user per date; absent columns fall
 *   back to the standing shift. It only changes the SCHEDULE that day (which
 *   attendance day a punch buckets to, and when a forgotten clock-out
 *   auto-closes). It never creates clock-ins or tracked time, so it cannot
 *   inflate hours. {@see App\Models\User::effectiveShiftFor()} resolves the two.
 *
 * TENANCY: both tables are tenant-owned — nullable, indexed organization_id and
 * the OrganizationScope does the filtering (never hardcode an id). Shift names
 * are therefore unique PER ORGANIZATION (enforced in the controller against the
 * scoped query), not globally, so two workspaces can both have a "Morning".
 *
 * CONVENTION: no DB-level foreign keys — plain unsignedBigInteger + index, so
 * the schema is identical on SQLite (local) and MySQL (prod). Deleting a shift
 * clears users.shift_id in application code (see UserController@destroyShift).
 * Everything is wrapped in hasTable/hasColumn guards so re-running is a no-op.
 */
return new class extends Migration
{
    /** Seeded starter shifts, in display order. Intentionally timing-free. */
    private const DEFAULT_SHIFTS = ['Morning', 'Noon', 'Evening', 'Night'];

    public function up(): void
    {
        if (! Schema::hasTable('shifts')) {
            Schema::create('shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->string('name');
                // Optional timing. When present these win over the per-user
                // columns; when null the user's own shift_start_time /
                // shift_hours / shift_grace_minutes keep working unchanged.
                $table->time('start_time')->nullable();
                $table->decimal('hours', 4, 2)->nullable();
                $table->unsignedInteger('grace_minutes')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['organization_id', 'sort_order'], 'shifts_org_order_idx');
            });
        }

        if (! Schema::hasTable('shift_overrides')) {
            Schema::create('shift_overrides', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->date('date');
                $table->time('shift_start_time')->nullable();
                $table->decimal('shift_hours', 4, 2)->nullable();
                $table->string('reason')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();

                // One change per person per day (users are globally unique ids,
                // so this is safe across tenants).
                $table->unique(['user_id', 'date'], 'shift_overrides_user_date_unique');
            });
        }

        if (! Schema::hasColumn('users', 'shift_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('shift_id')->nullable()->index();
            });
        }

        $this->seedDefaultShifts();
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'shift_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['shift_id']);
                $table->dropColumn('shift_id');
            });
        }

        Schema::dropIfExists('shift_overrides');
        Schema::dropIfExists('shifts');
    }

    /**
     * Give every EXISTING workspace the four starter shifts and file each user
     * under the one matching their current shift start hour. Because the seeded
     * shifts carry no timing, this changes no attendance bucketing whatsoever —
     * it only pre-fills the label so admins have something to refine. Workspaces
     * created later get theirs lazily via Shift::ensureSeeded().
     */
    private function seedDefaultShifts(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        $bandName = function (int $hour): string {
            if ($hour >= 5 && $hour <= 11) {
                return 'Morning';
            }
            if ($hour >= 12 && $hour <= 15) {
                return 'Noon';
            }
            if ($hour >= 16 && $hour <= 20) {
                return 'Evening';
            }

            return 'Night'; // 21–23 and 00–04
        };

        foreach (DB::table('organizations')->orderBy('id')->pluck('id') as $organizationId) {
            foreach (self::DEFAULT_SHIFTS as $index => $name) {
                $exists = DB::table('shifts')
                    ->where('organization_id', $organizationId)
                    ->where('name', $name)
                    ->exists();

                if (! $exists) {
                    DB::table('shifts')->insert([
                        'organization_id' => $organizationId,
                        'name' => $name,
                        'sort_order' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $shiftIdByName = DB::table('shifts')
                ->where('organization_id', $organizationId)
                ->pluck('id', 'name');

            DB::table('users')
                ->where('organization_id', $organizationId)
                ->whereNull('shift_id')
                ->whereNotNull('shift_start_time')
                ->select('id', 'shift_start_time')
                ->orderBy('id')
                ->chunk(200, function ($users) use ($shiftIdByName, $bandName) {
                    foreach ($users as $user) {
                        $hour = (int) substr((string) $user->shift_start_time, 0, 2);
                        $shiftId = $shiftIdByName[$bandName($hour)] ?? null;

                        if ($shiftId) {
                            DB::table('users')->where('id', $user->id)->update(['shift_id' => $shiftId]);
                        }
                    }
                });
        }
    }
};
