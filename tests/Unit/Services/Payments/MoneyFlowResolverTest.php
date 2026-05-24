<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payments;

use App\Models\MoneyFlowTerm;
use App\Services\Payments\MoneyFlowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MoneyFlowResolverTest extends TestCase
{
    use RefreshDatabase;

    private int $operatorId;

    private int $agentId;

    private int $otherAgentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operatorId = DB::table('companies')->insertGetId([
            'name' => 'Op '.uniqid(), 'type' => 'operator', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agentId = DB::table('companies')->insertGetId([
            'name' => 'Agent '.uniqid(), 'type' => 'agent', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->otherAgentId = DB::table('companies')->insertGetId([
            'name' => 'OtherAgent '.uniqid(), 'type' => 'agent', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeTerm(array $overrides = []): MoneyFlowTerm
    {
        return MoneyFlowTerm::create(array_merge([
            'scope_type' => MoneyFlowTerm::SCOPE_GLOBAL,
            'collection_model' => MoneyFlowTerm::MODEL_ZULU_COLLECTS,
            'remittance_days' => 15,
            'is_active' => true,
            'effective_from' => now()->subDay(),
        ], $overrides));
    }

    private function resolver(): MoneyFlowResolver
    {
        return app(MoneyFlowResolver::class);
    }

    public function test_customer_booking_always_uses_zulu_collects(): void
    {
        // Even if an operator default is operator_collects, customer
        // bookings get forced to zulu_collects per spec.
        $this->makeTerm([
            'scope_type' => MoneyFlowTerm::SCOPE_OPERATOR,
            'operator_id' => $this->operatorId,
            'collection_model' => MoneyFlowTerm::MODEL_OPERATOR_COLLECTS,
            'remittance_days' => null,
            'invoicing_period' => 'monthly',
        ]);

        $result = $this->resolver()->resolve([
            'operator_id' => $this->operatorId,
            'agent_company_id' => null,
            'buyer_type' => 'client',
        ]);

        $this->assertSame(MoneyFlowTerm::MODEL_ZULU_COLLECTS, $result->collectionModel);
        $this->assertSame(15, $result->remittanceDays); // platform default
    }

    public function test_customer_booking_uses_operator_remittance_days_when_zulu_collects(): void
    {
        // If operator default is zulu_collects with T+7, customer
        // bookings should pick up that T+7.
        $this->makeTerm([
            'scope_type' => MoneyFlowTerm::SCOPE_OPERATOR,
            'operator_id' => $this->operatorId,
            'collection_model' => MoneyFlowTerm::MODEL_ZULU_COLLECTS,
            'remittance_days' => 7,
        ]);

        $result = $this->resolver()->resolve([
            'operator_id' => $this->operatorId,
            'agent_company_id' => null,
            'buyer_type' => 'client',
        ]);

        $this->assertSame(MoneyFlowTerm::MODEL_ZULU_COLLECTS, $result->collectionModel);
        $this->assertSame(7, $result->remittanceDays);
        $this->assertSame('operator_default_used_for_customer', $result->scopeMatched);
    }

    public function test_agent_booking_partnership_beats_operator(): void
    {
        $this->makeTerm([
            'scope_type' => MoneyFlowTerm::SCOPE_OPERATOR,
            'operator_id' => $this->operatorId,
            'collection_model' => MoneyFlowTerm::MODEL_ZULU_COLLECTS,
            'remittance_days' => 15,
        ]);
        $this->makeTerm([
            'scope_type' => MoneyFlowTerm::SCOPE_PARTNERSHIP,
            'operator_id' => $this->operatorId,
            'agent_id' => $this->agentId,
            'collection_model' => MoneyFlowTerm::MODEL_OPERATOR_COLLECTS,
            'remittance_days' => null,
            'invoicing_period' => 'monthly',
        ]);

        $result = $this->resolver()->resolve([
            'operator_id' => $this->operatorId,
            'agent_company_id' => $this->agentId,
            'buyer_type' => 'agent',
        ]);

        $this->assertSame(MoneyFlowTerm::MODEL_OPERATOR_COLLECTS, $result->collectionModel);
        $this->assertSame('monthly', $result->invoicingPeriod);
        $this->assertSame('partnership', $result->scopeMatched);
    }

    public function test_agent_booking_operator_beats_global(): void
    {
        $this->makeTerm(); // global Model A T+15
        $this->makeTerm([
            'scope_type' => MoneyFlowTerm::SCOPE_OPERATOR,
            'operator_id' => $this->operatorId,
            'collection_model' => MoneyFlowTerm::MODEL_AGENT_COLLECTS,
            'remittance_days' => null,
        ]);

        $result = $this->resolver()->resolve([
            'operator_id' => $this->operatorId,
            'agent_company_id' => $this->agentId,
            'buyer_type' => 'agent',
        ]);

        $this->assertSame(MoneyFlowTerm::MODEL_AGENT_COLLECTS, $result->collectionModel);
        $this->assertSame('operator', $result->scopeMatched);
    }

    public function test_agent_booking_falls_back_to_global(): void
    {
        $this->makeTerm([
            'collection_model' => MoneyFlowTerm::MODEL_OPERATOR_COLLECTS,
            'remittance_days' => null,
            'invoicing_period' => 'weekly',
        ]);

        $result = $this->resolver()->resolve([
            'operator_id' => $this->operatorId,
            'agent_company_id' => $this->agentId,
            'buyer_type' => 'agent',
        ]);

        $this->assertSame(MoneyFlowTerm::MODEL_OPERATOR_COLLECTS, $result->collectionModel);
        $this->assertSame('weekly', $result->invoicingPeriod);
        $this->assertSame('global', $result->scopeMatched);
    }

    public function test_no_terms_at_all_returns_safe_default(): void
    {
        // No terms in DB. Agent booking should still resolve to A/T+15.
        $result = $this->resolver()->resolve([
            'operator_id' => $this->operatorId,
            'agent_company_id' => $this->agentId,
            'buyer_type' => 'agent',
        ]);

        $this->assertSame(MoneyFlowTerm::MODEL_ZULU_COLLECTS, $result->collectionModel);
        $this->assertSame(15, $result->remittanceDays);
        $this->assertNull($result->termIdApplied);
        $this->assertSame('platform_default', $result->scopeMatched);
    }
}
