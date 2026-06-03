<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Orders;

use App\Models\MoneyFlowTerm;
use App\Models\OrderPricingSnapshot;
use App\Models\PricingRule;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1 / Step F — verifies OrderService writes an
 * order_pricing_snapshots row per item, with the full resolver +
 * money-flow payload merged into snapshot_json.
 */
class OrderSnapshotWiringTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    private int $offerId;

    private int $offerHotelId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Op '.uniqid(), 'type' => 'operator',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->offerId = DB::table('offers')->insertGetId([
            'company_id' => $this->companyId, 'type' => 'flight',
            'title' => 'Flight', 'price' => 100, 'currency' => 'EUR',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->offerHotelId = DB::table('offers')->insertGetId([
            'company_id' => $this->companyId, 'type' => 'hotel',
            'title' => 'Hotel', 'price' => 200, 'currency' => 'EUR',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_single_item_writes_one_snapshot_row(): void
    {
        $order = app(OrderService::class)->create(
            ['currency' => 'EUR'],
            [[
                'item_type' => 'flight',
                'currency' => 'EUR',
                'offer_id' => $this->offerId,
                'quantity' => 2,
            ]]
        );

        $snapshots = OrderPricingSnapshot::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $snapshots);

        $row = $snapshots->first();
        $this->assertSame($this->offerId, (int) $row->offer_id);
        $this->assertEquals(100, (float) $row->supplier_net);
        $this->assertEquals(115, (float) $row->customer_paid);
        $this->assertSame('EUR', $row->currency);
        $this->assertNull($row->rule_id_applied); // fallback engine (no seeded rules)
        $this->assertSame('legacy', $row->zulu_markup_type);
    }

    public function test_multi_item_order_writes_one_snapshot_per_item(): void
    {
        $order = app(OrderService::class)->create(
            ['currency' => 'EUR'],
            [
                ['item_type' => 'flight', 'currency' => 'EUR', 'offer_id' => $this->offerId, 'quantity' => 1],
                ['item_type' => 'hotel', 'currency' => 'EUR', 'offer_id' => $this->offerHotelId, 'quantity' => 1],
            ]
        );

        $snapshots = OrderPricingSnapshot::query()->where('order_id', $order->id)->get();
        $this->assertCount(2, $snapshots);
        $this->assertSame(
            [$this->offerId, $this->offerHotelId],
            $snapshots->pluck('offer_id')->map(fn ($id) => (int) $id)->sort()->values()->all()
        );
    }

    public function test_snapshot_json_contains_pricing_and_money_flow_payloads(): void
    {
        // Seed a pricing rule + money flow term so we get a real
        // rule_id_applied + money_flow_v1 in the snapshot.
        PricingRule::create([
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::TYPE_PERCENTAGE,
            'markup_value' => 20, 'currency' => 'EUR',
            'effective_from' => now()->subDay(), 'priority' => 50, 'is_active' => true,
        ]);
        MoneyFlowTerm::create([
            'scope_type' => MoneyFlowTerm::SCOPE_GLOBAL,
            'collection_model' => MoneyFlowTerm::MODEL_ZULU_COLLECTS,
            'remittance_days' => 7,
            'is_active' => true, 'effective_from' => now()->subDay(),
        ]);

        $order = app(OrderService::class)->create(
            ['currency' => 'EUR'],
            [[
                'item_type' => 'flight',
                'currency' => 'EUR',
                'offer_id' => $this->offerId,
                'quantity' => 1,
            ]]
        );

        $row = OrderPricingSnapshot::query()->where('order_id', $order->id)->firstOrFail();
        $json = $row->snapshot_json;

        $this->assertSame('phase_1_pricing_rules_v1', $json['engine']);
        $this->assertNotNull($row->rule_id_applied);
        $this->assertSame('percentage', $row->zulu_markup_type);
        $this->assertEquals(120, (float) $row->customer_paid);

        // Money-flow payload merged in.
        $this->assertArrayHasKey('money_flow', $json);
        $this->assertSame('phase_1_money_flow_customer_default', $json['money_flow']['engine']);
    }

    public function test_snapshot_is_immutable_no_updated_at_column(): void
    {
        // Sanity check that we're not accidentally tracking updates —
        // snapshots are write-once by design.
        $snapshot = new OrderPricingSnapshot;
        $this->assertFalse($snapshot->usesTimestamps());
    }
}
