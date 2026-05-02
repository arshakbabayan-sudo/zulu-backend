<?php

namespace App\Services\Packages\Saga\DTOs;

final class RollbackResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function success(): self
    {
        return new self(success: true);
    }

    public static function failure(string $errorMessage): self
    {
        return new self(success: false, errorMessage: $errorMessage);
    }
}
