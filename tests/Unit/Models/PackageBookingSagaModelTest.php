<?php

namespace Tests\Unit\Models;

use App\Models\Order;
use App\Models\Package;
use App\Models\PackageBookingSaga;
use App\Models\SagaComponentState;
use App\Models\SagaStateLog;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageBookingSagaModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_saga_with_all_lifecycle_fields(): void
    {
        $order = $this->makeOrder();

        $saga = PackageBookingSaga::query()->create([
            'order_id' => $order->id,
            'status' => 'pending',
            'context' => ['source' => 'test', 'attempts' => 0],
        ]);

        $fresh = PackageBookingSaga::query()->findOrFail($saga->id);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $fresh->id);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($order->id, $fresh->order_id);
        $this->assertSame('test', $fresh->context['source']);
        $this->assertFalse($fresh->isTerminal());
        $this->assertFalse($fresh->isSuccess());
    }

    public function test_terminal_states_detected(): void
    {
        $order = $this->makeOrder();

        $confirmed = PackageBookingSaga::query()->create(['order_id' => $order->id, 'status' => 'confirmed']);
        $this->assertTrue($confirmed->isTerminal());
        $this->assertTrue($confirmed->isSuccess());

        $order2 = $this->makeOrder();
        $failed = PackageBookingSaga::query()->create(['order_id' => $order2->id, 'status' => 'failed']);
        $this->assertTrue($failed->isTerminal());
        $this->assertFalse($failed->isSuccess());
    }

    public function test_one_saga_per_order_unique_constraint(): void
    {
        $order = $this->makeOrder();
        PackageBookingSaga::query()->create(['order_id' => $order->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        PackageBookingSaga::query()->create(['order_id' => $order->id]);
    }

    public function test_components_relation(): void
    {
        $order = $this->makeOrder();
        $saga = PackageBookingSaga::query()->create(['order_id' => $order->id, 'status' => 'reserving']);

        SagaComponentState::query()->create([
            'saga_id' => $saga->id,
            'service_type' => 'flight',
            'service_id' => 1,
            'status' => 'reserved',
            'idempotency_key' => 'test-key-1',
        ]);
        SagaComponentState::query()->create([
            'saga_id' => $saga->id,
            'service_type' => 'hotel',
            'service_id' => 2,
            'status' => 'reserved',
            'idempotency_key' => 'test-key-2',
        ]);

        $this->assertInstanceOf(HasMany::class, $saga->components());
        $this->assertCount(2, $saga->fresh()->components);
    }

    public function test_state_log_relation_ordered_by_happened_at(): void
    {
        $order = $this->makeOrder();
        $saga = PackageBookingSaga::query()->create(['order_id' => $order->id]);

        SagaStateLog::query()->create([
            'saga_id' => $saga->id,
            'from_status' => null,
            'to_status' => 'pending',
            'event' => 'created',
            'happened_at' => now()->subMinute(),
        ]);
        SagaStateLog::query()->create([
            'saga_id' => $saga->id,
            'from_status' => 'pending',
            'to_status' => 'reserving',
            'event' => 'reserve_started',
            'happened_at' => now(),
        ]);

        $logs = $saga->fresh()->logs;
        $this->assertCount(2, $logs);
        $this->assertSame('created', $logs->first()->event);
        $this->assertSame('reserve_started', $logs->last()->event);
    }

    private function makeOrder(): Order
    {
        return Order::query()->create([
            'order_number' => 'ORD-SAGA-'.str()->random(6),
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
        ]);
    }
}
