<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Offer;
use App\Services\Pricing\DTOs\PricingResolutionResult;
use InvalidArgumentException;

/**
 * Phase 1 / Step B.4 — pricing resolver (STUB).
 *
 * This is the Phase-1 stub that returns the existing 15% markup behaviour
 * via PriceCalculatorService, so OrderService can switch to the new
 * signature (offer_id-driven) without changing customer-facing pricing.
 *
 * The REAL Phase 1 / Step C resolver will mirror CommissionRuleResolver:
 *   - 4-level priority: partnership → operator → category → global
 *   - Read from `pricing_rules` table (Step C migration)
 *   - FX-aware snapshot via `exchange_rates`
 *   - `bcmath` scale=4 throughout
 *
 * Contract is locked: callers MUST pass an offer_id + quantity + optional
 * buyer context. They MUST NOT pass `unit_price` — that field was the
 * markup-bypass attack surface (audit doc §4, B.4 commit).
 */
class PricingResolver
{
    public function __construct(
        private PriceCalculatorService $calculator,
    ) {}

    /**
     * Resolve the customer-facing price for a single line item.
     *
     * @param  int   $offerId         The Offer being purchased.
     * @param  int   $quantity        Units (default 1).
     * @param  array{
     *   buyer_type?: string,
     *   agent_company_id?: int|null,
     *   customer_id?: int|null,
     *   price_override?: numeric|null,
     * } $buyerContext
     *
     * @throws InvalidArgumentException if offer doesn't exist
     */
    public function resolve(int $offerId, int $quantity, array $buyerContext = []): PricingResolutionResult
    {
        if ($offerId <= 0) {
            throw new InvalidArgumentException('PricingResolver::resolve requires a positive offer_id.');
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException('PricingResolver::resolve requires quantity >= 1.');
        }

        /** @var Offer|null $offer */
        $offer = Offer::query()->find($offerId);
        if ($offer === null) {
            throw new InvalidArgumentException("Offer id={$offerId} not found.");
        }

        $supplierNet = isset($buyerContext['price_override']) && $buyerContext['price_override'] !== null
            ? (float) $buyerContext['price_override']
            : (float) ($offer->price ?? 0);

        // Stub: compute customer price via the existing 15% global markup.
        // Step C swaps this for the real pricing_rules resolution.
        $customerPrice = $this->calculator->b2cPrice($supplierNet);

        return new PricingResolutionResult(
            offerId: $offerId,
            quantity: $quantity,
            supplierNet: $supplierNet,
            customerPrice: $customerPrice,
            currency: strtoupper((string) ($offer->currency ?? 'USD')),
            ruleIdApplied: null, // no rule yet — global 15% from platform_settings
            snapshotPayload: [
                'engine' => 'phase_1_stub_b2c_markup',
                'engine_version' => 1,
                'supplier_net' => $supplierNet,
                'customer_price' => $customerPrice,
                'currency' => strtoupper((string) ($offer->currency ?? 'USD')),
                'buyer_context' => $buyerContext,
                'offer_company_id' => $offer->company_id ?? null,
                'computed_at' => now()->toIso8601String(),
            ],
        );
    }
}
