<?php

namespace Tests\Unit\Services\Finance;

use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierEntitlement;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_entitlements_for_order_happy_path_percentage_rule(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $order = $this->createOrder($company, $user, 'USD', 100.00);
        $item = $this->createOrderItem($order, $company->id, 'flight', 100.00);

        $this->createRule([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => 'flight',
            'percentage_value' => 10.0,
        ]);

        $created = app(FinanceService::class)->createEntitlementsForOrder($order);

        $this->assertCount(1, $created);

        $entitlement = SupplierEntitlement::query()->where('notes', 'like', '%'.$item->id.'%')->firstOrFail();
        $this->assertSame($company->id, $entitlement->company_id);
        $this->assertSame('flight', $entitlement->service_type);
        $this->assertEqualsWithDelta(100.0, (float) $entitlement->gross_amount, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $entitlement->commission_amount, 0.0001);
        $this->assertEqualsWithDelta(90.0, (float) $entitlement->net_amount, 0.0001);
    }

    public function test_create_entitlements_for_order_happy_path_creates_row_per_item(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $order = $this->createOrder($company, $user, 'USD', 200.00, metadata: ['legacy_origin' => 'package_order']);
        $itemA = $this->createOrderItem($order, $company->id, 'flight', 80.00, 1);
        $itemB = $this->createOrderItem($order, $company->id, 'hotel', 120.00, 1);

        $this->createRule([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => null,
            'percentage_value' => 10.0,
        ]);

        $created = app(FinanceService::class)->createEntitlementsForOrder($order);

        $this->assertCount(2, $created);
        $this->assertSame(2, SupplierEntitlement::query()->count());
        $this->assertNotNull(SupplierEntitlement::query()->where('notes', 'like', '%'.$itemA->id.'%')->first());
        $this->assertNotNull(SupplierEntitlement::query()->where('notes', 'like', '%'.$itemB->id.'%')->first());
    }

    public function test_create_entitlements_for_order_is_not_idempotent(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $order = $this->createOrder($company, $user, 'USD', 100.00);
        $this->createOrderItem($order, $company->id, 'flight', 100.00);

        $this->createRule([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => 'flight',
            'percentage_value' => 10.0,
        ]);

        $first = app(FinanceService::class)->createEntitlementsForOrder($order);
        $second = app(FinanceService::class)->createEntitlementsForOrder($order);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertNotSame($first[0]->id, $second[0]->id);
        $this->assertSame(2, SupplierEntitlement::query()->count());
    }

    public function test_create_entitlements_for_order_without_rule_sets_zero_commission_and_net_equals_gross(): void
    {
        $company = $this->createCompany();
        $user = $this->createUser();
        $order = $this->createOrder($company, $user, 'USD', 125.00);
        $item = $this->createOrderItem($order, $company->id, 'flight', 125.00);

        $created = app(FinanceService::class)->createEntitlementsForOrder($order);

        $this->assertCount(1, $created);

        $entitlement = SupplierEntitlement::query()->where('notes', 'like', '%'.$item->id.'%')->firstOrFail();
        $this->assertEqualsWithDelta(125.0, (float) $entitlement->gross_amount, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $entitlement->commission_amount, 0.0001);
        $this->assertEqualsWithDelta(125.0, (float) $entitlement->net_amount, 0.0001);
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Finance Service Seller '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Finance Test User',
            'email' => 'finance-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);
    }

    private function createOrder(
        Company $company,
        User $user,
        string $currency,
        float $total,
        array $metadata = ['legacy_origin' => 'booking']
    ): Order {
        return Order::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'order_number' => 'ORD-'.str()->upper(str()->random(10)),
            'buyer_type' => 'client',
            'status' => 'confirmed',
            'currency' => $currency,
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'metadata' => $metadata,
        ]);
    }

    private function createOrderItem(
        Order $order,
        int $companyId,
        string $itemType,
        float $price,
        int $quantity = 1
    ): OrderItem {
        return OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => $itemType,
            'item_id' => null,
            'package_id' => null,
            'parent_item_id' => null,
            'quantity' => $quantity,
            'unit_price' => $price,
            'total' => $price * $quantity,
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRule(array $overrides = []): CommissionRule
    {
        return CommissionRule::query()->create(array_merge([
            'type' => 'percentage',
            'level' => 'global',
            'scope_id' => null,
            'service_type' => 'flight',
            'percentage_value' => 5.0,
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
            'notes' => 'finance service test rule',
            'created_by' => null,
        ], $overrides));
    }
}
