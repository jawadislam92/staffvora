<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use BelongsToOrganization;

    use HasFactory;

    protected $fillable = [
        'name',
        'tags',
        'work_type',
        'is_active',
        'upwork_profile_id', // Keep for backward compatibility during transition
    ];

    protected $casts = [
        'tags' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Active (not archived) clients. Archived clients — finished contracts —
     * are hidden from the pickers used to log NEW work, but stay visible in all
     * historical reports and on existing entries that reference them. Tenant
     * scoping comes from the OrganizationScope; this only narrows further.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Archived clients only — the "Archived" list tab. */
    public function scopeArchived($query)
    {
        return $query->where('is_active', false);
    }

    public function workHours()
    {
        return $this->hasMany(WorkHour::class);
    }

    public function upworkProfile()
    {
        return $this->belongsTo(UpworkProfile::class);
    }

    public function upworkProfiles()
    {
        return $this->belongsToMany(UpworkProfile::class);
    }

    /**
     * ENGAGEMENT TYPE — a *different axis* from a time entry's work category.
     * `clients.work_type` answers "how is this client engaged, and does a
     * profile apply to it": hourly/tracked, fixed-price, or off-platform. It is
     * NOT the "what was the work" vocabulary — that now lives in one place only,
     * {@see WorkCategory}, and a client no longer constrains it.
     *
     * The stored keys are frozen (existing rows, the desktop API, CSV import).
     * The only label that used to CONTRADICT the rest of the app was
     * `outside_of_upwork`, which rendered "Outside Upwork" here for an agency
     * and "Other work" for everyone else, while the Slack digest, the timesheet
     * pages and the Timeline each said something different again. That one label
     * now comes from the workspace's OWN work_categories row — the single
     * vocabulary source — so it can never drift from what a time entry shows.
     * The hourly/fixed wording still follows the profiles module, which is a
     * separately gated feature (Organization::usesProfiles()), not a work
     * category branch.
     */
    public static function getWorkTypes(?Organization $organization = null): array
    {
        $offPlatform = self::offPlatformLabel($organization);

        if ($organization?->usesProfiles()) {
            return [
                'tracker_manual' => 'Upwork hourly / manual',
                'fixed' => 'Upwork fixed-price',
                'outside_of_upwork' => $offPlatform,
            ];
        }

        return [
            'tracker_manual' => 'Tracked or manual time',
            'fixed' => 'Fixed-fee work',
            'outside_of_upwork' => $offPlatform,
        ];
    }

    /**
     * The workspace's own name for off-platform client work, read from its
     * work_categories catalogue (the row seeded from the legacy
     * `outside_of_upwork` value). Falls back to neutral wording when the
     * workspace has no such row — a General workspace never sees "Upwork".
     *
     * Only consulted when the passed organization IS the bound tenant, so a
     * landlord/unscoped context can never read another workspace's vocabulary.
     * Costs nothing per call: WorkCategory memoises the catalogue per process.
     */
    private static function offPlatformLabel(?Organization $organization): string
    {
        $boundId = app(CurrentOrganization::class)->id();

        if ($boundId === null || ($organization !== null && $organization->id !== $boundId)) {
            return 'Other work';
        }

        return WorkCategory::resolveForLegacyWorkType('outside_of_upwork')?->name ?? 'Other work';
    }

    // Check if upwork profile is required for given work type
    public static function isProfileRequired($workType, ?Organization $organization = null): bool
    {
        return (bool) $organization?->usesProfiles()
            && in_array($workType, ['tracker_manual', 'fixed'], true);
    }
}
