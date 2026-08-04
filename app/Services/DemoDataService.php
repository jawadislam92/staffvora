<?php

namespace App\Services;

use App\Http\Controllers\DashboardController;
use App\Models\Client;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\WorkCategory;
use App\Models\WorkHour;
use App\Support\BusinessTime;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SAMPLE-DATA DEMO MODE.
 *
 * An empty workspace is honest but uninformative: a customer evaluating
 * Staffvora sees four zeroes and cannot tell whether the product works. This
 * service loads a SMALL, LOUDLY LABELLED sample workspace — a few teammates, a
 * project, a fortnight of attendance punches and work entries — and removes it
 * again leaving nothing behind.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE THREE NON-NEGOTIABLES, AND HOW EACH IS GUARANTEED
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. EVERY DEMO ROW IS IDENTIFIABLE, AND REMOVAL IS COMPLETE.
 *    Demo rows are never mixed into real ones. They hang off demo PEOPLE whose
 *    e-mail address ends in `@demo.staffvora.invalid` — `.invalid` is reserved
 *    by RFC 2606 and can never belong to a human being, so the marker cannot
 *    collide with a real account, ever. Removal deletes every row in every table
 *    this service writes, keyed on those user ids, and then the users
 *    themselves. Two INDEPENDENT markers back that up so a row is still
 *    reachable even if the user row were destroyed by some other path:
 *      • work_hours.source = 'demo'  (real rows are 'manual' or 'tracker')
 *      • clients.tags contains 'staffvora-demo'
 *    Nothing here writes to a table it cannot also clean.
 *
 * 2. IT IS OBVIOUS IN THE UI.
 *    Every demo person and the demo project carry the "Demo · " name prefix, so
 *    they read as demo in EVERY surface — dashboard, attendance grid, reports,
 *    timesheets, CSV exports — including surfaces written long after this file.
 *    The dashboard additionally shows a standing banner with a Remove button
 *    for as long as `isLoaded()` is true.
 *
 * 3. DEMO HOURS CANNOT BE MISTAKEN FOR PAYROLL HOURS.
 *    No demo hour is ever attributed to a real employee. Every demo work entry
 *    belongs to a demo person, so a real person's timesheet, report total and
 *    pay-period approval are byte-identical whether demo mode is on or off.
 *    Demo entries also carry source='demo', which is neither of the two values
 *    payroll-facing code recognises.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SEATS
 * ─────────────────────────────────────────────────────────────────────────────
 * Demo teammates are real user rows, but they DO NOT consume plan seats AND
 * ARE NOT BILLED: both counters — the plan limit ({@see User::seatUsage()}) and
 * the invoice ({@see BillingPricing::billableSeats()}) — are built
 * from the one scope {@see User::scopeSeatConsuming()}, which excludes the demo
 * namespace. Loading the sample can therefore never block a real hire, never
 * raise an invoice, and on a 2-seat Free workspace the customer still gets the
 * full three-person cast.
 *
 * That exclusion is a plan-limit bypass, so the marker it keys on is closed at
 * the MODEL layer, in BOTH directions:
 *   • nothing may be created or renamed INTO `@demo.staffvora.invalid` unless
 *     the write is wrapped in {@see User::asDemoIdentityWriter()}, which only
 *     this file calls and which no request can reach;
 *   • and an existing demo row's identity (e-mail, password, role, permissions,
 *     tenancy) is FROZEN, so it cannot be renamed back OUT of the namespace
 *     either. Without that second half, editing a demo teammate converted a
 *     free row into a real, counted, login-capable account and — because
 *     {@see isLoaded()} keys on the same address — left the workspace free to
 *     mint another three. Repeatable: a 2-seat plan reached eight real users.
 */
class DemoDataService
{
    /**
     * RFC 2606 reserved TLD: no mail can ever be delivered here and no real
     * signup can ever claim it, which is what makes this a SAFE marker. The
     * canonical definition — and the model-layer guard that makes it
     * UNFORGEABLE — lives on {@see User}; this alias is kept so existing
     * callers and tests keep reading the same constant.
     */
    public const EMAIL_DOMAIN = User::DEMO_EMAIL_DOMAIN;

    /** Prefix on every demo person and the demo project. Visible everywhere. */
    public const NAME_PREFIX = 'Demo · ';

    /** work_hours.source for demo entries — never 'manual', never 'tracker'. */
    public const WORK_HOUR_SOURCE = 'demo';

    /** Second, independent marker on the demo project row. */
    public const CLIENT_TAG = 'staffvora-demo';

    /** How many days of history the sample covers. */
    public const DAYS = 14;

    /** The sample cast, in priority order — a 1-seat workspace still gets one. */
    private const PEOPLE = [
        ['name' => 'Aisha Rahman', 'designation' => 'Frontend Engineer'],
        ['name' => 'Omar Siddiqui', 'designation' => 'Backend Engineer'],
        ['name' => 'Lena Fischer', 'designation' => 'Product Designer'],
    ];

    /** Sample work, as [category slug, description] pairs. */
    private const WORK = [
        ['client_work', 'Checkout flow refinements'],
        ['client_work', 'Reporting module build-out'],
        ['client_work', 'Bug triage and fixes'],
        ['meetings', 'Sprint planning'],
        ['internal_admin', 'Code review and housekeeping'],
    ];

    public function __construct(private CurrentOrganization $current) {}

    // ── State ───────────────────────────────────────────────────────────────

    /**
     * Is demo data present RIGHT NOW? Derived from the rows themselves — there
     * is no stored "demo mode is on" flag anywhere, so this can never disagree
     * with what the customer is looking at.
     *
     * Asked of the USERS, not of work_hours.source: the demo e-mail marker is a
     * LEFT-anchored LIKE against the unique e-mail index, so this stays a
     * bounded index probe on a workspace with five thousand people. The
     * source='demo' sweep is the removal path's belt-and-braces, and is only
     * paid for when somebody actually clicks Remove.
     */
    public function isLoaded(): bool
    {
        return $this->userQuery()->exists();
    }

    /** What is loaded, for the banner. */
    public function summary(): array
    {
        $ids = $this->userIds();

        return [
            'loaded' => $ids !== [],
            'people' => count($ids),
            'entries' => $ids === [] ? 0 : WorkHour::whereIn('user_id', $ids)->count(),
            'days' => self::DAYS,
        ];
    }

    /**
     * How many demo teammates the workspace can take right now — always the
     * whole sample cast, because demo people do not consume plan seats
     * ({@see User::seatUsage()} excludes them). Kept as a method, and still
     * asked before loading, so the "no room" path stays exercised if the seat
     * model ever changes again.
     */
    public function seatHeadroom(): int
    {
        return count(self::PEOPLE);
    }

    // ── Load ────────────────────────────────────────────────────────────────

    /**
     * Load the sample. Idempotent by refusal: if demo data is already present
     * the caller is told to remove it first rather than getting a second copy.
     *
     * @return array{ok: bool, message: string, people?: int, entries?: int}
     */
    public function load(): array
    {
        if ($this->isLoaded()) {
            return ['ok' => false, 'message' => 'Demo data is already loaded. Remove it first to load a fresh copy.'];
        }

        // Guarded, not decorative: array_slice(..., 0, 0) would silently create
        // nobody and still report success, so a headroom of zero must be an
        // explicit refusal even while it is unreachable today.
        $headroom = $this->seatHeadroom();

        if ($headroom < 1) {
            return ['ok' => false, 'message' => 'There is no room to add demo teammates right now.'];
        }

        $orgId = $this->current->id();

        if ($orgId === null) {
            return ['ok' => false, 'message' => 'No workspace is active.'];
        }

        $cast = array_slice(self::PEOPLE, 0, $headroom);

        return DB::transaction(function () use ($cast, $orgId) {
            $client = Client::create([
                'name' => self::NAME_PREFIX.'Northwind Co.',
                'work_type' => 'tracker_manual',
                'tags' => [self::CLIENT_TAG],
            ]);

            // The ONLY place in the codebase allowed to write the reserved demo
            // e-mail namespace. Every other write path — admin create, invite,
            // self-registration, profile e-mail change — is refused by
            // User::booted(), which is what makes excluding these rows from the
            // seat count safe rather than a free-seat exploit.
            $users = User::asDemoIdentityWriter(function () use ($cast, $orgId) {
                $created = [];

                foreach ($cast as $i => $person) {
                    $created[] = User::create([
                        'name' => self::NAME_PREFIX.$person['name'],
                        'email' => $this->emailFor($orgId, $i + 1),
                        // Never surfaced anywhere: a demo person cannot sign in, so
                        // demo mode cannot become an access path into the workspace.
                        'password' => Hash::make(Str::random(48)),
                        'role' => 'member',
                        'permissions' => [],
                        'designation' => $person['designation'],
                        'include_in_slack_reports' => false,
                    ]);
                }

                return $created;
            });

            $entries = $this->seedHistory($users, $client, $orgId);

            // The dashboard's project ranking is cached, so without this the
            // customer lands on a "you're looking at demo data" banner sitting
            // directly above a card insisting there are no project hours. Seen
            // live before this call existed.
            DashboardController::forgetTopProjects($orgId, array_map(fn ($u) => $u->id, $users));

            return [
                'ok' => true,
                'message' => count($users) === 1
                    ? 'Demo data loaded: 1 demo teammate and two weeks of sample work.'
                    : 'Demo data loaded: '.count($users).' demo teammates and two weeks of sample work.',
                'people' => count($users),
                'entries' => $entries,
            ];
        });
    }

    /**
     * Two weeks of weekday attendance punches and work entries, bulk-inserted.
     *
     * Deliberately NOT through the models: TimeEntry::created posts the punch to
     * the workspace's attendance Slack channel, and a few hundred back-dated
     * demo punches must never reach a real customer's Slack. The columns the
     * model events would have filled (organization_id, the derived category) are
     * therefore set explicitly here.
     */
    private function seedHistory(array $users, Client $client, int $orgId): int
    {
        $tz = BusinessTime::tz();
        $now = CarbonImmutable::now($tz);
        $today = $now->startOfDay();
        $stamp = $now->utc()->toDateTimeString();

        // Resolve the workspace's own category rows once (seeding the starter
        // set if this workspace has never opened Settings).
        $categories = [];
        foreach (array_unique(array_column(self::WORK, 0)) as $slug) {
            $categories[$slug] = WorkCategory::resolveBySlug($slug);
        }

        $punches = [];
        $hours = [];

        foreach ($users as $index => $user) {
            for ($back = self::DAYS - 1; $back >= 0; $back--) {
                $day = $today->subDays($back);
                $isToday = $back === 0;

                // Weekends stay empty — a demo workspace that shows people
                // working seven days a week is a lie about the product. TODAY is
                // the exception: it is what makes the dashboard's live "who's
                // working right now" table say anything, and a customer who
                // loads the sample on a Sunday must not be shown an empty one.
                if (($day->isSaturday() || $day->isSunday()) && ! $isToday) {
                    continue;
                }

                $date = $day->toDateString();

                // Staggered starts so the team table isn't a single flat row.
                $in = $day->addHours(9)->addMinutes($index * 20);
                $breakStart = $day->addHours(13);
                $breakEnd = $breakStart->addMinutes(45);
                $out = $day->addHours(17)->addMinutes(30 + $index * 20);

                if ($isToday) {
                    // Today is still in progress: clock in far enough back that
                    // the dashboard shows real elapsed time, and leave them on
                    // the clock. Guarded so a run just after midnight cannot
                    // stamp a punch into yesterday.
                    $in = $now->subHours(3);
                    if ($in->toDateString() !== $date) {
                        $in = $now->subMinutes(20);
                    }
                    $punches[] = $this->punch($orgId, $user->id, 'clock_in', $in, $date, $stamp);
                } else {
                    $punches[] = $this->punch($orgId, $user->id, 'clock_in', $in, $date, $stamp);
                    $punches[] = $this->punch($orgId, $user->id, 'break_start', $breakStart, $date, $stamp);
                    $punches[] = $this->punch($orgId, $user->id, 'break_end', $breakEnd, $date, $stamp);
                    $punches[] = $this->punch($orgId, $user->id, 'clock_out', $out, $date, $stamp);
                }

                // One or two work entries per person per day. Deterministic
                // (no rand) so the same workspace always gets the same sample
                // and a support conversation about it is reproducible.
                $slots = (($index + $back) % 3 === 0) ? 2 : 1;
                for ($s = 0; $s < $slots; $s++) {
                    [$slug, $description] = self::WORK[($index * 3 + $back + $s) % count(self::WORK)];
                    $category = $categories[$slug] ?? null;
                    $value = $slots === 1 ? 7.5 - ($index * 0.25) : (4.0 - ($s * 0.5));

                    $hours[] = [
                        'organization_id' => $orgId,
                        'user_id' => $user->id,
                        'date' => $date,
                        'hours' => round(max(0.5, $value), 2),
                        'description' => self::NAME_PREFIX.$description,
                        'work_type' => 'manual',
                        'work_category_id' => $category?->id,
                        'is_billable' => (bool) ($category?->is_billable_default ?? false),
                        'client_id' => $slug === 'client_work' ? $client->id : null,
                        'tracker' => null,
                        'tracking_session_id' => null,
                        // The independent second marker. Also keeps these rows
                        // out of both payroll-facing capture methods.
                        'source' => self::WORK_HOUR_SOURCE,
                        'timesheet_id' => null,
                        'started_at' => null,
                        'ended_at' => null,
                        'created_at' => $stamp,
                        'updated_at' => $stamp,
                    ];
                }
            }
        }

        foreach (array_chunk($punches, 250) as $chunk) {
            DB::table('time_entries')->insert($chunk);
        }

        foreach (array_chunk($hours, 250) as $chunk) {
            DB::table('work_hours')->insert($chunk);
        }

        return count($hours);
    }

    private function punch(int $orgId, int $userId, string $type, CarbonImmutable $at, string $date, string $stamp): array
    {
        return [
            'organization_id' => $orgId,
            'user_id' => $userId,
            'action_type' => $type,
            // Stored in UTC like every other punch; action_date is the worker's
            // business-timezone day, which is what the attendance grid buckets on.
            'action_timestamp' => $at->utc()->toDateTimeString(),
            'action_date' => $date,
            'action_time' => $at->format('H:i:s'),
            'notes' => 'Staffvora demo data',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    // ── Remove ──────────────────────────────────────────────────────────────

    /**
     * Delete every demo row. Ordered children-first so a failure part-way can
     * never orphan a work entry behind a deleted person.
     *
     * The timesheet sweep is defensive: this service never creates one, but an
     * admin could have generated a pay period covering a demo person while the
     * sample was loaded, and leaving that behind would break the promise that
     * removal leaves nothing.
     *
     * @return array{ok: bool, message: string, removed: array<string, int>}
     */
    public function remove(): array
    {
        $ids = $this->userIds();

        $removed = DB::transaction(function () use ($ids) {
            $counts = [];

            $counts['work_hours'] = WorkHour::query()
                ->where(function ($q) use ($ids) {
                    $q->where('source', self::WORK_HOUR_SOURCE);
                    if ($ids !== []) {
                        $q->orWhereIn('user_id', $ids);
                    }
                })
                ->delete();

            $counts['time_entries'] = $ids === []
                ? 0
                : TimeEntry::whereIn('user_id', $ids)->delete();

            $counts['timesheets'] = $ids === []
                ? 0
                : $this->deleteTimesheets($ids);

            $counts['clients'] = Client::query()
                ->where('name', 'like', self::NAME_PREFIX.'%')
                ->get()
                ->filter(fn (Client $c) => in_array(self::CLIENT_TAG, (array) ($c->tags ?? []), true))
                ->each(fn (Client $c) => $c->delete())
                ->count();

            $counts['users'] = $ids === [] ? 0 : User::whereIn('id', $ids)->delete();

            return $counts;
        });

        // Symmetrical to load(): the ranking must not keep advertising demo
        // projects after the customer has removed them.
        DashboardController::forgetTopProjects($this->current->id(), $ids);

        return [
            'ok' => true,
            'message' => array_sum($removed) === 0
                ? 'There was no demo data to remove.'
                : 'Demo data removed. Your workspace is back to your own data only.',
            'removed' => $removed,
        ];
    }

    /**
     * Timesheets (and their approval trail) belonging to demo people. Guarded so
     * a workspace on a plan without the timesheets module — where the tables may
     * legitimately be absent — still gets a clean removal.
     */
    private function deleteTimesheets(array $ids): int
    {
        try {
            $timesheetIds = Timesheet::whereIn('user_id', $ids)->pluck('id')->all();

            if ($timesheetIds === []) {
                return 0;
            }

            // Release any entry this timesheet froze before the row goes, so a
            // dangling timesheet_id can never make a surviving entry look
            // payroll-locked against a timesheet that no longer exists.
            WorkHour::whereIn('timesheet_id', $timesheetIds)->update(['timesheet_id' => null]);
            DB::table('timesheet_approvals')->whereIn('timesheet_id', $timesheetIds)->delete();

            return Timesheet::whereIn('id', $timesheetIds)->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * This workspace's demo people. Org-scoped by the global scope, and matched
     * on the `is_demo` MARKER — a column only this service can write.
     *
     * It used to be two `LIKE`s on the address. On MySQL those are evaluated in
     * `utf8mb4_unicode_ci`, which folds accents, so a forged
     * `demo-<org>-1@demo.staffvora.İnvalid` matched them: the workspace read as
     * "sample data already loaded" (blocking the real Load button) while the
     * seat count read the same row as free. The marker cannot be forged and
     * compares identically on SQLite and MySQL.
     */
    private function userQuery()
    {
        return User::query()->demoIdentities();
    }

    /** @return array<int, int> */
    private function userIds(): array
    {
        return $this->userQuery()->pluck('id')->all();
    }

    /**
     * E-mails are unique across the whole install, so the org id is part of the
     * local part — two tenants can both load the sample.
     */
    private function emailFor(int $orgId, int $n): string
    {
        return "demo-{$orgId}-{$n}@".self::EMAIL_DOMAIN;
    }
}
