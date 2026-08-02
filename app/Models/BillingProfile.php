<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A workspace's bill-to details: the legal name, tax id and address that go on
 * its invoices, plus where invoices are emailed.
 *
 * Tenant-owned (one row per organization, enforced by a unique index) and
 * org-scoped like every other tenant record — a company can only ever read or
 * write its own. Nothing here decides access; it is presentation data for an
 * invoice, so it must never be consulted by a gate.
 */
class BillingProfile extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'billing_name', 'billing_email', 'tax_id',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code',
        'country', 'notes',
    ];

    /** The address rendered as invoice lines, blanks dropped. */
    public function addressLines(): array
    {
        return array_values(array_filter([
            $this->address_line1,
            $this->address_line2,
            trim(implode(' ', array_filter([$this->city, $this->state, $this->postal_code]))) ?: null,
            $this->country,
        ], fn ($line) => filled($line)));
    }
}
