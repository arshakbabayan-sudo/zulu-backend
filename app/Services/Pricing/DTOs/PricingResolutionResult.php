<?php

declare(strict_types=1);

namespace App\Services\Pricing\DTOs;

/**
 * Immutable result of PricingResolver::resolve().
 *
 * Future-compatibility: order_pricing_snapshots (Step C) will persist
 * the full snapshotPayload as JSONB for audit and report regeneration.
 */
final class PricingResolutionResult
{
    /**
     * @param  array<string, mixed>  $snapshotPayload
     */
    public function __construct(
        public readonly int $offerId,
        public readonly int $quantity,
        public readonly float $supplierNet,
        public readonly float $customerPrice,
        public readonly string $currency,
        public readonly ?string $ruleIdApplied,
        public readonly array $snapshotPayload,
    ) {}

    public function lineTotal(): float
    {
        return round($this->customerPrice * $this->quantity, 2);
    }
}
