<?php

namespace Tests\Feature;

use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\OperatorAgentCommission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap §8 — money-flow dashboard aggregates.
 *
 * Regression guards for two bugs found in the 2026-06-11 scope:
 *  - commission_split was computed off a non-existent commission_transactions
 *    .agent_id column, so it ALWAYS read platform=ALL / agent=0.
 *  - total_commission_pending / pending_payouts were hardcoded 0.0.
 * Both are now derived from the supplier_entitlements ledger, where the agent's
 * share lives as dedicated "agent_share_of_order_id:" rows and the operator
 * row's commission_amount already includes that share.
 */
class FinanceMoneyFlowSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_summary_v2_reports_real_split_and_pending_payouts(): void
    {
        // Operator sells $100; platform takes 10% ($10) as commission; agent
        // (referrer) earns a 15% margin of gross ($15) carved out of operator net.
        $operator = $this->company('operator');
        $agent = $this->company('agent');
        $buyer = $this->user();
        $order = $this->order($operator, $agent, $buyer, 100.00);
        $this->orderItem($order, $operator->id, 'flight', 100.00);
        $this->platformRule($operator, 10.0);
        $this->agentConfig($operator, null, 15.0);

        app(FinanceService::class)->createEntitlementsForOrder($order);

        Sanctum::actingAs($this->platformAdmin());

        $data = $this->getJson('/api/platform-admin/finance-summary/v2?range=year')
            ->assertOk()
            ->json('data');

        // Agent split = the $15 agent_share row; platform = total entitlement
        // commission minus that share. Both must be > 0 (the old code returned 0).
        $this->assertGreaterThan(0, $data['commission_split']['agent'], 'agent split should be non-zero');
        $this->assertEqualsWithDelta(15.0, (float) $data['commission_split']['agent'], 0.01);
        $this->assertGreaterThan(0, $data['commission_split']['platform'], 'platform split should be non-zero');

        // Pending payouts = net still owed to sellers (accrued rows) — no
        // settlement yet, so it equals the sum of accrued net amounts (> 0).
        $this->assertArrayHasKey('pending_payouts', $data);
        $this->assertGreaterThan(0, $data['pending_payouts'], 'pending payouts should be non-zero');
        $this->assertSame($data['pending_payouts'], $data['total_commission_pending']);
    }

    private function company(string $type): Company
    {
        return Company::query()->create(['name' => 'MF '.$type.' '.str()->uuid(), 'type' => $type]);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'MF Buyer',
            'email' => 'mf-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);
    }

    private function order(Company $operator, ?Company $agent, User $user, float $total): Order
    {
        return Order::query()->create([
            'company_id' => $operator->id,
            'agent_company_id' => $agent?->id,
            'user_id' => $user->id,
            'order_number' => 'ORD-'.str()->upper(str()->random(10)),
            'buyer_type' => 'client',
            'status' => 'confirmed',
            'currency' => 'USD',
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'metadata' => ['legacy_origin' => 'booking'],
        ]);
    }

    private function orderItem(Order $order, int $companyId, string $itemType, float $price): OrderItem
    {
        return OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => $itemType,
            'quantity' => 1,
            'unit_price' => $price,
            'total' => $price,
            'currency' => $order->currency,
            'service_snapshot' => ['company_id' => $companyId, 'is_required' => true],
            'status' => 'pending',
        ]);
    }

    private function platformRule(Company $operator, float $percentage): CommissionRule
    {
        return CommissionRule::query()->create([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $operator->id,
            'service_type' => null,
            'percentage_value' => $percentage,
            'fixed_value' => null,
            'fixed_currency' => null,
            'hybrid_config' => null,
            'tiered_config' => null,
            'direction' => 'zulu_from_seller',
            'priority' => 0,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
            'status' => 'active',
            'active' => true,
            'notes' => 'money-flow split test platform rule',
            'created_by' => null,
        ]);
    }

    private function agentConfig(Company $operator, ?Company $agent, float $percentage): OperatorAgentCommission
    {
        return OperatorAgentCommission::query()->create([
            'operator_company_id' => $operator->id,
            'agent_company_id' => $agent?->id,
            'calculation_base' => 'gross',
            'custom_base_percentage' => null,
            'default_percentage' => $percentage,
            'notes' => null,
            'created_by_user_id' => null,
        ]);
    }

    private function platformAdmin(): User
    {
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }
}
