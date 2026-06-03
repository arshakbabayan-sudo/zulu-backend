<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class OrderModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_all_fillable_columns_and_rehydrates_with_correct_casts(): void
    {
        $company = $this->createCompany();
        $user = User::factory()->create();

        $created = Order::query()->create([
            'order_number' => 'ZULU-2026-TEST-001',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'cart',
            'currency' => 'EUR',
            'subtotal' => 100.00,
            'tax' => 20.00,
            'total' => 120.00,
            'payment_id' => null,
            'notes' => 'unit test',
            'metadata' => ['source' => 'web', 'utm' => ['campaign' => 'test']],
        ]);

        $order = Order::query()->findOrFail($created->id);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $order->id);
        $this->assertSame('ZULU-2026-TEST-001', $order->order_number);
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame($company->id, $order->company_id);
        $this->assertSame('client', $order->buyer_type);
        $this->assertSame('cart', $order->status);
        $this->assertSame('EUR', $order->currency);
        $this->assertSame('100.00', (string) $order->subtotal);
        $this->assertSame('20.00', (string) $order->tax);
        $this->assertSame('120.00', (string) $order->total);
        $this->assertNull($order->payment_id);
        $this->assertSame('unit test', $order->notes);
        $this->assertIsArray($order->metadata);
        $this->assertSame('web', $order->metadata['source']);
        $this->assertSame('test', $order->metadata['utm']['campaign']);
    }

    public function test_it_loads_items_hasmany_relation(): void
    {
        $order = $this->createOrder();

        foreach ([1, 2, 3] as $index) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'item_type' => 'hotel',
                'item_id' => 100 + $index,
                'currency' => 'EUR',
                'status' => 'pending',
                'external_ref' => 'ORDER-ITEM-'.$index,
            ]);
        }

        $items = $order->fresh()->items;

        $this->assertInstanceOf(Collection::class, $items);
        $this->assertCount(3, $items);
    }

    public function test_it_loads_user_company_payment_relations(): void
    {
        $company = $this->createCompany();
        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_number' => 'ZULU-2026-TEST-REL',
            'user_id' => $user->id,
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'cart',
            'currency' => 'EUR',
            'subtotal' => 10.00,
            'tax' => 2.00,
            'total' => 12.00,
            'payment_id' => null,
            'notes' => null,
            'metadata' => ['source' => 'relations-test'],
        ]);

        $fresh = $order->fresh();

        $this->assertTrue($fresh->user->is($user));
        $this->assertTrue($fresh->company->is($company));
        $this->assertNull($fresh->payment);
        $this->assertInstanceOf(BelongsTo::class, $fresh->payment());
    }

    private function createOrder(): Order
    {
        $company = $this->createCompany();
        $user = User::factory()->create();

        return Order::query()->create([
            'order_number' => 'ZULU-2026-TEST-ITEMS',
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
            'metadata' => ['source' => 'items-test'],
        ]);
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Order Model Company '.str()->uuid(),
            'type' => 'operator',
        ]);
    }
}
