<?php

namespace Tests\Unit\Services\Finance;

use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\OperatorAgentCommission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierEntitlement;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6B Part 2 — agent commission split during entitlement accrual.
 *
 * Covers the matrix of (config present / absent), each calculation_base mode,
 * and the per-agent override falling back to operator default. Mirrors the
 * scenarios spelled out in the Phase 6B roadmap.
 */
class FinanceServiceAgentCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_agent_referral_creates_single_operator_entitlement(): void
    {
        $operator = $this->createCompany('operator');
        $user = $this->createUser();
        $order = $this->createOrder($operator, null, $user, 'USD', 100.00);
        $this->createOrderItem($order, $operator->id, 'flight', 100.00);
        $this->createPlatformRule($operator, 10.0);

        $created = app(FinanceService::class)->createEntitlementsForOrder($order);

        $this->assertCount(1, $created);
        $this->assertSame($operator->id, $created[0]->company_id);
        $this->assertEqualsWithDelta(90.0, (float) $created[0]->net_amount, 0.0001);
    }

    public function test_agent_referral_without_config_keeps_operator_entitlement_only(): void
    {
        $operator = $this->createCompany('operator');
        $agent = $this->createCompany('agent');
        $user = $this->createUser();
        $order = $this->createOrder($operator, $agent, $user, 'USD', 100.00);
        $this->createOrderItem($order, $operator->id, 'flight', 100.00);
        $this->createPlatformRule($operator, 10.0);

        $created = app(FinanceService::class)->createEntitlementsForOrder($order);

        $this->assertCount(1, $created);
        $this->assertSame(0, SupplierEntitlement::query()->where('company_id', $agent->id)->count());
    }

    public function test_default_config_gross_base_creates_agent_entitlement(): void
    {
        $operator = $this->createCompany('operator');
        $agent = $this->createCompany('agent');
        $user = $this->createUser();
        $order = $this->createOrder($operator, $agent, $user, 'USD', 200.00);
        $this->createOrderItem($order, $operator->id, 'flight', 200.00);
        $this->createPlatformRule($operator, 10.0); // platform takes 20.00
        $this->createCommissionConfig($operator, null, 'gross', null, 5.0); // agent gets 5% of 200 = 10

        $created = app(FinanceService::class)->createEntitlementsForOrder($order);

        $this->assertCount(2, $created);

        $operatorRow = SupplierEntitlement::query()->where('company_id', $operator->id)->firstOrFail();
        $agentRow = SupplierEntitlement::query()->where('company_id', $agent->id)->firstOrFail();

        $this->assertEqualsWithDelta(200.0, (float) $operatorRow->gross_amount, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $operatorRow->commission_amount, 0.01); // 20 platform + 10 agent
        $this->assertEqualsWithDelta(170.0, (float) $operatorRow->net_amount, 0.01);

        $this->assertEqualsWithDelta(10.0, (float) $agentRow->gross_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $agentRow->commission_amount, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $agentRow->net_amount, 0.01);
    }

    public function test_post_platform_fee_base_uses_operator_net(): void
    {
        $operator = $this->createCompany('operator');
        $agent = $this->createCompany('agent');
        $user = $this->createUser();
        $order = $this->createOrder($operator, $agent, $user, 'USD', 200.00);
        $this->createOrderItem($order, $operator->id, 'flight', 200.00);
        $this->createPlatformRule($operator, 10.0); // platform 20, operator_net = 180
        $this->createCommissionConfig($operator, null, 'post_platform_fee', null, 10.0); // agent gets 10% of 180 = 18

        app(FinanceService::class)->createEntitlementsForOrder($order);

        $agentRow = SupplierEntitlement::query()->where('company_id', $agent->id)->firstOrFail();
        $this->assertEqualsWithDelta(18.0, (float) $agentRow->net_amount, 0.01);

        $operatorRow = SupplierEntitlement::query()->where('company_id', $operator->id)->firstOrFail();
        $this->assertEqualsWithDelta(162.0, (float) $operatorRow->net_amount, 0.01); // 200 - 20 - 18
    }

    public function test_custom_base_uses_explicit_percentage_of_gross(): void
    {
        $operator = $this->createCompany('operator');
        $agent = $this->createCompany('agent');
        $user = $this->createUser();
        $order = $this->createOrder($operator, $agent, $user, 'USD', 200.00);
        $this->createOrderItem($order, $operator->id, 'flight', 200.00);
        $this->createPlatformRule($operator, 10.0);
        // base = 50% of gross = 100; agent = 20% of 100 = 20
        $this->createCommissionConfig($operator, null, 'custom', 50.0, 20.0);

        app(FinanceService::class)->createEntitlementsForOrder($order);

        $agentRow = SupplierEntitlement::query()->where('company_id', $agent->id)->firstOrFail();
        $this->assertEqualsWithDelta(20.0, (float) $agentRow->net_amount, 0.01);
    }

    public function test_per_agent_override_wins_over_default(): void
    {
        $operator = $this->createCompany('operator');
        $agent = $this->createCompany('agent');
        $user = $this->createUser();
        $order = $this->createOrder($operator, $agent, $user, 'USD', 100.00);
        $this->createOrderItem($order, $operator->id, 'flight', 100.00);
        $this->createPlatformRule($operator, 10.0);
        $this->createCommissionConfig($operator, null, 'gross', null, 5.0); // default 5%
        $this->createCommissionConfig($operator, $agent, 'gross', null, 15.0); // override 15%

        app(FinanceService::class)->createEntitlementsForOrder($order);

        $agentRow = SupplierEntitlement::query()->where('company_id', $agent->id)->firstOrFail();
        $this->assertEqualsWithDelta(15.0, (float) $agentRow->net_amount, 0.01);
    }

    public function test_self_referral_does_not_create_agent_entitlement(): void
    {
        $operator = $this->createCompany('operator');
        $user = $this->createUser();
        $order = $this->createOrder($operator, $operator, $user, 'USD', 100.00);
        $this->createOrderItem($order, $operator->id, 'flight', 100.00);
        $this->createPlatformRule($operator, 10.0);
        $this->createCommissionConfig($operator, null, 'gross', null, 5.0);

        $created = app(FinanceService::class)->createEntitlementsForOrder($order);

        $this->assertCount(1, $created);
    }

    public function test_zero_agent_percentage_does_not_create_agent_row(): void
    {
        $operator = $this->createCompany('operator');
        $agent = $this->createCompany('agent');
        $user = $this->createUser();
        $order = $this->createOrder($operator, $agent, $user, 'USD', 100.00);
        $this->createOrderItem($order, $operator->id, 'flight', 100.00);
        $this->createPlatformRule($operator, 10.0);
        $this->createCommissionConfig($operator, null, 'gross', null, 0.0);

        $created = app(FinanceService::class)->createEntitlementsForOrder($order);

        $this->assertCount(1, $created);
        $this->assertSame(0, SupplierEntitlement::query()->where('company_id', $agent->id)->count());
    }

    private function createCompany(string $type): Company
    {
        return Company::query()->create([
            'name' => 'Agent Commission '.$type.' '.str()->uuid(),
            'type' => $type,
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Agent Commission Test',
            'email' => 'agent-comm-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);
    }

    private function createOrder(
        Company $operator,
        ?Company $agent,
        User $user,
        string $currency,
        float $total,
    ): Order {
        return Order::query()->create([
            'company_id' => $operator->id,
            'agent_company_id' => $agent?->id,
            'user_id' => $user->id,
            'order_number' => 'ORD-'.str()->upper(str()->random(10)),
            'buyer_type' => 'client',
            'status' => 'confirmed',
            'currency' => $currency,
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'metadata' => ['legacy_origin' => 'booking'],
        ]);
    }

    private function createOrderItem(Order $order, int $companyId, string $itemType, float $price): OrderItem
    {
        return OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => $itemType,
            'item_id' => null,
            'package_id' => null,
            'parent_item_id' => null,
            'quantity' => 1,
            'unit_price' => $price,
            'total' => $price,
            'currency' => $order->currency,
            'service_snapshot' => [
                'company_id' => $companyId,
                'is_required' => true,
            ],
            'passenger_data' => null,
            'date_from' => null,
            'date_to' => null,
            'status' => 'pending',
            'external_ref' => null,
        ]);
    }

    private function createPlatformRule(Company $operator, float $percentage): CommissionRule
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
            'notes' => 'agent commission test platform rule',
            'created_by' => null,
        ]);
    }

    private function createCommissionConfig(
        Company $operator,
        ?Company $agent,
        string $calculationBase,
        ?float $customBasePercentage,
        float $defaultPercentage,
    ): OperatorAgentCommission {
        return OperatorAgentCommission::query()->create([
            'operator_company_id' => $operator->id,
            'agent_company_id' => $agent?->id,
            'calculation_base' => $calculationBase,
            'custom_base_percentage' => $customBasePercentage,
            'default_percentage' => $defaultPercentage,
            'notes' => null,
            'created_by_user_id' => null,
        ]);
    }
}
