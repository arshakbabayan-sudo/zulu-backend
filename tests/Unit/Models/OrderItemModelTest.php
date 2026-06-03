<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class OrderItemModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_all_fillable_columns_and_rehydrates_with_correct_casts(): void
    {
        $order = $this->createOrder();

        $created = OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'hotel',
            'item_id' => 42,
            'package_id' => 777,
            'parent_item_id' => null,
            'quantity' => 2,
            'unit_price' => 75.50,
            'total' => 151.00,
            'currency' => 'EUR',
            'service_snapshot' => [
                'hotel_name' => 'Test Hotel',
                'rooms' => [
                    ['type' => 'std', 'price' => 75.50],
                ],
            ],
            'passenger_data' => [
                ['name' => 'John', 'doc' => 'AB123456'],
            ],
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-05',
            'status' => 'pending',
            'external_ref' => 'HTL-CONF-99',
        ]);

        $item = OrderItem::query()->findOrFail($created->id);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $item->id);
        $this->assertSame($order->id, $item->order_id);
        $this->assertSame('hotel', $item->item_type);
        $this->assertSame(42, $item->item_id);
        $this->assertSame(777, $item->package_id);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('75.50', (string) $item->unit_price);
        $this->assertSame('151.00', (string) $item->total);
        $this->assertSame('EUR', $item->currency);
        $this->assertIsArray($item->service_snapshot);
        $this->assertSame('Test Hotel', $item->service_snapshot['hotel_name']);
        $this->assertSame('std', $item->service_snapshot['rooms'][0]['type']);
        $this->assertIsArray($item->passenger_data);
        $this->assertSame('John', $item->passenger_data[0]['name']);
        $this->assertInstanceOf(Carbon::class, $item->date_from);
        $this->assertInstanceOf(Carbon::class, $item->date_to);
        $this->assertSame('pending', $item->status);
        $this->assertSame('HTL-CONF-99', $item->external_ref);
    }

    public function test_it_loads_parent_and_children_self_ref(): void
    {
        $order = $this->createOrder();

        $parent = OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'package',
            'item_id' => 5001,
            'currency' => 'EUR',
            'status' => 'pending',
            'external_ref' => 'PKG-ROOT-1',
        ]);

        $children = collect(['flight', 'hotel', 'transfer'])->map(function (string $type, int $index) use ($order, $parent) {
            return OrderItem::query()->create([
                'order_id' => $order->id,
                'item_type' => $type,
                'item_id' => 6000 + $index,
                'parent_item_id' => $parent->id,
                'currency' => 'EUR',
                'status' => 'pending',
                'external_ref' => 'CHILD-'.$type,
            ]);
        });

        $freshParent = $parent->fresh();
        $parentChildren = $freshParent->children;

        $this->assertInstanceOf(Collection::class, $parentChildren);
        $this->assertCount(3, $parentChildren);
        $children->each(function (OrderItem $child) use ($parent): void {
            $this->assertTrue($child->fresh()->parent->is($parent));
        });
        $this->assertNull($freshParent->parent);
    }

    public function test_it_loads_order_belongsto(): void
    {
        $order = $this->createOrder();

        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'hotel',
            'item_id' => 9001,
            'currency' => 'EUR',
            'status' => 'pending',
            'external_ref' => 'ORDER-LINK-1',
        ]);

        $this->assertTrue($item->fresh()->order->is($order));
    }

    private function createOrder(): Order
    {
        $company = Company::query()->create([
            'name' => 'Order Item Test Company '.str()->uuid(),
            'type' => 'operator',
        ]);
        $user = User::factory()->create();

        return Order::query()->create([
            'order_number' => 'ZULU-2026-ITEM-TEST',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'cart',
            'currency' => 'EUR',
            'subtotal' => 100.00,
            'tax' => 20.00,
            'total' => 120.00,
            'payment_id' => null,
            'notes' => null,
            'metadata' => ['source' => 'order-item-test'],
        ]);
    }
}
