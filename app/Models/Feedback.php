<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One feedback / request ticket: the opening post (subject + message) plus a
 * thread of replies (FeedbackMessage) from the submitter and from managers.
 *
 * Tenant-owned — the BelongsToOrganization trait registers the OrganizationScope
 * (so one workspace never sees another's inbox) and stamps organization_id on
 * create, which is why nothing in the controller passes it by hand.
 */
class Feedback extends Model
{
    use BelongsToOrganization;

    /** Explicit: the model is named Feedback (uncountable) but the table is not. */
    protected $table = 'feedback_items';

    public const TYPES = ['question', 'feature_request', 'bug', 'missing_doc'];

    public const STATUSES = ['new', 'in_review', 'planned', 'done', 'declined'];

    /** Statuses that still need a manager's attention (drive the inbox badge). */
    public const OPEN_STATUSES = ['new', 'in_review', 'planned'];

    protected $fillable = [
        'organization_id',
        'user_id',
        'type',
        'subject',
        'message',
        'context',
        'status',
        'handled_by',
        'handled_at',
        'response_seen_at',
        'manager_seen_at',
    ];

    protected $casts = [
        'context' => 'array',
        'handled_at' => 'datetime',
        'response_seen_at' => 'datetime',
        'manager_seen_at' => 'datetime',
    ];

    /**
     * There is no DB-level foreign key (SQLite/MySQL portability), so the thread
     * is cleaned up here instead of by ON DELETE CASCADE.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $item) {
            $item->messages()->delete();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** The conversation after the opening request, oldest first. */
    public function messages(): HasMany
    {
        return $this->hasMany(FeedbackMessage::class, 'feedback_item_id')->orderBy('created_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * A submitter's own tickets that have a reply from someone else (a manager)
     * posted since they last read the thread — i.e. unseen replies. Drives the
     * submitter's Inbox badge. (Reusable unread pattern: a message newer than
     * the viewer's last-read timestamp.) The outer query is already org-scoped,
     * and the subquery is joined by feedback_item_id, so it stays tenant-safe.
     */
    public function scopeUnseenFor(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('feedback_messages')
                    ->whereColumn('feedback_messages.feedback_item_id', 'feedback_items.id')
                    ->whereColumn('feedback_messages.user_id', '!=', 'feedback_items.user_id')
                    ->where(function ($w) {
                        $w->whereNull('feedback_items.response_seen_at')
                            ->orWhereColumn('feedback_messages.created_at', '>', 'feedback_items.response_seen_at');
                    });
            });
    }
}
