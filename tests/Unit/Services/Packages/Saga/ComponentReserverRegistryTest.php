<?php

namespace Tests\Unit\Services\Packages\Saga;

use App\Models\OrderItem;
use App\Models\SagaComponentState;
use App\Services\Packages\Saga\ComponentReserverRegistry;
use App\Services\Packages\Saga\Contracts\ComponentReserverInterface;
use App\Services\Packages\Saga\DTOs\ConfirmationResult;
use App\Services\Packages\Saga\DTOs\ReservationResult;
use App\Services\Packages\Saga\DTOs\RollbackResult;
use App\Services\Packages\Saga\Reservers\StubComponentReserver;
use InvalidArgumentException;
use Tests\TestCase;

class ComponentReserverRegistryTest extends TestCase
{
    public function test_registry_seeds_all_seven_service_types_with_stub_by_default(): void
    {
        $registry = new ComponentReserverRegistry;

        foreach (SagaComponentState::SERVICE_TYPES as $type) {
            $reserver = $registry->for($type);
            $this->assertInstanceOf(StubComponentReserver::class, $reserver);
            $this->assertSame($type, $reserver->serviceType());
        }
    }

    public function test_for_unknown_service_type_throws(): void
    {
        $registry = new ComponentReserverRegistry;
        $this->expectException(InvalidArgumentException::class);
        $registry->for('bogus');
    }

    public function test_register_overrides_default_stub(): void
    {
        $registry = new ComponentReserverRegistry;
        $custom = new class implements ComponentReserverInterface
        {
            public function reserve(SagaComponentState $component, ?OrderItem $item = null): ReservationResult
            {
                return ReservationResult::failure('always fails');
            }

            public function confirm(SagaComponentState $component): ConfirmationResult
            {
                return ConfirmationResult::failure('not implemented');
            }

            public function rollback(SagaComponentState $component): RollbackResult
            {
                return RollbackResult::success();
            }

            public function serviceType(): string
            {
                return 'flight';
            }
        };

        $registry->register('flight', $custom);

        $this->assertSame($custom, $registry->for('flight'));
        // other types still stubs
        $this->assertInstanceOf(StubComponentReserver::class, $registry->for('hotel'));
    }

    public function test_register_unknown_type_throws(): void
    {
        $registry = new ComponentReserverRegistry;
        $custom = new StubComponentReserver('flight'); // type irrelevant for the test

        $this->expectException(InvalidArgumentException::class);
        $registry->register('not_a_real_type', $custom);
    }

    public function test_stub_reserver_reserve_returns_deterministic_supplier_ref(): void
    {
        $reserver = new StubComponentReserver('hotel');
        $component = new SagaComponentState([
            'idempotency_key' => 'abc123def4567890',
            'service_type' => 'hotel',
        ]);

        $result = $reserver->reserve($component);

        $this->assertTrue($result->success);
        $this->assertSame('STUB-hotel-abc123def456', $result->supplierRef);
        $this->assertSame('stub', $result->payload['reserver']);
        $this->assertSame('hotel', $result->payload['service_type']);
    }

    public function test_stub_reserver_confirm_and_rollback_succeed(): void
    {
        $reserver = new StubComponentReserver('flight');
        $component = new SagaComponentState(['idempotency_key' => 'k1']);

        $this->assertTrue($reserver->confirm($component)->success);
        $this->assertTrue($reserver->rollback($component)->success);
    }
}
