<?php

declare(strict_types=1);

namespace App\Services\Commissions\DTOs;

use DateTimeInterface;

readonly class CommissionResolutionContext
{
    public function __construct(
        public int $sellerId,
        public string $serviceType,
        public DateTimeInterface $atTime,
        public ?int $categoryId = null,
        public ?int $partnerAgreementId = null,
    ) {}

    /**
     * @param  array{
     *   categoryId?: int|null,
     *   partnerAgreementId?: int|null,
     *   atTime?: DateTimeInterface
     * }  $opts
     */
    public static function make(int $sellerId, string $serviceType, array $opts = []): self
    {
        return new self(
            sellerId: $sellerId,
            serviceType: $serviceType,
            atTime: $opts['atTime'] ?? now(),
            categoryId: $opts['categoryId'] ?? null,
            partnerAgreementId: $opts['partnerAgreementId'] ?? null,
        );
    }

    public static function now(
        int $sellerId,
        string $serviceType,
        ?int $categoryId = null,
        ?int $partnerAgreementId = null,
    ): self {
        return new self(
            sellerId: $sellerId,
            serviceType: $serviceType,
            atTime: now(),
            categoryId: $categoryId,
            partnerAgreementId: $partnerAgreementId,
        );
    }
}
