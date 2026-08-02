<?php

namespace App\Support;

/**
 * Allowed transitions for the clock-in / break / clock-out sequence.
 * Single source of truth shared by the web dashboard and the desktop API.
 */
class TimeClockRules
{
    public const ACTIONS = ['clock_in', 'clock_out', 'break_start', 'break_end'];

    public static function isAllowed(?string $lastAction, string $nextAction): bool
    {
        if ($lastAction === null) {
            return $nextAction === 'clock_in';
        }

        return match ($lastAction) {
            'clock_in' => in_array($nextAction, ['clock_out', 'break_start'], true),
            'break_start' => $nextAction === 'break_end',
            'break_end' => in_array($nextAction, ['clock_out', 'break_start'], true),
            'clock_out' => $nextAction === 'clock_in',
            default => false,
        };
    }

    public static function blockedMessage(?string $lastAction, string $nextAction): string
    {
        if ($lastAction === null) {
            return 'Please clock in before recording another action.';
        }

        if ($lastAction === 'break_start' && $nextAction === 'clock_out') {
            return 'Please end your break before clocking out.';
        }

        // Already on the clock (incl. a clock-in carried over past midnight) and
        // trying to clock in again — the reported double clock-in.
        if ($nextAction === 'clock_in' && in_array($lastAction, ['clock_in', 'break_start', 'break_end'], true)) {
            return "You're already clocked in — clock out first.";
        }

        return 'This time action is not available from your current status.';
    }

    /** Actions the user may take next, given their last action. */
    public static function available(?string $lastAction): array
    {
        return array_values(array_filter(
            self::ACTIONS,
            fn (string $action) => self::isAllowed($lastAction, $action),
        ));
    }
}
