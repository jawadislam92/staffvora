<?php

namespace App\Models;

use App\Support\BusinessTime;
use Carbon\Carbon;

/**
 * ONE pay period — a half-open-free, inclusive [start, end] pair of business
 * DAYS plus the cadence that produced it.
 *
 * DELIBERATELY NOT AN ELOQUENT MODEL. A workspace has an infinite number of pay
 * periods; materialising them would be a table that grows with the calendar and
 * answers nothing the arithmetic can't. Periods are DERIVED from two settings —
 * `monitoring_settings.pay_period_cadence` and `pay_period_anchor_date` — and
 * only the ones that actually enter the approval state machine ever get a row
 * (see App\Models\Timesheet).
 *
 * THE CADENCES
 *   weekly       7 days from the anchor.
 *   biweekly     14 days from the anchor.
 *   semimonthly  the 1st–15th and the 16th–end of each month (anchor ignored —
 *                a semi-monthly period is defined by the calendar, not a drift).
 *   monthly      the whole calendar month (anchor ignored, same reason).
 *
 * THE ANCHOR is the start date of ANY one weekly/bi-weekly period; everything
 * else is that date ± a whole number of period lengths. When it is not set it
 * falls back to the start of the epoch week under the workspace's own
 * `week_starts_on`, so a workspace that never touches the setting still gets
 * periods that begin on the day its week begins.
 *
 * SECURITY NOTE — `fromKey()` is the ONLY way a request-supplied period reaches
 * a query. It re-derives the period from the cadence and refuses any start date
 * that is not a genuine period boundary, so a client can never widen a range,
 * pick an arbitrary window, or straddle two periods to change what gets summed
 * (or locked).
 */
final class PayPeriod
{
    public const WEEKLY = 'weekly';

    public const BIWEEKLY = 'biweekly';

    public const SEMIMONTHLY = 'semimonthly';

    public const MONTHLY = 'monthly';

    /** Every cadence a workspace may choose, in the order the settings UI shows them. */
    public const CADENCES = [self::WEEKLY, self::BIWEEKLY, self::SEMIMONTHLY, self::MONTHLY];

    /** Human labels for the settings UI and the period picker. */
    public const CADENCE_LABELS = [
        self::WEEKLY => 'Weekly',
        self::BIWEEKLY => 'Bi-weekly (every 2 weeks)',
        self::SEMIMONTHLY => 'Semi-monthly (1st–15th, 16th–end)',
        self::MONTHLY => 'Monthly',
    ];

    /**
     * How many periods back a person/approver may page through. A pay period is
     * a payroll window, not an archive browser — capping it keeps every read on
     * these screens a bounded, indexed range scan.
     */
    public const MAX_HISTORY = 26;

    public function __construct(
        public readonly string $cadence,
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    // ── Reading the workspace's policy ────────────────────────────────────────

    /** The workspace's configured cadence, normalised (unknown values → weekly). */
    public static function cadence(): string
    {
        $raw = (string) (MonitoringSetting::current()->pay_period_cadence ?? self::WEEKLY);

        return in_array($raw, self::CADENCES, true) ? $raw : self::WEEKLY;
    }

    /**
     * The workspace's anchor as a business-timezone start-of-day, or null when
     * it has never been set (weekly/bi-weekly then fall back to the epoch week).
     */
    public static function anchor(): ?Carbon
    {
        $raw = MonitoringSetting::current()->pay_period_anchor_date;

        if (blank($raw)) {
            return null;
        }

        try {
            return Carbon::parse((string) (is_string($raw) ? $raw : $raw->toDateString()), BusinessTime::tz())->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The effective anchor for a rolling cadence. Absent an explicit one, the
     * start of the week containing the epoch, using the workspace's own week
     * start — so "weekly" means the workspace's week, not an arbitrary Monday.
     */
    private static function effectiveAnchor(): Carbon
    {
        return self::anchor()
            ?? Carbon::create(2024, 1, 1, 0, 0, 0, BusinessTime::tz())
                ->startOfWeek(MonitoringSetting::weekStartDay());
    }

    // ── Deriving periods ──────────────────────────────────────────────────────

    /** The period the workspace is in right now. */
    public static function current(?string $cadence = null): self
    {
        return self::containing(BusinessTime::today(), $cadence);
    }

    /**
     * The period that CONTAINS a given business day. This is the single
     * derivation everything else routes through.
     */
    public static function containing(Carbon|string|null $date, ?string $cadence = null): self
    {
        $cadence = self::normalizeCadence($cadence);
        $day = $date instanceof Carbon
            ? $date->copy()->setTimezone(BusinessTime::tz())->startOfDay()
            : BusinessTime::parseDate($date === null ? null : (string) $date);

        return match ($cadence) {
            self::MONTHLY => new self($cadence, $day->copy()->startOfMonth(), $day->copy()->endOfMonth()->startOfDay()),
            self::SEMIMONTHLY => $day->day <= 15
                ? new self($cadence, $day->copy()->startOfMonth(), $day->copy()->setDay(15))
                : new self($cadence, $day->copy()->setDay(16), $day->copy()->endOfMonth()->startOfDay()),
            default => self::rolling($cadence, $day),
        };
    }

    /** Weekly / bi-weekly: whole multiples of the period length from the anchor. */
    private static function rolling(string $cadence, Carbon $day): self
    {
        $length = $cadence === self::BIWEEKLY ? 14 : 7;
        $anchor = self::effectiveAnchor();

        // intdiv() truncates toward zero, which would put days BEFORE the anchor
        // in the wrong bucket — floor division is what tiles the whole line.
        $delta = (int) $anchor->diffInDays($day, false);
        $index = (int) floor($delta / $length);

        $start = $anchor->copy()->addDays($index * $length);

        return new self($cadence, $start, $start->copy()->addDays($length - 1));
    }

    /**
     * Rebuild a period from a client-supplied "<cadence>:<Y-m-d>" key, or null
     * when the key is malformed, names an unknown cadence, or names a date that
     * is NOT a real period boundary for that cadence. Never trust the caller's
     * range: this re-derives it and compares.
     */
    public static function fromKey(?string $key): ?self
    {
        if (! is_string($key) || ! str_contains($key, ':')) {
            return null;
        }

        [$cadence, $date] = explode(':', $key, 2);

        if (! in_array($cadence, self::CADENCES, true) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            $start = Carbon::createFromFormat('Y-m-d', $date, BusinessTime::tz())->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }

        $period = self::containing($start, $cadence);

        // The named day must be the period's own first day — otherwise the key
        // was invented rather than derived.
        return $period->start->toDateString() === $start->toDateString() ? $period : null;
    }

    /**
     * The most recent $count periods, newest first, ending with the one
     * containing today. Bounded by MAX_HISTORY.
     */
    public static function recent(int $count = 8, ?string $cadence = null): array
    {
        $count = max(1, min($count, self::MAX_HISTORY));
        $period = self::current($cadence);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = $period;
            $period = $period->previous();
        }

        return $out;
    }

    private static function normalizeCadence(?string $cadence): string
    {
        return in_array($cadence, self::CADENCES, true) ? $cadence : self::cadence();
    }

    // ── Navigation ────────────────────────────────────────────────────────────

    public function previous(): self
    {
        return self::containing($this->start->copy()->subDay(), $this->cadence);
    }

    public function next(): self
    {
        return self::containing($this->end->copy()->addDay(), $this->cadence);
    }

    // ── Identity + presentation ───────────────────────────────────────────────

    /** The stable id used in URLs, timesheet rows and the period picker. */
    public function key(): string
    {
        return $this->cadence.':'.$this->start->toDateString();
    }

    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    public function endDate(): string
    {
        return $this->end->toDateString();
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /** True once the last day of the period is behind the workspace's today. */
    public function hasEnded(): bool
    {
        return $this->end->lessThan(BusinessTime::today());
    }

    /** True when the period has not started yet — nothing to submit. */
    public function isFuture(): bool
    {
        return $this->start->greaterThan(BusinessTime::today());
    }

    public function isCurrent(): bool
    {
        return ! $this->hasEnded() && ! $this->isFuture();
    }

    /** "27 Jul – 2 Aug 2026", or "August 2026" for a whole calendar month. */
    public function label(): string
    {
        if ($this->cadence === self::MONTHLY) {
            return $this->start->format('F Y');
        }

        $sameYear = $this->start->year === $this->end->year;
        $left = $sameYear ? $this->start->format('j M') : $this->start->format('j M Y');

        return $left.' – '.$this->end->format('j M Y');
    }

    /** @return array<string, mixed> The shape every page prop uses. */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'cadence' => $this->cadence,
            'cadence_label' => self::CADENCE_LABELS[$this->cadence] ?? $this->cadence,
            'start' => $this->startDate(),
            'end' => $this->endDate(),
            'label' => $this->label(),
            'days' => $this->days(),
            'is_current' => $this->isCurrent(),
            'has_ended' => $this->hasEnded(),
        ];
    }
}
