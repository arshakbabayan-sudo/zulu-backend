<?php

namespace App\Services\Insurance;

use App\Models\InsuranceProduct;
use InvalidArgumentException;

/**
 * PART 17 — Insurance pricing engine.
 *
 * Premium = base_premium × age_multiplier_per_traveler × duration_factor
 *
 * Age tiers stored on product as [{min_age, max_age, multiplier}, ...].
 * Duration factor: premium scales linearly with duration_days / smallest_duration_option.
 */
class InsurancePricingService
{
    /**
     * @param  array<int, array{age: int}>  $travelers
     * @return array{premium: float, breakdown: array<int, array{age: int, multiplier: float, line_total: float}>, currency: string}
     */
    public function quote(InsuranceProduct $product, array $travelers, int $coverageDays): array
    {
        if ($travelers === []) {
            throw new InvalidArgumentException('At least one traveler required.');
        }
        if ($coverageDays < 1) {
            throw new InvalidArgumentException('coverage_days must be ≥ 1.');
        }

        $durationFactor = $this->durationFactor($product, $coverageDays);
        $base = (float) $product->base_premium;

        $total = 0.0;
        $breakdown = [];
        foreach ($travelers as $traveler) {
            $age = (int) ($traveler['age'] ?? 0);
            if ($age < 0) {
                throw new InvalidArgumentException('Invalid age.');
            }

            $multiplier = $this->ageMultiplier($product, $age);
            $line = round($base * $multiplier * $durationFactor, 2);
            $total += $line;
            $breakdown[] = [
                'age' => $age,
                'multiplier' => $multiplier,
                'line_total' => $line,
            ];
        }

        return [
            'premium' => round($total, 2),
            'breakdown' => $breakdown,
            'currency' => $product->currency,
            'coverage_days' => $coverageDays,
            'duration_factor' => $durationFactor,
        ];
    }

    private function ageMultiplier(InsuranceProduct $product, int $age): float
    {
        $tiers = is_array($product->age_tiers) ? $product->age_tiers : [];

        foreach ($tiers as $tier) {
            $min = (int) ($tier['min_age'] ?? 0);
            $max = (int) ($tier['max_age'] ?? 999);
            if ($age >= $min && $age <= $max) {
                return (float) ($tier['multiplier'] ?? 1.0);
            }
        }

        return 1.0;
    }

    private function durationFactor(InsuranceProduct $product, int $coverageDays): float
    {
        $options = is_array($product->duration_options) ? $product->duration_options : [];
        $options = array_map('intval', $options);
        $options = array_values(array_filter($options, fn ($d) => $d > 0));

        if ($options === []) {
            return max(1.0, $coverageDays / 7.0); // fallback: per-week scaling
        }

        sort($options);
        $smallest = (int) $options[0];

        return max(1.0, $coverageDays / $smallest);
    }
}
