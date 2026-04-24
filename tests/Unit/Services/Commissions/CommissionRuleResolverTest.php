<?php

namespace Tests\Unit\Services\Commissions;

use App\Models\CommissionRule;
use App\Services\Commissions\CommissionRuleResolver;
use App\Services\Commissions\DTOs\CommissionResolutionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionRuleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_agreement_level_fires_when_partner_agreement_id_provided_and_matching_rule_exists(): void
    {
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
        ]);
        $seller = $this->createRule([
            'level' => 'seller',
            'scope_id' => 1001,
        ]);
        $category = $this->createRule([
            'level' => 'category',
            'scope_id' => 2001,
        ]);
        $partner = $this->createRule([
            'level' => 'partner_agreement',
            'scope_id' => 3001,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 1001,
                serviceType: 'flight',
                opts: ['categoryId' => 2001, 'partnerAgreementId' => 3001]
            )
        );

        $this->assertSame('partner_agreement', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($partner->id, $result->chosenRule->id);
        $this->assertNotSame($global->id, $result->chosenRule->id);
        $this->assertNotSame($seller->id, $result->chosenRule->id);
        $this->assertNotSame($category->id, $result->chosenRule->id);
    }

    public function test_seller_level_fires_when_no_partner_agreement_match_and_seller_rule_exists(): void
    {
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
        ]);
        $seller = $this->createRule([
            'level' => 'seller',
            'scope_id' => 1101,
        ]);
        $category = $this->createRule([
            'level' => 'category',
            'scope_id' => 2101,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 1101,
                serviceType: 'flight',
                opts: ['categoryId' => 2101, 'partnerAgreementId' => 9999]
            )
        );

        $this->assertSame('seller', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($seller->id, $result->chosenRule->id);
        $this->assertNotSame($global->id, $result->chosenRule->id);
        $this->assertNotSame($category->id, $result->chosenRule->id);
    }

    public function test_category_level_fires_when_no_partner_agreement_or_seller_match_and_category_rule_exists(): void
    {
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
        ]);
        $category = $this->createRule([
            'level' => 'category',
            'scope_id' => 2201,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 1201,
                serviceType: 'flight',
                opts: ['categoryId' => 2201]
            )
        );

        $this->assertSame('category', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($category->id, $result->chosenRule->id);
        $this->assertNotSame($global->id, $result->chosenRule->id);
    }

    public function test_global_level_fires_as_fallback(): void
    {
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 1301,
                serviceType: 'flight',
                opts: ['categoryId' => 2301]
            )
        );

        $this->assertSame('global', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($global->id, $result->chosenRule->id);
    }

    public function test_inactive_rule_is_ignored_when_active_false(): void
    {
        $inactiveSeller = $this->createRule([
            'level' => 'seller',
            'scope_id' => 1401,
            'active' => false,
        ]);
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 1401,
                serviceType: 'flight'
            )
        );

        $this->assertSame('global', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($global->id, $result->chosenRule->id);
        $this->assertNotSame($inactiveSeller->id, $result->chosenRule->id);
    }

    public function test_expired_rule_is_ignored_when_effective_to_is_before_now(): void
    {
        $expiredSeller = $this->createRule([
            'level' => 'seller',
            'scope_id' => 1501,
            'effective_to' => now()->subMinute(),
        ]);
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
            'effective_to' => null,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 1501,
                serviceType: 'flight',
                opts: ['atTime' => now()]
            )
        );

        $this->assertSame('global', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($global->id, $result->chosenRule->id);
        $this->assertNotSame($expiredSeller->id, $result->chosenRule->id);
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
            'notes' => 'resolver test rule',
            'created_by' => null,
        ], $overrides));
    }
}
