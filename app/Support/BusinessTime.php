<?php

namespace App\Support;

use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Carbon\Carbon;

/**
 * Timezone + day-bucketing helpers. There are THREE distinct zones in play and
 * keeping them apart is the whole point of this class:
 *
 *   1. STORAGE timezone — config('app.timezone'), now UTC. Every datetime is
 *      persisted here (neutral, so the same instant means the same thing for
 *      every tenant). Never used for "what day is this".
 *   2. BUSINESS timezone — tz(): the ACTIVE organization's operating zone
 *      (organizations.timezone). Day boundaries, shift times, attendance,
 *      auto-clock-out and "today" are all computed here, so a New York workspace
 *      and a Karachi workspace each bucket their own work correctly.
 *   3. DISPLAY timezone — per viewer (user_monitoring_settings.display_timezone),
 *      applied only when RENDERING (see resources/js/lib/datetime.js). Not here.
 *
 * Centralised so Timeline, Team, attendance and the desktop endpoints can never
 * drift apart again.
 */
class BusinessTime
{
    /** Cache of organization_id => business timezone, to avoid per-call lookups. */
    private static array $tzCache = [];

    /** The storage timezone — the zone every datetime is persisted in (UTC). */
    public static function storageTz(): string
    {
        return config('app.timezone', 'UTC');
    }

    /**
     * The BUSINESS timezone of the active organization (day/shift/attendance
     * bucketing). Falls back to the storage zone when no tenant is bound (guest
     * / generic console context), where day-bucketing is not meaningful anyway.
     */
    public static function tz(): string
    {
        $id = app(CurrentOrganization::class)->id();

        if ($id === null) {
            // No bound tenant (a guest request, or a console context not running
            // under tenant:run). Day-bucketing is not meaningful here, so fall back
            // to the storage zone.
            return static::storageTz();
        }

        if (! array_key_exists($id, static::$tzCache)) {
            $org = Organization::find($id);
            static::$tzCache[$id] = $org?->businessTimezone() ?? static::storageTz();
        }

        return static::$tzCache[$id];
    }

    /** Clear the per-org timezone cache (call after changing an org's timezone, and in tests). */
    public static function flushTzCache(): void
    {
        static::$tzCache = [];
    }

    /** "Now" in the active organization's business timezone. */
    public static function now(): Carbon
    {
        return Carbon::now(static::tz());
    }

    /** "Today" (start of day) in the active organization's business timezone. */
    public static function today(): Carbon
    {
        return Carbon::today(static::tz());
    }

    /** Parse a business-day date string (Y-m-d) as start-of-day in the business timezone. */
    public static function parseDate(?string $raw): Carbon
    {
        try {
            return $raw
                ? Carbon::parse($raw, static::tz())->startOfDay()
                : static::today();
        } catch (\Throwable $e) {
            return static::today();
        }
    }

    /**
     * Convert a timestamp received from a client (the desktop app sends ISO-8601
     * in UTC, e.g. "...Z") into the app's STORAGE timezone, so the stored value
     * is the correct instant. With UTC storage this is effectively a passthrough.
     */
    public static function fromClient(?string $iso): ?Carbon
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->setTimezone(static::storageTz());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Query bounds for a business-day range, expressed in the STORAGE timezone so
     * they compare correctly against persisted values.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function utcRange(Carbon $startTz, Carbon $endTz): array
    {
        $tz = static::storageTz();

        return [$startTz->copy()->setTimezone($tz), $endTz->copy()->setTimezone($tz)];
    }

    /** Bucket key (Y-m-d) for a stored instant, in the business timezone. */
    public static function dateKey(?Carbon $instant): ?string
    {
        return $instant?->copy()->setTimezone(static::tz())->toDateString();
    }
}
