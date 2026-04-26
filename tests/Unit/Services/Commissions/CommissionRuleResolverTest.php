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

    // Level priority chain
    /**
     * @dataProvider levelPriorityChainProvider
     */
    public function test_level_priority_chain_resolves_expected_level_and_rule(
        array $rules,
        array $ctxOpts,
        string $expectedLevel,
        ?string $expectedKey
    ): void {
        $created = [];
        foreach ($rules as $key => $overrides) {
            $created[$key] = $this->createRule($overrides);
        }

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 1601,
                serviceType: 'flight',
                opts: $ctxOpts
            )
        );

        $this->assertSame($expectedLevel, $result->level);
        if ($expectedKey === null) {
            $this->assertNull($result->chosenRule);

            return;
        }

        $this->assertNotNull($result->chosenRule);
        $this->assertSame($created[$expectedKey]->id, $result->chosenRule->id);
    }

    public static function levelPriorityChainProvider(): array
    {
        return [
            'partner-only' => [
                'rules' => [
                    'partner' => ['level' => 'partner_agreement', 'scope_id' => 3601],
                ],
                'ctxOpts' => ['partnerAgreementId' => 3601, 'categoryId' => 2601],
                'expectedLevel' => 'partner_agreement',
                'expectedKey' => 'partner',
            ],
            'seller-only' => [
                'rules' => [
                    'seller' => ['level' => 'seller', 'scope_id' => 1601],
                ],
                'ctxOpts' => ['partnerAgreementId' => 3602, 'categoryId' => 2602],
                'expectedLevel' => 'seller',
                'expectedKey' => 'seller',
            ],
            'category-only' => [
                'rules' => [
                    'category' => ['level' => 'category', 'scope_id' => 2603],
                ],
                'ctxOpts' => ['partnerAgreementId' => 3603, 'categoryId' => 2603],
                'expectedLevel' => 'category',
                'expectedKey' => 'category',
            ],
            'global-only' => [
                'rules' => [
                    'global' => ['level' => 'global', 'scope_id' => null],
                ],
                'ctxOpts' => ['partnerAgreementId' => 3604, 'categoryId' => 2604],
                'expectedLevel' => 'global',
                'expectedKey' => 'global',
            ],
            'no-rule' => [
                'rules' => [],
                'ctxOpts' => ['partnerAgreementId' => 3605, 'categoryId' => 2605],
                'expectedLevel' => 'none',
                'expectedKey' => null,
            ],
        ];
    }

    public function test_priority_with_all_scope_ids_set_chooses_highest_priority_matching_level_only(): void
    {
        $partner = $this->createRule([
            'level' => 'partner_agreement',
            'scope_id' => 3701,
        ]);
        $seller = $this->createRule([
            'level' => 'seller',
            'scope_id' => 1701,
        ]);
        $category = $this->createRule([
            'level' => 'category',
            'scope_id' => 2701,
        ]);
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 1701,
                serviceType: 'flight',
                opts: ['partnerAgreementId' => 3701, 'categoryId' => 2701]
            )
        );

        $this->assertSame('partner_agreement', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($partner->id, $result->chosenRule->id);
        $this->assertNotSame($seller->id, $result->chosenRule->id);
        $this->assertNotSame($category->id, $result->chosenRule->id);
        $this->assertNotSame($global->id, $result->chosenRule->id);
    }

    public function test_with_no_scope_ids_only_global_can_match(): void
    {
        $this->createRule([
            'level' => 'seller',
            'scope_id' => 1801,
        ]);
        $this->createRule([
            'level' => 'category',
            'scope_id' => 2801,
        ]);
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 9999,
                serviceType: 'flight'
            )
        );

        $this->assertSame('global', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($global->id, $result->chosenRule->id);
    }

    // Level fallback
    /**
     * @dataProvider levelFallbackProvider
     */
    public function test_level_fallback_chain_skips_missing_or_expired_higher_levels(
        array $rules,
        array $ctxOpts,
        string $expectedLevel,
        string $expectedKey
    ): void {
        $created = [];
        foreach ($rules as $key => $overrides) {
            $created[$key] = $this->createRule($overrides);
        }

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 1901,
                serviceType: 'flight',
                opts: $ctxOpts
            )
        );

        $this->assertSame($expectedLevel, $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($created[$expectedKey]->id, $result->chosenRule->id);
    }

    public static function levelFallbackProvider(): array
    {
        return [
            'partner-to-seller' => [
                'rules' => [
                    'seller' => ['level' => 'seller', 'scope_id' => 1901],
                ],
                'ctxOpts' => ['partnerAgreementId' => 3901, 'categoryId' => 2901],
                'expectedLevel' => 'seller',
                'expectedKey' => 'seller',
            ],
            'partner-to-category' => [
                'rules' => [
                    'category' => ['level' => 'category', 'scope_id' => 2902],
                ],
                'ctxOpts' => ['partnerAgreementId' => 3902, 'categoryId' => 2902],
                'expectedLevel' => 'category',
                'expectedKey' => 'category',
            ],
            'partner-to-global' => [
                'rules' => [
                    'global' => ['level' => 'global', 'scope_id' => null],
                ],
                'ctxOpts' => ['partnerAgreementId' => 3903, 'categoryId' => 2903],
                'expectedLevel' => 'global',
                'expectedKey' => 'global',
            ],
            'seller-to-category' => [
                'rules' => [
                    'category' => ['level' => 'category', 'scope_id' => 2904],
                ],
                'ctxOpts' => ['categoryId' => 2904],
                'expectedLevel' => 'category',
                'expectedKey' => 'category',
            ],
            'seller-to-global' => [
                'rules' => [
                    'global' => ['level' => 'global', 'scope_id' => null],
                ],
                'ctxOpts' => ['categoryId' => 2905],
                'expectedLevel' => 'global',
                'expectedKey' => 'global',
            ],
            'category-to-global' => [
                'rules' => [
                    'global' => ['level' => 'global', 'scope_id' => null],
                ],
                'ctxOpts' => ['categoryId' => 2906],
                'expectedLevel' => 'global',
                'expectedKey' => 'global',
            ],
        ];
    }

    public function test_partner_agreement_rule_expired_falls_back_to_seller_rule(): void
    {
        $expiredPartner = $this->createRule([
            'level' => 'partner_agreement',
            'scope_id' => 3999,
            'effective_to' => now()->subMinute(),
        ]);
        $seller = $this->createRule([
            'level' => 'seller',
            'scope_id' => 1999,
            'effective_to' => null,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 1999,
                serviceType: 'flight',
                opts: ['partnerAgreementId' => 3999, 'atTime' => now()]
            )
        );

        $this->assertSame('seller', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($seller->id, $result->chosenRule->id);
        $this->assertNotSame($expiredPartner->id, $result->chosenRule->id);
    }

    // Scope mismatch
    /**
     * @dataProvider scopeMismatchProvider
     */
    public function test_scope_mismatch_at_level_falls_through_to_lower_level_or_none(
        array $rules,
        array $ctxOpts,
        string $expectedLevel,
        ?string $expectedKey
    ): void {
        $created = [];
        foreach ($rules as $key => $overrides) {
            $created[$key] = $this->createRule($overrides);
        }

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 2001,
                serviceType: 'flight',
                opts: $ctxOpts
            )
        );

        $this->assertSame($expectedLevel, $result->level);

        if ($expectedKey === null) {
            $this->assertNull($result->chosenRule);

            return;
        }

        $this->assertNotNull($result->chosenRule);
        $this->assertSame($created[$expectedKey]->id, $result->chosenRule->id);
    }

    public static function scopeMismatchProvider(): array
    {
        return [
            'partner scope mismatch falls to seller' => [
                'rules' => [
                    'partner_mismatch' => ['level' => 'partner_agreement', 'scope_id' => 4002],
                    'seller' => ['level' => 'seller', 'scope_id' => 2001],
                ],
                'ctxOpts' => ['partnerAgreementId' => 4001, 'categoryId' => 3001],
                'expectedLevel' => 'seller',
                'expectedKey' => 'seller',
            ],
            'seller scope mismatch falls to category' => [
                'rules' => [
                    'seller_mismatch' => ['level' => 'seller', 'scope_id' => 2003],
                    'category' => ['level' => 'category', 'scope_id' => 3002],
                ],
                'ctxOpts' => ['categoryId' => 3002],
                'expectedLevel' => 'category',
                'expectedKey' => 'category',
            ],
            'category scope mismatch falls to global' => [
                'rules' => [
                    'category_mismatch' => ['level' => 'category', 'scope_id' => 3004],
                    'global' => ['level' => 'global', 'scope_id' => null],
                ],
                'ctxOpts' => ['categoryId' => 3003],
                'expectedLevel' => 'global',
                'expectedKey' => 'global',
            ],
            'global scope mismatch produces none' => [
                'rules' => [
                    'global_invalid_scope' => ['level' => 'global', 'scope_id' => 9999],
                ],
                'ctxOpts' => ['categoryId' => 3005],
                'expectedLevel' => 'none',
                'expectedKey' => null,
            ],
        ];
    }

    // Status filters
    /**
     * @dataProvider statusFiltersProvider
     */
    public function test_status_and_active_filters_ignore_non_active_rules(
        array $ruleOverrides,
        bool $softDelete
    ): void {
        $filtered = $this->createRule(array_merge([
            'level' => 'seller',
            'scope_id' => 2101,
        ], $ruleOverrides));
        if ($softDelete) {
            $filtered->delete();
        }
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 2101,
                serviceType: 'flight'
            )
        );

        $this->assertSame('global', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($global->id, $result->chosenRule->id);
        $this->assertNotSame($filtered->id, $result->chosenRule->id);
    }

    public static function statusFiltersProvider(): array
    {
        return [
            'active=false' => [
                'ruleOverrides' => ['active' => false],
                'softDelete' => false,
            ],
            'status=inactive' => [
                'ruleOverrides' => ['status' => 'inactive'],
                'softDelete' => false,
            ],
            'status=scheduled' => [
                'ruleOverrides' => ['status' => 'scheduled'],
                'softDelete' => false,
            ],
            'soft-deleted' => [
                'ruleOverrides' => [],
                'softDelete' => true,
            ],
        ];
    }

    // Validity window
    /**
     * @dataProvider validityWindowProvider
     */
    public function test_validity_window_boundaries_are_applied_strictly(
        string $windowCase,
        string $expectedLevel,
        string $expectedKey
    ): void {
        $atTime = now();
        $sellerOverrides = match ($windowCase) {
            'effective_from_future' => [
                'effective_from' => $atTime->copy()->addMinute(),
            ],
            'effective_to_past' => [
                'effective_to' => $atTime->copy()->subMinute(),
            ],
            'effective_to_open_ended' => [
                'effective_from' => $atTime->copy()->subMinute(),
                'effective_to' => null,
            ],
            'effective_to_exact_now' => [
                'effective_to' => $atTime,
            ],
            'effective_from_exact_now' => [
                'effective_from' => $atTime,
                'effective_to' => null,
            ],
            default => [],
        };
        $seller = $this->createRule(array_merge([
            'level' => 'seller',
            'scope_id' => 2201,
            'effective_to' => null,
        ], $sellerOverrides));
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
            'effective_to' => null,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 2201,
                serviceType: 'flight',
                opts: ['atTime' => $atTime]
            )
        );

        $expected = $expectedKey === 'seller' ? $seller : $global;
        $this->assertSame($expectedLevel, $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($expected->id, $result->chosenRule->id);
    }

    public static function validityWindowProvider(): array
    {
        return [
            'effective_from in future' => [
                'windowCase' => 'effective_from_future',
                'expectedLevel' => 'global',
                'expectedKey' => 'global',
            ],
            'effective_to in past' => [
                'windowCase' => 'effective_to_past',
                'expectedLevel' => 'global',
                'expectedKey' => 'global',
            ],
            'effective_to null is open ended' => [
                'windowCase' => 'effective_to_open_ended',
                'expectedLevel' => 'seller',
                'expectedKey' => 'seller',
            ],
            'effective_to exactly now is valid' => [
                'windowCase' => 'effective_to_exact_now',
                'expectedLevel' => 'seller',
                'expectedKey' => 'seller',
            ],
            'effective_from exactly now is valid' => [
                'windowCase' => 'effective_from_exact_now',
                'expectedLevel' => 'seller',
                'expectedKey' => 'seller',
            ],
        ];
    }

    // Service type matching
    public function test_service_type_wildcard_rule_matches_when_specific_absent(): void
    {
        $wildcard = $this->createRule([
            'level' => 'seller',
            'scope_id' => 2301,
            'service_type' => null,
        ]);
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 2301,
                serviceType: 'hotel'
            )
        );

        $this->assertSame('seller', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($wildcard->id, $result->chosenRule->id);
        $this->assertNotSame($global->id, $result->chosenRule->id);
    }

    public function test_service_type_specific_rule_matches_when_same_type_requested(): void
    {
        $specific = $this->createRule([
            'level' => 'seller',
            'scope_id' => 2302,
            'service_type' => 'hotel',
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 2302,
                serviceType: 'hotel'
            )
        );

        $this->assertSame('seller', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($specific->id, $result->chosenRule->id);
    }

    public function test_service_type_specific_rule_miss_falls_through(): void
    {
        $miss = $this->createRule([
            'level' => 'seller',
            'scope_id' => 2303,
            'service_type' => 'hotel',
        ]);
        $global = $this->createRule([
            'level' => 'global',
            'scope_id' => null,
            'service_type' => null,
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 2303,
                serviceType: 'flight'
            )
        );

        $this->assertSame('global', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($global->id, $result->chosenRule->id);
        $this->assertNotSame($miss->id, $result->chosenRule->id);
    }

    public function test_service_type_specific_beats_wildcard_at_same_level(): void
    {
        $wildcard = $this->createRule([
            'level' => 'seller',
            'scope_id' => 2304,
            'service_type' => null,
            'effective_from' => now()->subHour(),
        ]);
        $specific = $this->createRule([
            'level' => 'seller',
            'scope_id' => 2304,
            'service_type' => 'flight',
            'effective_from' => now()->subDay(),
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 2304,
                serviceType: 'flight'
            )
        );

        $this->assertSame('seller', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($specific->id, $result->chosenRule->id);
        $this->assertNotSame($wildcard->id, $result->chosenRule->id);
    }

    public function test_service_type_same_specific_chooses_latest_effective_from(): void
    {
        $older = $this->createRule([
            'level' => 'seller',
            'scope_id' => 2305,
            'service_type' => 'flight',
            'effective_from' => now()->subDays(2),
        ]);
        $newer = $this->createRule([
            'level' => 'seller',
            'scope_id' => 2305,
            'service_type' => 'flight',
            'effective_from' => now()->subMinute(),
        ]);

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 2305,
                serviceType: 'flight'
            )
        );

        $this->assertSame('seller', $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($newer->id, $result->chosenRule->id);
        $this->assertNotSame($older->id, $result->chosenRule->id);
    }

    // Type×level smoke
    /**
     * @dataProvider typeByLevelProvider
     */
    public function test_rule_type_is_ignored_by_resolver_across_levels(
        string $type,
        string $level,
        ?int $scopeId,
        array $ctxOpts
    ): void {
        $rule = $this->createRule(array_filter([
            'type' => $type,
            'level' => $level,
            'scope_id' => $scopeId,
            'percentage_value' => $type !== 'fixed' ? 12.5 : null,
            'fixed_value' => $type !== 'percentage' ? 7.0 : null,
            'hybrid_config' => $type === 'hybrid' ? ['mode' => 'sum'] : null,
            'service_type' => 'flight',
        ], static fn ($value): bool => $value !== '__UNSET__'));

        $result = (new CommissionRuleResolver)->resolve(
            CommissionResolutionContext::make(
                sellerId: 2401,
                serviceType: 'flight',
                opts: $ctxOpts
            )
        );

        $this->assertSame($level, $result->level);
        $this->assertNotNull($result->chosenRule);
        $this->assertSame($rule->id, $result->chosenRule->id);
    }

    public static function typeByLevelProvider(): array
    {
        $cases = [];
        $types = ['percentage', 'fixed', 'hybrid'];
        foreach ($types as $type) {
            $cases[$type.'-global'] = [$type, 'global', null, ['categoryId' => 3401, 'partnerAgreementId' => 4401]];
            $cases[$type.'-category'] = [$type, 'category', 3402, ['categoryId' => 3402, 'partnerAgreementId' => 4402]];
            $cases[$type.'-seller'] = [$type, 'seller', 2401, ['categoryId' => 3403, 'partnerAgreementId' => 4403]];
            $cases[$type.'-partner'] = [$type, 'partner_agreement', 4404, ['categoryId' => 3404, 'partnerAgreementId' => 4404]];
        }

        return $cases;
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
