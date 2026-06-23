<?php

namespace App\Services\Pricing;

use App\Models\Company;
use App\Services\Infrastructure\PlatformSettingsService;

class PriceCalculatorService
{
    public function __construct(
        private PlatformSettingsService $settings
    ) {}

    /**
     * Calculate B2C (retail) price from B2B (base) price.
     * B2C = B2B * (1 + markup_percent / 100)
     */
    public function b2cPrice(string|float $b2bPrice): float
    {
        $markup = $this->settings->getB2cMarkupPercent();
        $base = (float) $b2bPrice;

        return round($base * (1 + $markup / 100), 2);
    }

    /**
     * The ONE place the per-operator markup math lives. Both the customer-site
     * DISPLAY (CatalogOfferDetailResource) and the booking CHARGE (PricingResolver
     * no-rule fallback) call this so "shown == charged" for an operator with no
     * pricing_rule.
     *
     * Picks the operator's percent for the buyer type:
     *   - seller_b2b (agent / netto)  → company.agent_markup_percent
     *   - everything else (client/guest, brutto) → company.customer_markup_percent
     * If that column is NULL (or there is no operator) it falls back to the
     * global getB2cMarkupPercent() — so an operator with both columns NULL
     * behaves EXACTLY as today (15%). Then: round(price * (1 + pct/100), 2).
     */
    public function b2cPriceForOperator(
        string|float $supplierPrice,
        ?int $operatorCompanyId,
        string $buyerType = 'client'
    ): float {
        $base = (float) $supplierPrice;
        $pct = $this->markupPercentForOperator($operatorCompanyId, $buyerType);

        return round($base * (1 + $pct / 100), 2);
    }

    /**
     * Resolve the effective markup percent for an operator + buyer type,
     * falling back to the global default when no per-operator value is set.
     */
    private function markupPercentForOperator(?int $operatorCompanyId, string $buyerType): float
    {
        if ($operatorCompanyId !== null && $operatorCompanyId > 0) {
            /** @var Company|null $company */
            $company = Company::query()
                ->select(['id', 'agent_markup_percent', 'customer_markup_percent'])
                ->find($operatorCompanyId);

            if ($company !== null) {
                $column = $buyerType === 'seller_b2b'
                    ? $company->agent_markup_percent
                    : $company->customer_markup_percent;

                if ($column !== null) {
                    return (float) $column;
                }
            }
        }

        return $this->settings->getB2cMarkupPercent();
    }

    /**
     * Return both prices as array.
     *
     * @return array{b2b_price: float, b2c_price: float}
     */
    public function dualPrice(string|float $b2bPrice): array
    {
        $b2b = round((float) $b2bPrice, 2);

        return [
            'b2b_price' => $b2b,
            'b2c_price' => $this->b2cPrice($b2bPrice),
        ];
    }

    /**
     * @return array{
     *   base_price: float,
     *   calculated_price: float,
     *   currency: string|null
     * }
     */
    public function normalizedPrice(string|float|null $basePrice, ?string $currency): array
    {
        $base = round((float) ($basePrice ?? 0), 2);

        return [
            'base_price' => $base,
            'calculated_price' => $this->b2cPrice($base),
            'currency' => $currency !== null ? strtoupper($currency) : null,
        ];
    }
}
