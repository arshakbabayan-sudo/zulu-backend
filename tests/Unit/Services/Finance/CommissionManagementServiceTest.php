<?php

namespace Tests\Unit\Services\Finance;

use App\Models\CommissionRule;
use App\Models\Company;
use App\Services\Finance\CommissionManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_commission_with_percentage_rule(): void
    {
        $company = $this->createCompany();
        $this->createRule([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => 'general',
            'percentage_value' => 10.0,
            'fixed_value' => null,
        ]);

        $result = app(CommissionManagementService::class)->applyCommission(100.0, $company->id, 'general');

        $this->assertSame(110.0, $result['client_price']);
        $this->assertSame(10.0, $result['commission_amount']);
    }

    public function test_apply_commission_with_fixed_rule(): void
    {
        $company = $this->createCompany();
        $this->createRule([
            'type' => 'fixed',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => 'general',
            'percentage_value' => null,
            'fixed_value' => 12.0,
            'fixed_currency' => 'USD',
        ]);

        $result = app(CommissionManagementService::class)->applyCommission(100.0, $company->id, 'general');

        $this->assertSame(112.0, $result['client_price']);
        $this->assertSame(12.0, $result['commission_amount']);
    }

    public function test_apply_commission_with_hybrid_rule(): void
    {
        $company = $this->createCompany();
        $this->createRule([
            'type' => 'hybrid',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => 'general',
            'percentage_value' => 10.0,
            'fixed_value' => 5.0,
            'fixed_currency' => 'USD',
            'hybrid_config' => ['mode' => 'sum'],
        ]);

        $result = app(CommissionManagementService::class)->applyCommission(100.0, $company->id, 'general');

        $this->assertSame(115.0, $result['client_price']);
        $this->assertSame(15.0, $result['commission_amount']);
    }

    public function test_apply_commission_returns_zero_when_no_rule_found(): void
    {
        $company = $this->createCompany();

        $result = app(CommissionManagementService::class)->applyCommission(100.0, $company->id, 'general');

        $this->assertSame(100.0, $result['client_price']);
        $this->assertSame(0.0, $result['commission_amount']);
    }

    public function test_apply_commission_ignores_inactive_rule_and_returns_zero(): void
    {
        $company = $this->createCompany();
        $this->createRule([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => 'general',
            'percentage_value' => 25.0,
            'active' => false,
        ]);

        $result = app(CommissionManagementService::class)->applyCommission(100.0, $company->id, 'general');

        $this->assertSame(100.0, $result['client_price']);
        $this->assertSame(0.0, $result['commission_amount']);
    }

    public function test_apply_commission_fixed_currency_mismatch_uses_literal_fixed_value_without_fx_conversion(): void
    {
        $company = $this->createCompany();
        $this->createRule([
            'type' => 'fixed',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => 'general',
            'percentage_value' => null,
            'fixed_value' => 7.0,
            'fixed_currency' => 'EUR',
        ]);

        $result = app(CommissionManagementService::class)->applyCommission(100.0, $company->id, 'general');

        // This layer has no base-currency input and performs no FX conversion, same as FinanceService fixed rule path.
        $this->assertSame(107.0, $result['client_price']);
        $this->assertSame(7.0, $result['commission_amount']);
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Commission Mgmt Seller '.str()->uuid(),
            'type' => 'operator',
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
            'service_type' => 'general',
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
            'notes' => 'commission management service test rule',
            'created_by' => null,
        ], $overrides));
    }
}
