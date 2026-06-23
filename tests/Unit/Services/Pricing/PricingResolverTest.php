<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pricing;

use App\Models\PricingRule;
use App\Services\Pricing\PricingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1 / Step C.2 — verifies the 4-level priority resolver:
 *   partnership > operator > category > global
 *
 * Plus: percentage vs fixed markup math, bcmath precision, min/max
 * bounds, hierarchical destination matching, priority tie-breakers,
 * currency strictness, legacy fallback when no rule matches.
 */
class PricingResolverTest extends TestCase
{
    use RefreshDatabase;

    private int $operatorId;

    private int $otherOperatorId;

    private int $agentId;

    private int $offerId;

    private int $usdOfferId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operatorId = DB::table('companies')->insertGetId([
            'name' => 'Op '.uniqid(), 'type' => 'operator',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->otherOperatorId = DB::table('companies')->insertGetId([
            'name' => 'OtherOp '.uniqid(), 'type' => 'operator',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agentId = DB::table('companies')->insertGetId([
            'name' => 'Agent '.uniqid(), 'type' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->offerId = DB::table('offers')->insertGetId([
            'company_id' => $this->operatorId, 'type' => 'hotel',
            'title' => 'Hotel offer', 'price' => 100, 'currency' => 'EUR',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->usdOfferId = DB::table('offers')->insertGetId([
            'company_id' => $this->operatorId, 'type' => 'hotel',
            'title' => 'USD Hotel', 'price' => 200, 'currency' => 'USD',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeRule(array $overrides = []): PricingRule
    {
        return PricingRule::create(array_merge([
            'scope_type' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::TYPE_PERCENTAGE,
            'markup_value' => 15,
            'currency' => 'EUR',
            'effective_from' => now()->subDay(),
            'priority' => 100,
            'is_active' => true,
        ], $overrides));
    }

    private function resolver(): PricingResolver
    {
        return app(PricingResolver::class);
    }

    public function test_global_percentage_rule_applies_markup(): void
    {
        $this->makeRule(['markup_value' => 20]);

        $result = $this->resolver()->resolve($this->offerId, 1);

        $this->assertSame('phase_1_pricing_rules_v1', $result->snapshotPayload['engine']);
        // 100 * 1.20 = 120.00
        $this->assertSame(120.0, $result->customerPrice);
        $this->assertSame('EUR', $result->currency);
    }

    public function test_global_fixed_rule_adds_amount(): void
    {
        $this->makeRule(['markup_type' => PricingRule::TYPE_FIXED, 'markup_value' => 25]);

        $result = $this->resolver()->resolve($this->offerId, 1);

        // 100 + 25 fixed = 125
        $this->assertSame(125.0, $result->customerPrice);
    }

    public function test_operator_rule_beats_global_rule(): void
    {
        $this->makeRule(['markup_value' => 15]); // global
        $this->makeRule([
            'scope_type' => PricingRule::SCOPE_OPERATOR,
            'operator_id' => $this->operatorId,
            'markup_value' => 25,
        ]);

        $result = $this->resolver()->resolve($this->offerId, 1);

        // operator rule wins → 100 * 1.25 = 125
        $this->assertSame(125.0, $result->customerPrice);
    }

    public function test_partnership_rule_beats_operator_rule(): void
    {
        $this->makeRule(['markup_value' => 15]); // global
        $this->makeRule([
            'scope_type' => PricingRule::SCOPE_OPERATOR,
            'operator_id' => $this->operatorId,
            'markup_value' => 25,
        ]);
        $this->makeRule([
            'scope_type' => PricingRule::SCOPE_PARTNERSHIP,
            'operator_id' => $this->operatorId,
            'agent_id' => $this->agentId,
            'markup_value' => 10,
        ]);

        $result = $this->resolver()->resolve(
            $this->offerId,
            1,
            ['agent_company_id' => $this->agentId]
        );

        // partnership wins → 100 * 1.10 = 110
        $this->assertSame(110.0, $result->customerPrice);
    }

    public function test_category_rule_only_matches_matching_service_type(): void
    {
        // Category rule for "flight" only — should NOT match a hotel offer.
        $this->makeRule([
            'scope_type' => PricingRule::SCOPE_CATEGORY,
            'service_category' => 'flight',
            'markup_value' => 50,
        ]);

        $result = $this->resolver()->resolve($this->offerId, 1);

        // No matching rule → fallback to legacy 15% via PriceCalculatorService.
        $this->assertSame('operator_markup_percent', $result->snapshotPayload['engine']);
        $this->assertSame(115.0, $result->customerPrice);
    }

    public function test_category_rule_matches_hotel_offer(): void
    {
        $this->makeRule([
            'scope_type' => PricingRule::SCOPE_CATEGORY,
            'service_category' => 'hotel',
            'markup_value' => 30,
        ]);

        $result = $this->resolver()->resolve($this->offerId, 1);

        $this->assertSame(130.0, $result->customerPrice);
        $this->assertSame('category', $result->snapshotPayload['rule']['scope_type']);
    }

    public function test_currency_strict_matching(): void
    {
        // Only a USD rule exists; EUR offer should fall back.
        $this->makeRule(['markup_value' => 50, 'currency' => 'USD']);

        $result = $this->resolver()->resolve($this->offerId, 1); // EUR offer

        $this->assertSame('operator_markup_percent', $result->snapshotPayload['engine']);
    }

    public function test_min_sell_amount_floor(): void
    {
        // Tiny markup but a floor pulls it up.
        $this->makeRule([
            'markup_value' => 5,
            'min_sell_amount' => 130,
        ]);

        $result = $this->resolver()->resolve($this->offerId, 1);

        // 100 + 5% = 105 → bumped up to floor 130
        $this->assertSame(130.0, $result->customerPrice);
    }

    public function test_max_sell_amount_ceiling(): void
    {
        $this->makeRule([
            'markup_value' => 100,
            'max_sell_amount' => 150,
        ]);

        $result = $this->resolver()->resolve($this->offerId, 1);

        // 100 + 100% = 200 → clamped down to 150 ceiling
        $this->assertSame(150.0, $result->customerPrice);
    }

    public function test_priority_tiebreaker_within_same_scope(): void
    {
        $this->makeRule([
            'scope_type' => PricingRule::SCOPE_OPERATOR,
            'operator_id' => $this->operatorId,
            'markup_value' => 10,
            'priority' => 100,
        ]);
        $this->makeRule([
            'scope_type' => PricingRule::SCOPE_OPERATOR,
            'operator_id' => $this->operatorId,
            'markup_value' => 30,
            'priority' => 200, // higher priority wins
        ]);

        $result = $this->resolver()->resolve($this->offerId, 1);

        $this->assertSame(130.0, $result->customerPrice);
    }

    public function test_no_match_falls_back_to_legacy_b2c_markup(): void
    {
        // No rules in DB at all → fallback path.
        $result = $this->resolver()->resolve($this->offerId, 1);

        $this->assertNull($result->ruleIdApplied);
        $this->assertSame('operator_markup_percent', $result->snapshotPayload['engine']);
        $this->assertSame(115.0, $result->customerPrice);
    }

    public function test_price_override_replaces_supplier_net(): void
    {
        $this->makeRule(['markup_value' => 10]);

        $result = $this->resolver()->resolve(
            $this->offerId,
            1,
            ['price_override' => 50]
        );

        // 50 (override) + 10% = 55
        $this->assertSame(55.0, $result->customerPrice);
        $this->assertSame(50.0, $result->supplierNet);
    }

    public function test_bcmath_precision_on_awkward_percentages(): void
    {
        // 33.3333% on supplier net 100 = 133.3333 → rounded to 4 decimals.
        $this->makeRule(['markup_value' => 33.3333]);

        $result = $this->resolver()->resolve($this->offerId, 1);

        // bcmath at scale 4: 100 * 33.3333 = 3333.33300000; /100 = 33.3333; +100 = 133.3333
        $this->assertEqualsWithDelta(133.3333, $result->customerPrice, 0.0001);
    }
}
