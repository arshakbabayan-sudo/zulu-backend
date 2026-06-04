<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

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

    /**
     * Resolve the `agent_company_id` to stamp on an order when a customer buys
     * a product supplied by $supplierCompanyId having chosen $sellerCompanyId
     * in the seller picker. Returns the chosen agent's company id, or null for a
     * direct purchase from the supplier operator. Throws (anti-spoof) if the
     * chosen seller is neither the supplier nor one of the customer's partners.
     */
    public static function resolveChosenAgent(int $customerUserId, ?int $sellerCompanyId, ?int $supplierCompanyId): ?int
    {
        if ($sellerCompanyId === null || $sellerCompanyId === $supplierCompanyId) {
            return null; // direct from the supplier operator
        }

        $isPartner = static::query()
            ->where('customer_user_id', $customerUserId)
            ->where('partner_company_id', $sellerCompanyId)
            ->exists();

        if (! $isPartner) {
            throw ValidationException::withMessages([
                'seller_company_id' => ['This seller is not one of your partners.'],
            ]);
        }

        return $sellerCompanyId;
    }
}
