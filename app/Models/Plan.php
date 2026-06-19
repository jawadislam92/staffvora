<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan in the global catalogue (not tenant-scoped). Feature flags
 * and the seat limit drive gating; price is per seat / month.
 */
class Plan extends Model
{
    protected $fillable = [
        'key', 'name', 'price_cents', 'currency', 'interval', 'max_users', 'features', 'is_public', 'sort',
    ];

    protected $casts = [
        'features' => 'array',
        'is_public' => 'boolean',
        'price_cents' => 'integer',
        'max_users' => 'integer',
    ];

    public function feature(string $key, mixed $default = false): mixed
    {
        return data_get($this->features, $key, $default);
    }

    public function isUnlimitedUsers(): bool
    {
        return $this->max_users === null;
    }

    /** Idempotently seed the default plan catalogue (safe to re-run). */
    public static function ensureSeeded(): void
    {
        foreach (static::defaults() as $plan) {
            static::firstOrCreate(['key' => $plan['key']], $plan);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function defaults(): array
    {
        $features = fn (array $on) => array_merge(
            ['monitoring' => false, 'app_url' => false, 'ai' => false, 'white_label' => false, 'api' => false, 'integrations' => false],
            $on,
        );

        return [
            ['key' => 'free', 'name' => 'Free', 'price_cents' => 0, 'max_users' => 3, 'sort' => 1,
                'features' => $features([])],
            ['key' => 'starter', 'name' => 'Starter', 'price_cents' => 400, 'max_users' => 10, 'sort' => 2,
                'features' => $features(['monitoring' => true, 'app_url' => true])],
            ['key' => 'pro', 'name' => 'Pro', 'price_cents' => 800, 'max_users' => 50, 'sort' => 3,
                'features' => $features(['monitoring' => true, 'app_url' => true, 'ai' => true, 'white_label' => true, 'api' => true, 'integrations' => true])],
            ['key' => 'enterprise', 'name' => 'Enterprise', 'price_cents' => 0, 'max_users' => null, 'sort' => 4,
                'features' => $features(['monitoring' => true, 'app_url' => true, 'ai' => true, 'white_label' => true, 'api' => true, 'integrations' => true])],
        ];
    }
}

