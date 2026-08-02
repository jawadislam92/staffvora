<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkHour extends Model
{
    use BelongsToOrganization;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'started_at',
        'ended_at',
        'hours',
        'description',
        'work_type',
        'work_category_id',
        'is_billable',
        'client_id',
        'tracker',
        'tracking_session_id',
        'source',
        // Stamped when the timesheet covering this entry is APPROVED, cleared
        // when it is reopened. {@see App\Models\Timesheet}
        'timesheet_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_billable' => 'boolean',
    ];

    // `is_approval_locked` rides along so a list can say WHY a row is locked
    // (frozen for payroll vs. measured by the tracker) without a second lookup.
    protected $appends = ['time_range', 'is_locked', 'capture_method', 'is_approval_locked'];

    /** `source` value written by the desktop-tracker mirror (TrackingSessionService). */
    public const SOURCE_TRACKER = 'tracker';

    /**
     * CAPTURE METHOD — how the time got here. System-set, never chosen by a
     * person, and deliberately NOT a stored column: it is derived from the
     * tracking session link the same way the lock rule always has been.
     */
    public const CAPTURE_TRACKED = 'tracked';

    public const CAPTURE_MANUAL = 'manual';

    /**
     * Fill in the two new vocabulary fields for any writer that still speaks the
     * legacy `work_type` (the desktop-tracker mirror, the desktop API, old form
     * posts), so tracked time is categorised without those call sites changing.
     * Costs at most one query per process per workspace (the catalogue is
     * memoised), and is a complete no-op when no tenant is bound.
     */
    protected static function booted(): void
    {
        static::creating(function (self $workHour) {
            if ($workHour->work_category_id !== null) {
                return;
            }

            // Read the RAW attribute, never the accessor — the accessor invents
            // a legacy value for rows that don't have one, which would file
            // every uncategorised row under Client Work.
            $category = WorkCategory::resolveForLegacyWorkType($workHour->getAttributes()['work_type'] ?? null);

            if ($category === null) {
                return;
            }

            $workHour->work_category_id = $category->id;

            // Only when the caller didn't state it — an explicit false must win.
            if (! array_key_exists('is_billable', $workHour->getAttributes())) {
                $workHour->is_billable = $category->is_billable_default;
            }
        });
    }

    /**
     * A human "2:08 PM – 3:05 PM" label for ranged manual entries, formatted
     * server-side from the stored wall-clock so the browser timezone can't
     * shift it. Null for duration-only entries.
     */
    public function getTimeRangeAttribute(): ?string
    {
        if (! $this->started_at || ! $this->ended_at) {
            return null;
        }

        return $this->started_at->format('g:i A').' – '.$this->ended_at->format('g:i A');
    }

    /**
     * Tracker-recorded time: mirrored from a desktop tracking session. Its hours
     * are measured, not typed, so they must never be hand-edited — the row is a
     * projection of the session, and the session is the source of truth.
     */
    public function isTracked(): bool
    {
        return $this->tracking_session_id !== null || $this->source === self::SOURCE_TRACKER;
    }

    /** The inverse: an entry a person typed in by hand (the Add Manual Time form). */
    public function isManual(): bool
    {
        return ! $this->isTracked();
    }

    /** 'tracked' | 'manual' — concept A of the split vocabulary. */
    public function captureMethod(): string
    {
        return $this->isTracked() ? self::CAPTURE_TRACKED : self::CAPTURE_MANUAL;
    }

    /** Serialized for the UI so rows/forms can label the capture method. */
    public function getCaptureMethodAttribute(): string
    {
        return $this->captureMethod();
    }

    /**
     * PAYROLL LOCK — this entry sits inside an APPROVED timesheet.
     *
     * Approving a pay period freezes the number payroll pays against, so every
     * entry in it is stamped with the approving timesheet's id in one indexed
     * UPDATE (see TimesheetApprovalController). Asking the question is
     * therefore a COLUMN READ on an already-loaded row — no query per row, no
     * query per page — which is the only shape that survives a workspace with
     * hundreds of thousands of entries.
     *
     * This lock is STRICTER than the tracked lock below: a tracked entry still
     * accepts label corrections (description, category, billable, client),
     * because its *time* is what's measured. An approved entry accepts nothing
     * at all — the billable split and the category are part of the number that
     * was signed off. Only reopening the timesheet releases it.
     */
    public function isApprovalLocked(): bool
    {
        return $this->timesheet_id !== null;
    }

    /**
     * Whether the time portion of this entry (hours / start–end / date) is
     * locked against hand-editing: it was measured by the tracker, or it has
     * been frozen by an approved timesheet. Callers should ask this question
     * rather than sniff `source` themselves.
     */
    public function isLocked(): bool
    {
        return $this->isTracked() || $this->isApprovalLocked();
    }

    /** Serialized for the UI so rows/forms can show the lock without re-deriving it. */
    public function getIsLockedAttribute(): bool
    {
        return $this->isLocked();
    }

    /** Serialized alongside it, so the UI can name the stricter payroll lock. */
    public function getIsApprovalLockedAttribute(): bool
    {
        return $this->isApprovalLocked();
    }

    /**
     * Why the entry is locked, in the words the UI shows. Null when editable.
     * The approval lock is reported FIRST because it is the stronger of the two:
     * it also rules out the label edits a tracked entry still allows.
     */
    public function lockReason(): ?string
    {
        if ($this->isApprovalLocked()) {
            return 'This time is inside an approved timesheet, so it\'s frozen for payroll and can\'t be edited or deleted. Ask an approver to reopen that pay period first.';
        }

        return $this->isTracked()
            ? 'These hours were measured by the desktop tracker, so they can\'t be edited or deleted by hand. Adjust the tracked time from the Timeline for that day instead.'
            : null;
    }

    /**
     * LEGACY READER. `work_type` is kept so every old row still reads exactly as
     * it does today and the not-yet-retired label maps keep working. When a row
     * has no stored value (anything written category-first) the legacy value is
     * derived instead of coming back null — Client Work resolves to the capture
     * method, because that is the distinction the old enum was really making.
     *
     * Nothing new should depend on this: work_category_id + is_billable are the
     * source of truth from here on.
     */
    public function getWorkTypeAttribute($value): ?string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        return $this->legacyWorkTypeFromCategory();
    }

    /**
     * Never queries: it reads the category only when the relation is already
     * eager-loaded, so serialising a page of rows can't turn into an N+1.
     */
    public function legacyWorkTypeFromCategory(): string
    {
        $slug = $this->relationLoaded('workCategory') ? $this->workCategory?->slug : null;

        if ($slug === null) {
            return $this->captureMethod() === self::CAPTURE_TRACKED ? 'tracker' : 'manual';
        }

        return WorkCategory::LEGACY_WORK_TYPE_BY_SLUG[$slug]
            ?? ($this->captureMethod() === self::CAPTURE_TRACKED ? 'tracker' : 'manual');
    }

    /** Hand-logged entries only (no tracking session behind them). */
    public function scopeManual($query)
    {
        return $query
            ->whereNull('tracking_session_id')
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', self::SOURCE_TRACKER));
    }

    /** Entries frozen by an approved timesheet. Rides work_hours_timesheet_idx. */
    public function scopeApprovalLocked($query)
    {
        return $query->whereNotNull('timesheet_id');
    }

    /** Tracker-mirrored entries only. */
    public function scopeTracked($query)
    {
        return $query
            ->where(fn ($q) => $q->whereNotNull('tracking_session_id')->orWhere('source', self::SOURCE_TRACKER));
    }

    /** Entries that carry real clock endpoints (range mode), not just a duration. */
    public function scopeRanged($query)
    {
        return $query->whereNotNull('started_at')->whereNotNull('ended_at');
    }

    /** Concept C — the billable half of the split. Indexed (org, is_billable, date). */
    public function scopeBillable($query, bool $billable = true)
    {
        return $query->where('is_billable', $billable);
    }

    /**
     * Ranged entries overlapping a wall-clock window — the "busy" set used when
     * working out a day's untracked gaps and when applying the overlap policy.
     */
    public function scopeOverlapping($query, $start, $end)
    {
        return $query->ranged()
            ->where('started_at', '<', $end)
            ->where('ended_at', '>', $start);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** Concept B — WHAT the work was, from the workspace's own editable list. */
    public function workCategory()
    {
        return $this->belongsTo(WorkCategory::class);
    }

    /** The APPROVED timesheet that froze this entry, when there is one. */
    public function timesheet()
    {
        return $this->belongsTo(Timesheet::class);
    }
}
