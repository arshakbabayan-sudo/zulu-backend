<?php

namespace Tests\Unit\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 1 / Step B.4 — OrderService contract changed: callers MUST pass
 * `offer_id` per item, service resolves the price via PricingResolver
 * (stub returns 15% markup behaviour preserved from PriceCalculatorService).
 *
 * `unit_price` from callers is REJECTED with InvalidArgumentException —
 * was the markup-bypass attack surface (audit doc §4).
 */
class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,int>  Map of item_type → seeded offer id (price 100, currency EUR) */
    private array $offers = [];

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'TestCo '.uniqid(),
            'type' => 'operator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Each test offer is priced at 100 EUR (supplier net). After the
        // 15% markup, customer_price = 115.00.
        foreach (['flight', 'hotel', 'transfer', 'package'] as $type) {
            $this->offers[$type] = DB::table('offers')->insertGetId([
                'company_id' => $this->companyId,
                'type' => $type,
                'title' => 'Test '.$type,
                'price' => 100,
                'currency' => 'EUR',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function service(): OrderService
    {
        return app(OrderService::class);
    }

    public function test_it_creates_order_with_single_item_and_computes_resolved_totals(): void
    {
        $order = $this->service()->create(
            ['currency' => 'EUR'],
            [[
                'item_type' => 'flight',
                'currency' => 'EUR',
                'offer_id' => $this->offers['flight'],
                'quantity' => 2,
            ]]
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $order->id);
        $this->assertSame('EUR', $order->currency);
        // 100 supplier net * 1.15 markup * 2 qty = 230.00
        $this->assertSame('230.00', (string) $order->subtotal);
        $this->assertSame('230.00', (string) $order->total);
        $this->assertSame('0.00', (string) $order->tax);

        $this->assertCount(1, $order->items);
        $item = $order->items->firstOrFail();
        $this->assertSame(2, $item->quantity);
        $this->assertSame('115.00', (string) $item->unit_price);
        $this->assertSame('230.00', (string) $item->total);
        $this->assertNull($item->parent_item_id);

        // Snapshot of pricing decision is persisted on the item.
        $snapshot = $item->service_snapshot;
        // C.2 — real resolver. Without a matching pricing_rules row the
        // resolver falls back to the legacy 15% behaviour (preserves
        // production semantics until C.5 ships the global seed row).
        $this->assertSame('operator_markup_percent', $snapshot['pricing']['engine']);
        $this->assertSame($this->offers['flight'], $snapshot['offer_id']);
    }

    public function test_it_creates_order_with_multiple_items_and_sums_resolved_subtotal(): void
    {
        $order = $this->service()->create(
            ['currency' => 'EUR'],
            [
                ['item_type' => 'flight', 'currency' => 'EUR', 'offer_id' => $this->offers['flight'], 'quantity' => 1],
                ['item_type' => 'hotel', 'currency' => 'EUR', 'offer_id' => $this->offers['hotel'], 'quantity' => 2],
                ['item_type' => 'transfer', 'currency' => 'EUR', 'offer_id' => $this->offers['transfer'], 'quantity' => 1],
            ]
        );

        // (115 * 1) + (115 * 2) + (115 * 1) = 460.00
        $this->assertSame('460.00', (string) $order->subtotal);
        $this->assertCount(3, $order->items);

        $byType = $order->items->keyBy('item_type');
        $this->assertSame('115.00', (string) $byType['flight']->total);
        $this->assertSame('230.00', (string) $byType['hotel']->total);
        $this->assertSame('115.00', (string) $byType['transfer']->total);
        $this->assertTrue($order->items->every(fn (OrderItem $item): bool => $item->parent_item_id === null));
    }

    public function test_it_creates_package_with_children_via_parent_index(): void
    {
        $order = $this->service()->create(
            ['currency' => 'EUR'],
            [
                ['item_type' => 'package', 'currency' => 'EUR', 'offer_id' => $this->offers['package'], 'quantity' => 1],
                ['item_type' => 'flight', 'currency' => 'EUR', 'offer_id' => $this->offers['flight'], 'quantity' => 1, 'parent_index' => 0],
                ['item_type' => 'hotel', 'currency' => 'EUR', 'offer_id' => $this->offers['hotel'], 'quantity' => 1, 'parent_index' => 0],
                ['item_type' => 'transfer', 'currency' => 'EUR', 'offer_id' => $this->offers['transfer'], 'quantity' => 1, 'parent_index' => 0],
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
    }

    public function test_it_respects_price_override_for_package_components(): void
    {
        $order = $this->service()->create(
            ['currency' => 'EUR'],
            [[
                'item_type' => 'hotel',
                'currency' => 'EUR',
                'offer_id' => $this->offers['hotel'],
                'price_override' => 50, // operator-set override on a package component
                'quantity' => 1,
            ]]
        );

        $item = $order->items->firstOrFail();
        // Override (50) is the new supplier_net; markup 15% applied = 57.50
        $this->assertSame('57.50', (string) $item->unit_price);
    }

    public function test_it_round_trips_caller_service_snapshot_and_passenger_data(): void
    {
        $callerSnapshot = ['hotel' => 'Test', 'rooms' => [['type' => 'std', 'price' => 75]]];
        $passengerData = [['name' => 'John', 'doc' => 'X1']];

        $order = $this->service()->create(
            ['currency' => 'EUR'],
            [[
                'item_type' => 'hotel',
                'currency' => 'EUR',
                'offer_id' => $this->offers['hotel'],
                'quantity' => 1,
                'service_snapshot' => $callerSnapshot,
                'passenger_data' => $passengerData,
            ]]
        );

        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

        // Caller's keys preserved (hotel, rooms) AND pricing snapshot added.
        $this->assertSame('Test', $item->service_snapshot['hotel']);
        $this->assertSame($callerSnapshot['rooms'], $item->service_snapshot['rooms']);
        $this->assertArrayHasKey('pricing', $item->service_snapshot);
        $this->assertSame($passengerData, $item->passenger_data);
    }

    public function test_it_rejects_caller_supplied_unit_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unit_price is no longer accepted/');

        $this->service()->create(
            ['currency' => 'EUR'],
            [[
                'item_type' => 'flight',
                'currency' => 'EUR',
                'offer_id' => $this->offers['flight'],
                'unit_price' => 1, // attempted markup bypass
                'quantity' => 1,
            ]]
        );
    }

    public function test_it_requires_offer_id_per_item(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/offer_id is required/');

        $this->service()->create(
            ['currency' => 'EUR'],
            [[
                'item_type' => 'flight',
                'currency' => 'EUR',
                'quantity' => 1,
            ]]
        );
    }

    public function test_it_rolls_back_on_invalid_item_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing item_type');

        try {
            $this->service()->create(
                ['currency' => 'EUR'],
                [[
                    'item_type' => 'bogus_type',
                    'currency' => 'EUR',
                    'offer_id' => $this->offers['flight'],
                ]]
            );
        } finally {
            $this->assertSame(0, Order::count());
            $this->assertSame(0, OrderItem::count());
        }
    }

    public function test_it_rejects_forward_or_self_parent_index(): void
    {
        $service = $this->service();

        try {
            $service->create(
                ['currency' => 'EUR'],
                [
                    ['item_type' => 'package', 'currency' => 'EUR', 'offer_id' => $this->offers['package']],
                    ['item_type' => 'flight', 'currency' => 'EUR', 'offer_id' => $this->offers['flight'], 'parent_index' => 2],
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
                    ['item_type' => 'package', 'currency' => 'EUR', 'offer_id' => $this->offers['package']],
                    ['item_type' => 'hotel', 'currency' => 'EUR', 'offer_id' => $this->offers['hotel'], 'parent_index' => 1],
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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OrderService::create requires at least one item.');
        $this->service()->create(['currency' => 'EUR'], []);
    }

    public function test_it_requires_order_data_currency_when_items_present(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('orderData.currency is required.');

        $this->service()->create(
            [],
            [['item_type' => 'flight', 'currency' => 'EUR', 'offer_id' => $this->offers['flight']]]
        );
    }
}
