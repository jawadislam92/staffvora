<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One diagnostic event: either something the desktop agent reported about itself
 * (`category = 'desktop'` — capture blocked, upload failed, crash on resume) or a
 * decision the capture-health watchdog made (`category = 'tracker-health'`).
 *
 * Tenant-owned: the OrganizationScope restricts reads to the active workspace and
 * organization_id is stamped on create. Nothing here is ever populated from a
 * request body — the ingest controller derives the tenant and the user from the
 * authenticated Sanctum token, because the agent runs on the employee's machine
 * and is attacker-controllable.
 *
 * Retention is enforced by `monitoring:prune-screenshots` (time ceiling + per-org
 * row ceiling). See the migration for the policy.
 */
class DesktopDiagnostic extends Model
{
    use BelongsToOrganization;
    use HasFactory;

    /** Agent-reported client-side telemetry. */
    public const CATEGORY_DESKTOP = 'desktop';

    /** Watchdog decisions (alerted / skipped and why). */
    public const CATEGORY_TRACKER_HEALTH = 'tracker-health';

    public const LEVELS = ['info', 'warn', 'error'];

    /**
     * Hard ceiling on a single event's serialized context. The agent controls
     * this payload, so an unbounded object graph is a cheap way to fill the
     * operator's database; anything larger is stored truncated with a marker
     * rather than rejected, so one oversized event never loses the whole batch.
     */
    public const MAX_CONTEXT_BYTES = 4000;

    protected $table = 'desktop_diagnostics';

    protected $fillable = [
        'organization_id',
        'user_id',
        'category',
        'level',
        'event',
        'summary',
        'message',
        'context',
        'device_name',
        'platform',
        'app_version',
        'client_at',
        'captured_at',
    ];

    protected $casts = [
        'context' => 'array',
        'client_at' => 'datetime',
        'captured_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Write one event. Capturing diagnostics must NEVER turn a problem into two,
     * so every failure is swallowed and null is returned — callers are watchdogs
     * and ingest endpoints whose real job must continue regardless.
     */
    public static function record(array $attributes): ?self
    {
        try {
            $attributes['captured_at'] = $attributes['captured_at'] ?? now();
            $attributes['category'] = $attributes['category'] ?? self::CATEGORY_DESKTOP;
            $attributes['level'] = in_array($attributes['level'] ?? null, self::LEVELS, true)
                ? $attributes['level']
                : 'info';
            $attributes['context'] = self::boundContext($attributes['context'] ?? null);

            if (empty($attributes['organization_id'])) {
                $attributes['organization_id'] = app(CurrentOrganization::class)->id();
            }

            $attributes['summary'] = Str::limit((string) ($attributes['summary'] ?? $attributes['event'] ?? ''), 250, '');

            return static::create($attributes);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Keep a context payload inside MAX_CONTEXT_BYTES. Oversized payloads are
     * replaced by a marker carrying the original size, which is the information
     * an operator actually needs (and is not attacker-sized).
     */
    public static function boundContext(mixed $context): ?array
    {
        if ($context === null || $context === []) {
            return null;
        }

        if (! is_array($context)) {
            $context = ['value' => $context];
        }

        $encoded = json_encode($context);

        if ($encoded === false) {
            return ['_diagnostics' => 'context could not be encoded'];
        }

        if (strlen($encoded) <= self::MAX_CONTEXT_BYTES) {
            return $context;
        }

        return [
            '_diagnostics' => 'context truncated',
            '_original_bytes' => strlen($encoded),
            '_preview' => Str::limit($encoded, 500, '…'),
        ];
    }
}
