<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Day-bucketing helpers. The app stores datetimes in config('app.timezone')
 * (Asia/Karachi for this company) on every environment, so day boundaries are
 * computed in that same timezone — no UTC conversion. Centralised here so
 * Timeline, Team, and the desktop endpoints can never drift apart again.
 */
class BusinessTime
{
    public static function tz(): string
    {
        return config('app.timezone', 'Asia/Karachi');
    }

    public static function today(): Carbon
    {
        return Carbon::today(static::tz());
    }

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
     * in UTC, e.g. "...Z") into the app's storage timezone, so the stored
     * wall-clock matches every other table (which is written in app.timezone).
     * Without this, desktop timestamps land 5h behind Asia/Karachi.
     */
    public static function fromClient(?string $iso): ?Carbon
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->setTimezone(static::tz());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Query bounds for a business-day range. Stored values share the app
     * timezone, so the bounds pass through in that timezone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function utcRange(Carbon $startTz, Carbon $endTz): array
    {
        $tz = static::tz();

        return [$startTz->copy()->setTimezone($tz), $endTz->copy()->setTimezone($tz)];
    }

    /** Bucket key (Y-m-d) for a stored instant, in the business timezone. */
    public static function dateKey(?Carbon $instant): ?string
    {
        return $instant?->copy()->setTimezone(static::tz())->toDateString();
    }
}
