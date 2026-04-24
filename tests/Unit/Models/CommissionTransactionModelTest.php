<?php

namespace Tests\Unit\Models;

use App\Models\CommissionResolutionLog;
use App\Models\CommissionRule;
use App\Models\CommissionTransaction;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CommissionTransactionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_and_casts_commission_transaction_fields(): void
    {
        $rule = $this->createRule();
        $seller = $this->createSeller();

        $created = CommissionTransaction::query()->create([
            'order_id' => 501,
            'order_item_id' => 601,
            'rule_id' => $rule->id,
            'seller_id' => $seller->id,
            'base_amount' => 125.5,
            'base_currency' => 'USD',
            'commission_amount' => 10.04,
            'commission_currency' => 'USD',
            'net_to_seller' => 115.46,
            'fx_rate' => 1.0,
            'snapshot' => ['level' => 'seller', 'candidate_count' => 2],
            'computed_at' => now()->subMinute(),
        ]);

        $transaction = CommissionTransaction::query()->findOrFail($created->id);

        $this->assertIsArray($transaction->snapshot);
        $this->assertInstanceOf(Carbon::class, $transaction->computed_at);
        $this->assertIsString($transaction->base_amount);
    }

    public function test_it_links_resolution_logs_with_transaction_relationships(): void
    {
        $rule = $this->createRule();
        $seller = $this->createSeller();
        $transaction = CommissionTransaction::query()->create([
            'order_id' => 701,
            'order_item_id' => 801,
            'rule_id' => $rule->id,
            'seller_id' => $seller->id,
            'base_amount' => 200,
            'base_currency' => 'USD',
            'commission_amount' => 25,
            'commission_currency' => 'USD',
            'net_to_seller' => 175,
            'fx_rate' => 1,
            'snapshot' => ['source' => 'test'],
            'computed_at' => now(),
        ]);

        $log = CommissionResolutionLog::query()->create([
            'transaction_id' => $transaction->id,
            'candidate_rules' => [$rule->id],
            'chosen_rule_id' => $rule->id,
            'reason' => 'seller match',
        ]);

        $logs = $transaction->resolutionLogs()->get();

        $this->assertCount(1, $logs);
        $this->assertTrue($logs->first()->is($log));
        $this->assertTrue($log->transaction()->firstOrFail()->is($transaction));
    }

    private function createSeller(): Company
    {
        return Company::query()->create([
            'name' => 'Commission Tx Seller '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
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
            'notes' => 'commission transaction model test rule',
            'created_by' => null,
        ], $overrides));
    }
}
