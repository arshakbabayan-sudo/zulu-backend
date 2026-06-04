<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A B2C customer's preferred seller (agent or operator) for a given country,
 * with a rank that drives which price the customer sees by default.
 *
 * See docs/blueprints/seller-attribution-architecture-2026-06-04.md.
 */
class CustomerPartner extends Model
{
    /** Arshak's rule: a customer may keep at most this many partners per country. */
    public const MAX_PER_COUNTRY = 10;

    protected $fillable = [
        'customer_user_id',
        'partner_company_id',
        'country',
        'rank',
    ];

    protected $casts = [
        'rank' => 'integer',
    ];

    /** The B2C customer who owns this preference. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    /** The preferred seller company (agent or operator). */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'partner_company_id');
    }
}
