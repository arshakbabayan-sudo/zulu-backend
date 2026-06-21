<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\ExchangeRate;
use App\Models\SellerFxRate;
use App\Services\Pricing\Fx\FxConverter;

/**
 * Seller FX-rate model — resolution + conversion service.
 *
 * Arshak's decided currency model: the customer pays at the OPERATOR's chosen
 * bank rate plus an ABSOLUTE per-unit margin, so ZULU takes no FX risk.
 *
 * Given a (source currency, target currency) and the operator/agent involved,
 * this service:
 *   1. resolves the applicable SellerFxRate setting (agent → operator →
 *      platform precedence; first active wins),
 *   2. computes the effective per-unit rate = baseRate + ABSOLUTE margin,
 *      where baseRate is the manual_rates entry (source='manual') or the
 *      Central-Bank rate from exchange_rates (source='cba'),
 *   3. converts an amount and returns a snapshot-ready shape (the resolved
 *      rate is later persisted at booking so the paid amount is locked).
 *
 * FxConverter is injected per the design and used ONLY as the FX read layer;
 * this service never re-implements FX math. (CBA base rate is read directly
 * from the exchange_rates 'cba' row because we specifically want CBA, not
 * FxConverter's source-precedence pick.)
 */
class SellerFxRateService
{
    /**
     * bcmath scale for rate arithmetic.
     */
    private const SCALE = 4;

    public function __construct(
        private readonly FxConverter $fx,
    ) {
    }

    /**
     * Resolve the applicable seller FX setting.
     *
     * Precedence: agent (scope='agent', company_id=$agentCompanyId) →
     * operator (scope='operator', company_id=$operatorCompanyId) →
     * platform (scope='platform', company_id NULL). First active match wins.
     * Returns null when no active setting exists at any level.
     */
    public function resolveSetting(
        ?int $operatorCompanyId,
        ?int $agentCompanyId,
        string $targetCurrency = 'AMD',
    ): ?SellerFxRate {
        $targetCurrency = strtoupper($targetCurrency);

        if ($agentCompanyId !== null) {
            $agent = SellerFxRate::query()
                ->active()
                ->forTarget($targetCurrency)
                ->where('scope', SellerFxRate::SCOPE_AGENT)
                ->where('company_id', $agentCompanyId)
                ->first();

            if ($agent !== null) {
                return $agent;
            }
        }

        if ($operatorCompanyId !== null) {
            $operator = SellerFxRate::query()
                ->active()
                ->forTarget($targetCurrency)
                ->where('scope', SellerFxRate::SCOPE_OPERATOR)
                ->where('company_id', $operatorCompanyId)
                ->first();

            if ($operator !== null) {
                return $operator;
            }
        }

        return SellerFxRate::query()
            ->active()
            ->forTarget($targetCurrency)
            ->where('scope', SellerFxRate::SCOPE_PLATFORM)
            ->whereNull('company_id')
            ->first();
    }

    /**
     * Compute the effective per-unit rate as a bcmath string:
     *   baseRate + ABSOLUTE margin.
     *
     * - Same currency → '1' and NO margin (we never mark up same-currency).
     * - source='manual' → baseRate = manual_rates[$sourceCurrency].
     * - source='cba' (or anything else) → baseRate = the active CBA row in
     *   exchange_rates for ($sourceCurrency, $targetCurrency).
     * - No base rate available (no CBA row / missing manual entry) → null,
     *   so the caller can fall back.
     */
    public function effectiveRate(
        string $sourceCurrency,
        string $targetCurrency,
        ?SellerFxRate $setting,
    ): ?string {
        $sourceCurrency = strtoupper($sourceCurrency);
        $targetCurrency = strtoupper($targetCurrency);

        if ($sourceCurrency === $targetCurrency) {
            return '1';
        }

        $baseRate = $this->resolveBaseRate($sourceCurrency, $targetCurrency, $setting);
        if ($baseRate === null) {
            return null;
        }

        $margin = $setting !== null ? (string) $setting->margin : '0';

        return bcadd($baseRate, $margin, self::SCALE);
    }

    /**
     * Resolve + convert an amount into the target currency.
     *
     * Returns the snapshot-ready shape:
     *   [
     *     'amount'          => float (amount * rate, rounded 2dp),
     *     'rate'            => string (the effective per-unit rate),
     *     'target_currency' => string,
     *     'source'          => string|null (setting source, or null),
     *     'setting_id'      => int|null,
     *   ]
     *
     * Same-currency → amount unchanged, rate '1'. Returns null when no rate
     * is available (no base rate), so the caller can fall back.
     */
    public function convert(
        string $amount,
        string $sourceCurrency,
        string $targetCurrency,
        ?int $operatorCompanyId,
        ?int $agentCompanyId,
    ): ?array {
        $sourceCurrency = strtoupper($sourceCurrency);
        $targetCurrency = strtoupper($targetCurrency);

        $setting = $this->resolveSetting($operatorCompanyId, $agentCompanyId, $targetCurrency);

        $rate = $this->effectiveRate($sourceCurrency, $targetCurrency, $setting);
        if ($rate === null) {
            return null;
        }

        $converted = bcmul($amount, $rate, self::SCALE);

        return [
            'amount' => round((float) $converted, 2),
            'rate' => $rate,
            'target_currency' => $targetCurrency,
            'source' => $setting?->source,
            'setting_id' => $setting?->id,
        ];
    }

    /**
     * Read the base per-unit rate (before margin) as a bcmath string, or null.
     */
    private function resolveBaseRate(
        string $sourceCurrency,
        string $targetCurrency,
        ?SellerFxRate $setting,
    ): ?string {
        if ($setting !== null && $setting->source === SellerFxRate::SOURCE_MANUAL) {
            $rates = $setting->manual_rates ?? [];
            $rate = $rates[$sourceCurrency] ?? null;

            return $rate !== null ? (string) $rate : null;
        }

        // 'cba' (default): read the active Central-Bank row specifically.
        $row = ExchangeRate::query()
            ->forPair($sourceCurrency, $targetCurrency)
            ->active()
            ->where('source', ExchangeRate::SOURCE_CBA)
            ->first();

        return $row !== null ? (string) $row->rate : null;
    }
}
