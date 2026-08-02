<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reply in a feedback ticket's thread — from the submitter or a manager.
 * The opening request itself lives on the Feedback item (subject/message);
 * these are everything said after it.
 *
 * Tenant-owned like its parent so a cross-tenant read is impossible even when a
 * message is queried directly.
 */
class FeedbackMessage extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'feedback_item_id', 'user_id', 'body'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Feedback::class, 'feedback_item_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
