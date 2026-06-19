<?php

namespace App\Models;

use App\Tenancy\Entitlements;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan in the global catalogue (not tenant-scoped). Its
 * `entitlements` map (caps + flags, see App\Tenancy\Entitlements) drives all
 * gating; the platform edits it in the plan editor. `features` is the old flat
 * boolean map, kept readable for back-compat. Price is per seat / month.
 */
class Plan extends Model
{
    protected $fillable = [
        'key', 'name', 'price_cents', 'currency', 'interval', 'max_users', 'features', 'entitlements', 'is_public', 'sort',
    ];

    protected $casts = [
        'features' => 'array',
        'entitlements' => 'array',
        'is_public' => 'boolean',
        'price_cents' => 'integer',
        'max_users' => 'integer',
    ];

    /** @deprecated use entitlement(); kept for any old call sites. */
    public function feature(string $key, mixed $default = false): mixed
    {
        $value = $this->entitlement($key);

        return $value ?? data_get($this->features, $key, $default);
    }

    /** The plan-level value for an entitlement, or the registry default (deny). */
    public function entitlement(string $key): int|bool|null
    {
        return data_get($this->entitlements, $key, Entitlements::default($key));
    }

    /** The full resolved entitlement map for this plan (every key). */
    public function entitlementMap(): array
    {
        $out = [];
        foreach (Entitlements::keys() as $key) {
            $out[$key] = $this->entitlement($key);
        }

        return $out;
    }

    public function isUnlimitedUsers(): bool
    {
        return $this->max_users === null;
    }

    /**
     * Idempotently seed the default plan catalogue (safe to re-run). Existing
     * rows keep any platform edits, but a row missing its `entitlements` map is
     * backfilled so upgrades don't leave a plan ungated.
     */
    public static function ensureSeeded(): void
    {
        foreach (static::defaults() as $plan) {
            $row = static::firstOrCreate(['key' => $plan['key']], $plan);

            if (empty($row->entitlements)) {
                $row->entitlements = $plan['entitlements'];
                $row->save();
            }
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
                'features' => $features([]), 'entitlements' => Entitlements::planDefaults('free')],
            ['key' => 'starter', 'name' => 'Starter', 'price_cents' => 400, 'max_users' => 10, 'sort' => 2,
                'features' => $features(['monitoring' => true, 'app_url' => true]), 'entitlements' => Entitlements::planDefaults('starter')],
            ['key' => 'pro', 'name' => 'Pro', 'price_cents' => 800, 'max_users' => 50, 'sort' => 3,
                'features' => $features(['monitoring' => true, 'app_url' => true, 'ai' => true, 'white_label' => true, 'api' => true, 'integrations' => true]), 'entitlements' => Entitlements::planDefaults('pro')],
            ['key' => 'enterprise', 'name' => 'Enterprise', 'price_cents' => 0, 'max_users' => null, 'sort' => 4,
                'features' => $features(['monitoring' => true, 'app_url' => true, 'ai' => true, 'white_label' => true, 'api' => true, 'integrations' => true]), 'entitlements' => Entitlements::planDefaults('enterprise')],
        ];
    }
}
