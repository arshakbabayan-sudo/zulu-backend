<?php

namespace App\Services\Packages\Saga\Contracts;

use App\Models\OrderItem;
use App\Models\SagaComponentState;
use App\Services\Packages\Saga\DTOs\ConfirmationResult;
use App\Services\Packages\Saga\DTOs\ReservationResult;
use App\Services\Packages\Saga\DTOs\RollbackResult;

/**
 * Per-service-type reservation contract for the package booking saga.
 *
 * Each implementation handles the supplier-specific protocol for one of:
 * flight, hotel, transfer, car, excursion, visa, insurance.
 *
 * All operations MUST be idempotent — the saga may retry with the same
 * idempotency_key and expect the same result without duplicating side effects.
 */
interface ComponentReserverInterface
{
    /**
     * Hold the supplier resource on behalf of $component.
     * Must populate $component->supplier_ref + reservation_payload on success.
     */
    public function reserve(SagaComponentState $component, ?OrderItem $item = null): ReservationResult;

    /**
     * Finalize a previously reserved hold (mark booked / issue tickets / etc.).
     * No-op for suppliers where reserve == confirm (single-phase).
     */
    public function confirm(SagaComponentState $component): ConfirmationResult;

    /**
     * Compensate a previous reservation or confirmation.
     * Releases the held supplier resource.
     */
    public function rollback(SagaComponentState $component): RollbackResult;

    /**
     * Service type this reserver handles (one of SagaComponentState::SERVICE_TYPES).
     */
    public function serviceType(): string;
}
