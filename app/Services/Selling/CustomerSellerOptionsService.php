<?php

namespace App\Services\Selling;

use App\Models\Company;
use App\Models\CustomerPartner;
use App\Models\Offer;
use App\Models\User;
use App\Services\Pricing\PricingResolver;
use Illuminate\Support\Collection;

/**
 * For an offer + a B2C customer, returns the sellers the customer can buy
 * THROUGH (their preferred partner-agents + the supplier operator direct),
 * each with its price, ranked by the customer's partner order.
 *
 * Seller-attribution Phase 1 (docs/blueprints/seller-attribution-architecture-2026-06-04.md):
 *   - The supplier operator (offer.company_id) sells DIRECT at the market price.
 *   - Each partner-AGENT prices the same offer via PricingResolver with its own
 *     agent-scoped markup → its (usually lower, competitive) price.
 *   - Default = the customer's highest-ranked eligible partner; else operator-direct.
 *
 * MVP note: a partner-agent is offered for any product; the connections-based
 * "which agent may sell which operator" restriction is Phase 3.
 */
class CustomerSellerOptionsService
{
    public function __construct(private PricingResolver $pricingResolver) {}

    /** @return array<string, mixed> */
    public function optionsFor(Offer $offer, User $customer, int $quantity = 1): array
    {
        $offerId = (int) $offer->id;
        $supplierId = $offer->company_id !== null ? (int) $offer->company_id : null;
        $supplier = $supplierId !== null ? Company::query()->find($supplierId) : null;
        $country = $supplier?->country;

        $base = ['buyer_type' => 'client', 'customer_id' => $customer->id];

        // Market / operator-direct price (always available).
        $market = $this->pricingResolver->resolve($offerId, max(1, $quantity), $base);
        $currency = $market->currency;
        $marketPrice = round((float) $market->customerPrice, 2);

        /** @var Collection<int, CustomerPartner> $partners */
        $partners = CustomerPartner::query()
            ->where('customer_user_id', $customer->id)
            ->when(is_string($country) && $country !== '', fn ($q) => $q->where('country', $country))
            ->with('partner:id,name,type')
            ->orderBy('rank')
            ->get();

        $options = [];
        foreach ($partners as $p) {
            $pid = (int) $p->partner_company_id;
            $name = $p->partner?->name;
            $type = $p->partner?->type;

            if ($supplierId !== null && $pid === $supplierId) {
                // Their chosen partner IS the supplier operator → buy direct.
                $options[] = $this->opt($pid, $name, $type, $marketPrice, $currency, $p->rank, false);

                continue;
            }
            if ($type !== 'agency') {
                // A non-supplier operator can't sell this offer (Phase 3 adds the
                // connections-based restriction; for now only agents resell).
                continue;
            }

            $priced = $this->pricingResolver->resolve($offerId, max(1, $quantity), $base + ['agent_company_id' => $pid]);
            $options[] = $this->opt($pid, $name, $type, round((float) $priced->customerPrice, 2), $currency, $p->rank, true);
        }

        // Operator-direct is ALWAYS an option (fallback), if not already present.
        $hasSupplier = $supplierId !== null
            && collect($options)->contains(fn (array $o): bool => $o['seller_company_id'] === $supplierId);
        if ($supplierId !== null && ! $hasSupplier) {
            $options[] = $this->opt($supplierId, $supplier?->name, $supplier?->type, $marketPrice, $currency, null, false);
        }

        if ($options !== []) {
            $options[0]['is_default'] = true;
        }

        return [
            'offer_id' => $offerId,
            'currency' => $currency,
            'market' => [
                'company_id' => $supplierId,
                'name' => $supplier?->name,
                'price' => $marketPrice,
            ],
            'options' => $options,
            'default_company_id' => $options[0]['seller_company_id'] ?? $supplierId,
        ];
    }

    /** @return array<string, mixed> */
    private function opt(?int $companyId, ?string $name, ?string $type, float $price, string $currency, ?int $rank, bool $isAgent): array
    {
        return [
            'seller_company_id' => $companyId,
            'name' => $name,
            'type' => $type,
            'price' => $price,
            'currency' => $currency,
            'rank' => $rank,
            'is_agent' => $isAgent,
            'is_default' => false,
        ];
    }
}
