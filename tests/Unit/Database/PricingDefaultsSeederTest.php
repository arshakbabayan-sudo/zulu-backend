<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Models\MoneyFlowTerm;
use App\Models\PricingRule;
use Database\Seeders\PricingDefaultsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 / Step C.5 — verifies the day-1 defaults seeder produces
 * the expected rows and is safe to re-run (idempotent).
 */
class PricingDefaultsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_one_global_rule_per_currency(): void
    {
        $this->seed(PricingDefaultsSeeder::class);

        $rules = PricingRule::query()
            ->where('scope_type', PricingRule::SCOPE_GLOBAL)
            ->where('markup_type', PricingRule::TYPE_PERCENTAGE)
            ->get();

        $this->assertCount(5, $rules);
        $this->assertSame(
            ['AMD', 'EUR', 'GBP', 'RUB', 'USD'],
            $rules->pluck('currency')->sort()->values()->all()
        );

        foreach ($rules as $rule) {
            $this->assertSame('15.0000', (string) $rule->markup_value);
            $this->assertSame(10, (int) $rule->priority);
            $this->assertTrue((bool) $rule->is_active);
        }
    }

    public function test_seeder_creates_global_money_flow_term(): void
    {
        $this->seed(PricingDefaultsSeeder::class);

        $term = MoneyFlowTerm::query()
            ->where('scope_type', MoneyFlowTerm::SCOPE_GLOBAL)
            ->first();

        $this->assertNotNull($term);
        $this->assertSame(MoneyFlowTerm::MODEL_ZULU_COLLECTS, $term->collection_model);
        $this->assertSame(15, (int) $term->remittance_days);
        $this->assertTrue((bool) $term->is_active);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(PricingDefaultsSeeder::class);
        $this->seed(PricingDefaultsSeeder::class);
        $this->seed(PricingDefaultsSeeder::class);

        $this->assertSame(5, PricingRule::query()->where('scope_type', 'global')->count());
        $this->assertSame(1, MoneyFlowTerm::query()->where('scope_type', 'global')->count());
    }
}
