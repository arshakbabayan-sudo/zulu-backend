<?php

declare(strict_types=1);

namespace App\Services\Pricing\Fx;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 1 / Step C.4 — read-side FX helper.
 *
 * Reads the best is_active=true rate per (source, target) pair, honouring
 * ExchangeRate::SOURCE_PRECEDENCE (partner_override > manual > cba > ecb >
 * exchangerate_api). Small in-memory cache so a single web request that
 * touches many prices doesn't re-hit the DB per conversion.
 *
 * Snapshots: when an order is created, the resolved rate(s) get persisted
 * into order_pricing_snapshots.snapshot_json.fx so the customer's paid
 * amount is locked regardless of future rate movements.
 */
class FxConverter
{
    /**
     * Return the rate to convert 1 unit of $source into $target, or null
     * when no rate is available from any provider.
     */
    public function rate(string $source, string $target): ?string
    {
        $source = strtoupper($source);
        $target = strtoupper($target);
        if ($source === $target) {
            return '1.000000';
        }

        $cacheKey = "fx_rate:{$source}:{$target}";

        return Cache::driver('array')->rememberForever(
            $cacheKey,
            fn () => $this->lookupBestRate($source, $target),
        );
    }

    /**
     * Convert $amount from $source currency to $target currency, returning
     * the converted amount as a string with 4-decimal precision (bcmath).
     */
    public function convert(string $amount, string $source, string $target): ?string
    {
        $rate = $this->rate($source, $target);
        if ($rate === null) {
            return null;
        }

        return bcmul($amount, $rate, 4);
    }

    /**
     * Phase Զ.16 / Item 18 — convert with a per-partnership FX premium.
     *
     * Looks up a row in fx_partnership_premiums for the (operator, agent,
     * source, target) tuple and applies the premium_percent to the base
     * rate before converting. Falls back to plain ::convert() if no row.
     */
    public function convertWithPartnership(
        int $operatorId,
        ?int $agentId,
        string $amount,
        string $source,
        string $target,
    ): ?string {
        $source = strtoupper($source);
        $target = strtoupper($target);

        $baseRate = $this->rate($source, $target);
        if ($baseRate === null) {
            return null;
        }

        // Prefer agent-specific row; fall back to operator-wide (agent_id IS NULL).
        $premium = \App\Models\FxPartnershipPremium::query()
            ->where('operator_id', $operatorId)
            ->where('source_currency', $source)
            ->where('target_currency', $target)
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN agent_id = ? THEN 0 WHEN agent_id IS NULL THEN 1 ELSE 2 END', [$agentId])
            ->first();

        if ($premium === null) {
            return bcmul($amount, $baseRate, 4);
        }

        // Apply premium: rate * (1 + premium_percent / 100)
        $multiplier = bcadd('1', bcdiv((string) $premium->premium_percent, '100', 6), 6);
        $effectiveRate = bcmul($baseRate, $multiplier, 6);

        return bcmul($amount, $effectiveRate, 4);
    }

    private function lookupBestRate(string $source, string $target): ?string
    {
        $rows = ExchangeRate::query()
            ->forPair($source, $target)
            ->active()
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        // Pick highest-precedence provider.
        foreach (ExchangeRate::SOURCE_PRECEDENCE as $providerKey) {
            $row = $rows->firstWhere('source', $providerKey);
            if ($row !== null) {
                return (string) $row->rate;
            }
        }

        // Fallback: just return any row's rate (shouldn't happen — every
        // is_active row has a known source).
        return (string) $rows->first()->rate;
    }
}
