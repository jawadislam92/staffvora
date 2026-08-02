<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * The APPEND-ONLY audit trail of the timesheet state machine.
 *
 * Every transition writes exactly one row — who did it, when, from which status
 * to which, with the note and the totals as they stood at that moment. Nothing
 * here is ever updated or deleted: a payroll number that was approved and later
 * reopened has to be explainable months afterwards, and "the timesheet says
 * open now" is not an explanation.
 *
 * The totals are copied in rather than re-derived on read, precisely because
 * reopening lets the underlying time change — the trail records what was
 * approved, not what the entries happen to add up to today.
 */
class TimesheetApproval extends Model
{
    use BelongsToOrganization;

    public const ACTION_SUBMITTED = 'submitted';

    public const ACTION_APPROVED = 'approved';

    public const ACTION_REJECTED = 'rejected';

    public const ACTION_REOPENED = 'reopened';

    public const ACTIONS = [self::ACTION_SUBMITTED, self::ACTION_APPROVED, self::ACTION_REJECTED, self::ACTION_REOPENED];

    protected $fillable = [
        'timesheet_id',
        'subject_user_id',
        'actor_user_id',
        'action',
        'from_status',
        'to_status',
        'note',
        'total_hours',
        'billable_hours',
    ];

    protected $casts = [
        'total_hours' => 'float',
        'billable_hours' => 'float',
    ];

    public function timesheet()
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject()
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * Record one transition. Called from inside the transaction that performs
     * it, so the trail can never disagree with the row it describes.
     *
     * @param  array{total?: float, billable?: float}  $totals
     */
    public static function record(
        Timesheet $timesheet,
        string $action,
        ?string $fromStatus,
        string $toStatus,
        ?int $actorId,
        ?string $note = null,
        array $totals = [],
    ): self {
        return static::create([
            'timesheet_id' => $timesheet->id,
            'subject_user_id' => $timesheet->user_id,
            'actor_user_id' => $actorId,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'total_hours' => $totals['total'] ?? null,
            'billable_hours' => $totals['billable'] ?? null,
        ]);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => (int) $this->id,
            'action' => $this->action,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'note' => $this->note,
            'actor' => $this->relationLoaded('actor') ? $this->actor?->name : null,
            'total_hours' => $this->total_hours === null ? null : (float) $this->total_hours,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
