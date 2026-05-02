<?php

namespace App\Services\Packages\Saga\DTOs;

final class ReservationResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $supplierRef = null,
        public readonly ?string $errorMessage = null,
        public readonly array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function success(string $supplierRef, array $payload = []): self
    {
        return new self(success: true, supplierRef: $supplierRef, payload: $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function failure(string $errorMessage, array $payload = []): self
    {
        return new self(success: false, errorMessage: $errorMessage, payload: $payload);
    }
}
