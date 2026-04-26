<?php

namespace Tests\Unit\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_order_with_single_item_and_computes_totals(): void
    {
        $order = (new OrderService)->create(
            ['currency' => 'EUR'],
            [[
                'item_type' => 'flight',
                'currency' => 'EUR',
                'unit_price' => 100,
                'quantity' => 2,
            ]]
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $order->id);
        $this->assertSame('EUR', $order->currency);
        $this->assertSame('200.00', (string) $order->subtotal);
        $this->assertSame('200.00', (string) $order->total);
        $this->assertSame('0.00', (string) $order->tax);

        $this->assertCount(1, $order->items);
        $item = $order->items->firstOrFail();
        $this->assertSame(2, $item->quantity);
        $this->assertSame('100.00', (string) $item->unit_price);
        $this->assertSame('200.00', (string) $item->total);
        $this->assertNull($item->parent_item_id);
    }

    public function test_it_creates_order_with_multiple_items_and_sums_subtotal(): void
    {
        $order = (new OrderService)->create(
            ['currency' => 'EUR'],
            [
                ['item_type' => 'flight', 'currency' => 'EUR', 'unit_price' => 100, 'quantity' => 1],
                ['item_type' => 'hotel', 'currency' => 'EUR', 'unit_price' => 75, 'quantity' => 2],
                ['item_type' => 'transfer', 'currency' => 'EUR', 'unit_price' => 40, 'quantity' => 1],
            ]
        );

        $this->assertSame('290.00', (string) $order->subtotal);
        $this->assertCount(3, $order->items);

        $byType = $order->items->keyBy('item_type');
        $this->assertSame('100.00', (string) $byType['flight']->total);
        $this->assertSame('150.00', (string) $byType['hotel']->total);
        $this->assertSame('40.00', (string) $byType['transfer']->total);
        $this->assertTrue($order->items->every(fn (OrderItem $item): bool => $item->parent_item_id === null));
    }

    public function test_it_creates_package_with_children_via_parent_index(): void
    {
        $order = (new OrderService)->create(
            ['currency' => 'EUR'],
            [
                ['item_type' => 'package', 'currency' => 'EUR', 'unit_price' => 180, 'quantity' => 1],
                ['item_type' => 'flight', 'currency' => 'EUR', 'unit_price' => 100, 'quantity' => 1, 'parent_index' => 0],
                ['item_type' => 'hotel', 'currency' => 'EUR', 'unit_price' => 60, 'quantity' => 1, 'parent_index' => 0],
                ['item_type' => 'transfer', 'currency' => 'EUR', 'unit_price' => 20, 'quantity' => 1, 'parent_index' => 0],
            ]
        );

        $this->assertCount(4, $order->items);

        $package = $order->items->firstWhere('item_type', 'package');
        $this->assertNotNull($package);
        $this->assertNull($package->parent_item_id);

        $children = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('parent_item_id', $package->id)
            ->get();

        $this->assertCount(3, $children);
        $this->assertSame(
            ['flight', 'hotel', 'transfer'],
            $children->pluck('item_type')->sort()->values()->all()
        );
        $this->assertSame(
            [$package->id],
            $order->items->whereNotNull('parent_item_id')->pluck('parent_item_id')->unique()->values()->all()
        );
    }

    public function test_it_round_trips_jsonb_service_snapshot_and_passenger_data(): void
    {
        $snapshot = [
            'hotel' => 'Test',
            'rooms' => [
                ['type' => 'std', 'price' => 75],
            ],
        ];
        $passengerData = [
            ['name' => 'John', 'doc' => 'X1'],
        ];

        $order = (new OrderService)->create(
            ['currency' => 'EUR'],
            [[
                'item_type' => 'hotel',
                'currency' => 'EUR',
                'unit_price' => 75,
                'quantity' => 1,
                'service_snapshot' => $snapshot,
                'passenger_data' => $passengerData,
            ]]
        );

        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertSame($snapshot, $item->service_snapshot);
        $this->assertSame($passengerData, $item->passenger_data);
    }

    public function test_it_rolls_back_on_invalid_item_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing item_type');

        try {
            (new OrderService)->create(
                ['currency' => 'EUR'],
                [[
                    'item_type' => 'bogus_type',
                    'currency' => 'EUR',
                    'unit_price' => 50,
                ]]
            );
        } finally {
            $this->assertSame(0, Order::count());
            $this->assertSame(0, OrderItem::count());
        }
    }

    public function test_it_rejects_forward_or_self_parent_index(): void
    {
        $service = new OrderService;

        try {
            $service->create(
                ['currency' => 'EUR'],
                [
                    ['item_type' => 'package', 'currency' => 'EUR', 'unit_price' => 100],
                    ['item_type' => 'flight', 'currency' => 'EUR', 'unit_price' => 50, 'parent_index' => 2],
                ]
            );
            $this->fail('Expected InvalidArgumentException for forward parent_index.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('parent_index', $exception->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());

        try {
            $service->create(
                ['currency' => 'EUR'],
                [
                    ['item_type' => 'package', 'currency' => 'EUR', 'unit_price' => 100],
                    ['item_type' => 'hotel', 'currency' => 'EUR', 'unit_price' => 50, 'parent_index' => 1],
                ]
            );
            $this->fail('Expected InvalidArgumentException for self parent_index.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('parent_index', $exception->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
    }

    public function test_it_requires_currency_and_at_least_one_item(): void
    {
        $service = new OrderService;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OrderService::create requires at least one item.');
        $service->create(['currency' => 'EUR'], []);
    }

    public function test_it_requires_order_data_currency_when_items_present(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('orderData.currency is required.');

        (new OrderService)->create(
            [],
            [['item_type' => 'flight', 'currency' => 'EUR', 'unit_price' => 100]]
        );
    }
}
