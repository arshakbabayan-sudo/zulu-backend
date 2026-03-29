<?php

namespace App\Services\Pricing;

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
}
