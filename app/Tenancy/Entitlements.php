<?php

namespace App\Tenancy;

/**
 * The canonical catalogue of every gated capability ("entitlement") in the
 * product, and the rules for resolving an effective value.
 *
 * This is the heart of platform control: a tenant gets ONLY what its plan grants
 * or what we (the platform) grant it directly. Anything not granted falls back
 * to the registry default below — which is DENY (false / 0 / a high floor) — so
 * the system is default-deny and can't be abused.
 *
 * Each entitlement has a `kind`:
 *   - flag : boolean. Effective = override ?? plan ?? default(false).
 *   - max  : integer ceiling. A tenant setting may not exceed it.
 *            Effective = override ?? plan ?? default. (e.g. screenshots/hour)
 *   - min  : integer floor. A tenant setting may not go below it.
 *            Effective = override ?? plan ?? default. (e.g. auto-pause minutes —
 *            Free is forced to a slower cadence; higher plans can go tighter.)
 *
 * When the platform sets a per-tenant override it REPLACES the plan value (it can
 * raise OR lower it) — that's how we grant one customer a little more at a custom
 * price without touching anyone else.
 */
class Entitlements
{
    /**
     * @var array<string, array{kind:string, default:int|bool, group:string, label:string, help:string, unit?:string}>
     */
    public const REGISTRY = [
        // Seats
        'max_users' => [
            'kind' => 'max', 'default' => 1, 'group' => 'Limits', 'unit' => 'seats',
            'label' => 'User seats', 'help' => 'Maximum number of users in the workspace (blank = unlimited).',
        ],

        // Monitoring caps
        'max_screenshots_per_hour' => [
            'kind' => 'max', 'default' => 0, 'group' => 'Monitoring', 'unit' => '/hour',
            'label' => 'Screenshots per hour (max)', 'help' => 'The highest screenshot rate this plan may set.',
        ],
        'min_auto_pause_minutes' => [
            'kind' => 'min', 'default' => 10, 'group' => 'Monitoring', 'unit' => 'min',
            'label' => 'Auto-pause floor', 'help' => 'The tightest idle auto-pause this plan may set (lower = more aggressive). Free is forced to a gentler floor.',
        ],
        'max_retention_days' => [
            'kind' => 'max', 'default' => 7, 'group' => 'Monitoring', 'unit' => 'days',
            'label' => 'Screenshot retention (max days)', 'help' => 'How long screenshots may be kept.',
        ],

        // Feature flags
        'monitoring' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'Screenshot monitoring', 'help' => 'Capture desktop screenshots at all.',
        ],
        'activity_tracking' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'Activity tracking', 'help' => 'Keyboard/mouse activity percentage.',
        ],
        'app_url_tracking' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'App & URL tracking', 'help' => 'Record active application and browser URL.',
        ],
        'weekly_limit' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'Weekly time limit', 'help' => 'Allow setting a weekly hours cap per user.',
        ],
        'attendance_alerts' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'Attendance Slack alerts', 'help' => 'Notify Slack on clock-in / clock-out.',
        ],
        'ai' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'AI assistant', 'help' => 'AI day-summary and assistant features.',
        ],
        'integrations' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'Integrations', 'help' => 'Connect the workspace\'s own Slack bot.',
        ],
        'api' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Features',
            'label' => 'API access', 'help' => 'Programmatic API access.',
        ],
        'white_label' => [
            'kind' => 'flag', 'default' => false, 'group' => 'Branding',
            'label' => 'White-label branding', 'help' => 'Custom logo, name, accent and theme across the app.',
        ],
    ];

    /** All entitlement keys. */
    public static function keys(): array
    {
        return array_keys(self::REGISTRY);
    }

    public static function kind(string $key): ?string
    {
        return self::REGISTRY[$key]['kind'] ?? null;
    }

    /** The default-deny value for an entitlement (false / 0 / a high floor). */
    public static function default(string $key): int|bool|null
    {
        return self::REGISTRY[$key]['default'] ?? null;
    }

    public static function isFlag(string $key): bool
    {
        return (self::REGISTRY[$key]['kind'] ?? null) === 'flag';
    }

    /**
     * Resolve the effective value. A per-tenant override wins when present (the
     * platform can raise OR lower it). Otherwise, if the org has a plan, the
     * plan value wins — including an explicit `null` for an unlimited cap
     * (e.g. enterprise seats). Only when there is no plan and no override do we
     * fall back to the registry default, which is deny.
     */
    public static function resolve(string $key, bool $hasPlan, mixed $planValue, mixed $override): int|bool|null
    {
        if ($override !== null) {
            return self::cast($key, $override);
        }
        if ($hasPlan) {
            return self::cast($key, $planValue);
        }

        return self::cast($key, self::default($key));
    }

    /** Normalise a raw value to its kind: flags → bool, caps → int (null = unlimited). */
    public static function cast(string $key, mixed $value): int|bool|null
    {
        if (self::kind($key) === 'flag') {
            return (bool) $value;
        }
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Is a requested integer setting allowed by the effective entitlement?
     * For `max`, requested must be ≤ effective. For `min`, requested must be ≥
     * effective. A null effective `max` means unlimited.
     */
    public static function allowsValue(string $key, int $requested, int|bool|null $effective): bool
    {
        $kind = self::kind($key);
        if ($kind === 'max') {
            return $effective === null || $requested <= (int) $effective;
        }
        if ($kind === 'min') {
            return $effective === null || $requested >= (int) $effective;
        }

        return true;
    }

    /**
     * The full plan-level entitlement map for a named plan tier, used to seed
     * `plans.entitlements`. Values omitted fall back to the registry default
     * (deny) at resolve time, but we write explicit values so the platform
     * editor shows a complete picture.
     *
     * @return array<string, int|bool|null>
     */
    public static function planDefaults(string $key): array
    {
        return match ($key) {
            'free' => [
                'max_users' => 3,
                'max_screenshots_per_hour' => 6,
                'min_auto_pause_minutes' => 10,
                'max_retention_days' => 14,
                'monitoring' => true,
                'activity_tracking' => false,
                'app_url_tracking' => false,
                'weekly_limit' => false,
                'attendance_alerts' => false,
                'ai' => false,
                'integrations' => false,
                'api' => false,
                'white_label' => false,
            ],
            'starter' => [
                'max_users' => 10,
                'max_screenshots_per_hour' => 12,
                'min_auto_pause_minutes' => 5,
                'max_retention_days' => 30,
                'monitoring' => true,
                'activity_tracking' => true,
                'app_url_tracking' => true,
                'weekly_limit' => true,
                'attendance_alerts' => true,
                'ai' => false,
                'integrations' => true,
                'api' => false,
                'white_label' => false,
            ],
            'pro' => [
                'max_users' => 50,
                'max_screenshots_per_hour' => 30,
                'min_auto_pause_minutes' => 1,
                'max_retention_days' => 90,
                'monitoring' => true,
                'activity_tracking' => true,
                'app_url_tracking' => true,
                'weekly_limit' => true,
                'attendance_alerts' => true,
                'ai' => true,
                'integrations' => true,
                'api' => true,
                'white_label' => true,
            ],
            'enterprise' => [
                'max_users' => null,
                'max_screenshots_per_hour' => 60,
                'min_auto_pause_minutes' => 1,
                'max_retention_days' => 365,
                'monitoring' => true,
                'activity_tracking' => true,
                'app_url_tracking' => true,
                'weekly_limit' => true,
                'attendance_alerts' => true,
                'ai' => true,
                'integrations' => true,
                'api' => true,
                'white_label' => true,
            ],
            default => [],
        };
    }
}
