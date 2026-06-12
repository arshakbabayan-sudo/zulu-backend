<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Hotel;
use App\Models\Offer;
use App\Models\OperatorAgentCommission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap §4 (2026-06-12) — CRM "My agents" per-agent sales aggregation.
 *
 * GET crm/my-agents/stats        — per-agent sales/revenue/commission rows
 * GET crm/my-agents/{id}/stats   — one agent's cards + destination/service
 *                                  breakdowns + order history
 *
 * Tenancy: every aggregate is computed ONLY over orders whose seller
 * (company_id) is in the caller's scope; commission rows are pinned to the
 * operator via the exact-suffix ';operator_company_id:<id>' notes tag.
 */
class CrmMyAgentsStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'company_admin', 'agent'] as $r) {
            Role::query()->firstOrCreate(['name' => $r]);
        }
    }

    private function roleId(string $name): int
    {
        return (int) Role::query()->where('name', $name)->value('id');
    }

    private function company(string $type): Company
    {
        return Company::query()->create(['name' => 'MA '.$type.' '.str()->uuid(), 'type' => $type]);
    }

    private function owner(Company $company): User
    {
        $owner = User::factory()->create();
        $owner->companies()->attach($company->id, ['role_id' => $this->roleId('company_admin')]);

        return $owner->fresh();
    }

    private function buyer(): User
    {
        return User::factory()->create();
    }

    private function order(Company $operator, ?Company $agent, User $buyer, float $total, string $status = 'confirmed', string $currency = 'USD'): Order
    {
        return Order::query()->create([
            'company_id' => $operator->id,
            'agent_company_id' => $agent?->id,
            'user_id' => $buyer->id,
            'order_number' => 'ORD-'.str()->upper(str()->random(10)),
            'buyer_type' => 'client',
            'status' => $status,
            'currency' => $currency,
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
        ]);
    }

    private function item(Order $order, string $itemType, float $price, ?int $itemId = null): OrderItem
    {
        return OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'quantity' => 1,
            'unit_price' => $price,
            'total' => $price,
            'currency' => $order->currency,
            'status' => 'pending',
        ]);
    }

    private function hotelIn(Company $company, int $locationId): Hotel
    {
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'MA Hotel '.str()->uuid(),
            'price' => 100,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        return Hotel::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'location_id' => $locationId,
            'hotel_name' => 'MA Hotel '.str()->uuid(),
            'property_type' => 'hotel',
            'hotel_type' => 'resort',
            'meal_type' => 'bed_and_breakfast',
            'is_package_eligible' => false,
            'status' => 'draft',
        ]);
    }

    private function superAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }

    // ─── List aggregation ───────────────────────────────────────────────────

    public function test_operator_sees_per_agent_aggregates_scoped_to_own_orders(): void
    {
        $operator = $this->company('operator');
        $caller = $this->owner($operator);
        $agentA = $this->company('agent');
        $agentB = $this->company('agent');
        $buyer = $this->buyer();

        // Agent A: two counted sales (100 + 50) with 3 service items.
        $o1 = $this->order($operator, $agentA, $buyer, 100.00);
        $this->item($o1, 'hotel', 60.00);
        $this->item($o1, 'transfer', 40.00);
        $o2 = $this->order($operator, $agentA, $buyer, 50.00, 'paid');
        $this->item($o2, 'excursion', 50.00);

        // Agent B: one counted sale.
        $o3 = $this->order($operator, $agentB, $buyer, 200.00);
        $this->item($o3, 'flight', 200.00);

        // Noise that must NOT count: uncounted status, direct sale (no agent),
        // and another operator's order through agent A.
        $this->order($operator, $agentA, $buyer, 999.00, 'cancelled');
        $this->order($operator, null, $buyer, 888.00);
        $otherOperator = $this->company('operator');
        $this->order($otherOperator, $agentA, $buyer, 777.00);

        Sanctum::actingAs($caller);

        $data = $this->getJson('/api/platform-admin/crm/my-agents/stats')
            ->assertOk()
            ->json('data');

        $byId = collect($data['agents'])->keyBy('agent_company_id');
        $this->assertCount(2, $byId);

        $a = $byId[$agentA->id];
        $this->assertSame(2, $a['sales']);
        $this->assertSame(3, $a['bookings']);
        $this->assertSame($agentA->name, $a['agent_name']);
        $this->assertEqualsWithDelta(150.0, $a['revenue'][0]['total'], 0.01);
        $this->assertSame('USD', $a['revenue'][0]['currency']);

        $b = $byId[$agentB->id];
        $this->assertSame(1, $b['sales']);
        $this->assertEqualsWithDelta(200.0, $b['revenue'][0]['total'], 0.01);

        $this->assertSame(3, $data['summary']['sales']);
        $this->assertSame(4, $data['summary']['bookings']);
        $this->assertEqualsWithDelta(350.0, $data['summary']['revenue'][0]['total'], 0.01);
    }

    public function test_commission_comes_from_entitlement_ledger_pinned_to_operator(): void
    {
        $operator = $this->company('operator');
        $caller = $this->owner($operator);
        $agent = $this->company('agent');
        $buyer = $this->buyer();

        // Real ledger rows: platform takes 10%, the agent's margin is 15% of
        // gross — produced by the SAME service that writes them in prod.
        $order = $this->order($operator, $agent, $buyer, 100.00);
        $this->item($order, 'flight', 100.00);
        CommissionRule::query()->create([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $operator->id,
            'percentage_value' => 10.0,
            'direction' => 'zulu_from_seller',
            'priority' => 0,
            'effective_from' => now()->subDay(),
            'status' => 'active',
            'active' => true,
        ]);
        OperatorAgentCommission::query()->create([
            'operator_company_id' => $operator->id,
            'agent_company_id' => null,
            'calculation_base' => 'gross',
            'default_percentage' => 15.0,
        ]);
        app(FinanceService::class)->createEntitlementsForOrder($order);

        // A DIFFERENT operator's agent-share row for the same agent company —
        // must NOT leak into this operator's commission column.
        $otherOperator = $this->company('operator');
        $foreignOrder = $this->order($otherOperator, $agent, $buyer, 500.00);
        \DB::table('supplier_entitlements')->insert([
            'company_id' => $agent->id,
            'service_type' => 'flight',
            'gross_amount' => 500.00,
            'commission_amount' => 0,
            'net_amount' => 75.00,
            'currency' => 'USD',
            'status' => 'accrued',
            'notes' => 'agent_share_of_order_id:'.$foreignOrder->id.';order_item_id:x;operator_company_id:'.$otherOperator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($caller);

        $data = $this->getJson('/api/platform-admin/crm/my-agents/stats')
            ->assertOk()
            ->json('data');

        $row = collect($data['agents'])->firstWhere('agent_company_id', $agent->id);
        $this->assertNotNull($row);
        $this->assertCount(1, $row['commission']);
        $this->assertEqualsWithDelta(15.0, $row['commission'][0]['total'], 0.01);
    }

    // ─── Detail breakdowns ──────────────────────────────────────────────────

    public function test_detail_returns_cards_destinations_services_and_history(): void
    {
        $operator = $this->company('operator');
        $caller = $this->owner($operator);
        $agent = $this->company('agent');
        $buyer = $this->buyer();

        $yerevan = $this->hotelIn($operator, $this->locationIds()['yerevan_city']);
        $gyumri = $this->hotelIn($operator, $this->locationIds()['gyumri_city']);

        $o1 = $this->order($operator, $agent, $buyer, 300.00);
        $this->item($o1, 'hotel', 200.00, (int) $yerevan->id);
        $this->item($o1, 'hotel', 100.00, (int) $gyumri->id);
        $o2 = $this->order($operator, $agent, $buyer, 80.00, 'paid');
        $this->item($o2, 'hotel', 50.00, (int) $yerevan->id);
        $this->item($o2, 'transfer', 30.00);

        // Cancelled order: excluded from cards, still visible in history.
        $this->order($operator, $agent, $buyer, 70.00, 'cancelled');

        Sanctum::actingAs($caller);

        $data = $this->getJson("/api/platform-admin/crm/my-agents/{$agent->id}/stats")
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['sales']);
        $this->assertSame(4, $data['bookings']);
        $this->assertEqualsWithDelta(380.0, $data['revenue'][0]['total'], 0.01);

        $dest = collect($data['destinations'])->keyBy('name');
        $this->assertSame(2, $dest['Yerevan']['bookings']);
        $this->assertSame(1, $dest['Gyumri']['bookings']);

        $svc = collect($data['services'])->keyBy('type');
        $this->assertSame(3, $svc['hotel']['bookings']);
        $this->assertSame(1, $svc['transfer']['bookings']);

        $this->assertCount(3, $data['orders']);
        $statuses = collect($data['orders'])->pluck('status');
        $this->assertTrue($statuses->contains('cancelled'));
        $first = collect($data['orders'])->firstWhere('status', 'confirmed');
        $this->assertSame($buyer->name, $first['customer']['name']);
    }

    public function test_detail_for_foreign_agent_returns_zeros_not_other_tenant_data(): void
    {
        $operatorA = $this->company('operator');
        $agent = $this->company('agent');
        $buyer = $this->buyer();
        $o = $this->order($operatorA, $agent, $buyer, 400.00);
        $this->item($o, 'flight', 400.00);

        // Operator B never sold through this agent — must see zeros even when
        // passing the agent's real id.
        $operatorB = $this->company('operator');
        Sanctum::actingAs($this->owner($operatorB));

        $data = $this->getJson("/api/platform-admin/crm/my-agents/{$agent->id}/stats")
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['sales']);
        $this->assertSame(0, $data['bookings']);
        $this->assertSame([], $data['revenue']);
        $this->assertSame([], $data['orders']);
    }

    public function test_super_sees_all_and_can_pin_one_operator(): void
    {
        $operatorA = $this->company('operator');
        $operatorB = $this->company('operator');
        $agent = $this->company('agent');
        $buyer = $this->buyer();
        $this->order($operatorA, $agent, $buyer, 100.00);
        $this->order($operatorB, $agent, $buyer, 250.00);

        Sanctum::actingAs($this->superAdmin());

        $all = $this->getJson('/api/platform-admin/crm/my-agents/stats')->assertOk()->json('data');
        $row = collect($all['agents'])->firstWhere('agent_company_id', $agent->id);
        $this->assertSame(2, $row['sales']);
        $this->assertEqualsWithDelta(350.0, $row['revenue'][0]['total'], 0.01);

        $pinned = $this->getJson('/api/platform-admin/crm/my-agents/stats?company_id='.$operatorA->id)
            ->assertOk()->json('data');
        $rowA = collect($pinned['agents'])->firstWhere('agent_company_id', $agent->id);
        $this->assertSame(1, $rowA['sales']);
        $this->assertEqualsWithDelta(100.0, $rowA['revenue'][0]['total'], 0.01);
    }

    public function test_operator_cannot_widen_scope_with_company_id_param(): void
    {
        $mine = $this->company('operator');
        $other = $this->company('operator');
        $agent = $this->company('agent');
        $buyer = $this->buyer();
        $this->order($other, $agent, $buyer, 5000.00);

        Sanctum::actingAs($this->owner($mine));

        // ?company_id pointing at ANOTHER operator is ignored for non-super.
        $data = $this->getJson('/api/platform-admin/crm/my-agents/stats?company_id='.$other->id)
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data['agents']);
        $this->assertSame(0, $data['summary']['sales']);
    }
}
