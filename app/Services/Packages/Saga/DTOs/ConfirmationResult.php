<?php

namespace App\Services\Packages\Saga\DTOs;

final class ConfirmationResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $errorMessage = null,
        public readonly array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function success(array $payload = []): self
    {
        return new self(success: true, payload: $payload);
    }

    public static function failure(string $errorMessage): self
    {
        return new self(success: false, errorMessage: $errorMessage);
    }
}
