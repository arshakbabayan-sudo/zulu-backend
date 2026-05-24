<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MoneyFlowTerm;
use App\Models\PricingRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 / Step C.5 — day-1 defaults for the new pricing engine.
 *
 * Idempotent. Safe to re-run; uses firstOrCreate on stable identity.
 *
 * Produces:
 *   - 1 global PricingRule replicating the legacy 15% B2C markup,
 *     priority=10 (low — operator/partnership rules trump it). The
 *     PricingResolver fallback path (`phase_1_fallback_legacy_b2c_markup`)
 *     should never fire in production after this seeder runs.
 *
 *   - 1 global MoneyFlowTerm: Model A (zulu_collects) with T+15 days.
 *     Matches the platform default the MoneyFlowResolver hard-codes
 *     when no terms exist; making it a real row enables overrides at
 *     operator/partnership level without touching code.
 */
class PricingDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedGlobalPricingRule();
        $this->seedGlobalMoneyFlowTerm();
    }

    private function seedGlobalPricingRule(): void
    {
        // Identity: one global, percentage, currency-agnostic row per
        // currency. The 15% legacy markup historically applied to ALL
        // currencies (PriceCalculatorService didn't filter), so we seed
        // one global row per active marketplace currency.
        $currencies = ['USD', 'EUR', 'AMD', 'RUB', 'GBP'];

        foreach ($currencies as $currency) {
            $existing = PricingRule::query()
                ->where('scope_type', PricingRule::SCOPE_GLOBAL)
                ->where('currency', $currency)
                ->whereNull('operator_id')
                ->whereNull('agent_id')
                ->whereNull('service_category')
                ->whereNull('destination_id')
                ->where('markup_type', PricingRule::TYPE_PERCENTAGE)
                ->first();

            if ($existing !== null) {
                continue;
            }

            PricingRule::create([
                'scope_type' => PricingRule::SCOPE_GLOBAL,
                'markup_type' => PricingRule::TYPE_PERCENTAGE,
                'markup_value' => 15,
                'currency' => $currency,
                'effective_from' => now(),
                'priority' => 10, // low — operator/partnership trumps
                'is_active' => true,
            ]);
        }
    }

    private function seedGlobalMoneyFlowTerm(): void
    {
        $existing = MoneyFlowTerm::query()
            ->where('scope_type', MoneyFlowTerm::SCOPE_GLOBAL)
            ->whereNull('operator_id')
            ->whereNull('agent_id')
            ->first();

        if ($existing !== null) {
            return;
        }

        MoneyFlowTerm::create([
            'scope_type' => MoneyFlowTerm::SCOPE_GLOBAL,
            'collection_model' => MoneyFlowTerm::MODEL_ZULU_COLLECTS,
            'remittance_days' => 15,
            'is_active' => true,
            'effective_from' => now(),
        ]);
    }
}
