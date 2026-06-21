<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A marketing contact-form lead. Landlord-owned (not tenant-scoped); read in the
 * operator portal's Leads inbox.
 */
class ContactSubmission extends Model
{
    protected $guarded = [];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
