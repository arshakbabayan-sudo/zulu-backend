<?php

namespace App\Services\Packages\Saga\Reservers;

use App\Models\OrderItem;
use App\Models\SagaComponentState;
use App\Services\Packages\Saga\Contracts\ComponentReserverInterface;
use App\Services\Packages\Saga\DTOs\ConfirmationResult;
use App\Services\Packages\Saga\DTOs\ReservationResult;
use App\Services\Packages\Saga\DTOs\RollbackResult;

/**
 * Default no-op reserver used until real supplier integrations land.
 *
 * Always succeeds. Generates a deterministic supplier_ref of the form
 * STUB-{serviceType}-{idempotencyKey}. Tests can swap concrete reservers
 * via the registry to exercise failure paths.
 */
class StubComponentReserver implements ComponentReserverInterface
{
    public function __construct(
        private string $serviceType,
    ) {}

    public function reserve(SagaComponentState $component, ?OrderItem $item = null): ReservationResult
    {
        $supplierRef = 'STUB-'.$this->serviceType.'-'.substr($component->idempotency_key, 0, 12);

        return ReservationResult::success(
            supplierRef: $supplierRef,
            payload: [
                'reserver' => 'stub',
                'service_type' => $this->serviceType,
                'item_id' => $item?->id,
                'reserved_at' => now()->toIso8601String(),
            ],
        );
    }

    public function confirm(SagaComponentState $component): ConfirmationResult
    {
        return ConfirmationResult::success([
            'reserver' => 'stub',
            'service_type' => $this->serviceType,
            'confirmed_at' => now()->toIso8601String(),
        ]);
    }

    public function rollback(SagaComponentState $component): RollbackResult
    {
        return RollbackResult::success();
    }

    public function serviceType(): string
    {
        return $this->serviceType;
    }
}
