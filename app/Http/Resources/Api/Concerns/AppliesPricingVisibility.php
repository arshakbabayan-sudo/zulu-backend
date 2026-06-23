<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Concerns;

use App\Models\User;
use App\Services\Pricing\DisplayCurrencyService;
use App\Services\Pricing\PriceCalculatorService;
use Illuminate\Http\Request;

/**
 * Phase 1 / Step B.3 — controls visibility of supplier-net pricing
 * fields on public-facing API resources.
 *
 * Background (audit doc §5): 11 resources (Catalog, Hotel, Transfer,
 * Car, Excursion, Visa, Package, Flight detail/list variants) were
 * shipping the operator's NET price (the cost to Zulu) to every API
 * consumer — including unauthenticated browsers and competing operators.
 * The leak source is PriceCalculatorService::normalizedPrice() returning
 * BOTH base_price (cost) and calculated_price (sell). Many resources also
 * exposed `base_price` directly as a top-level field.
 *
 * Visibility rules per spec:
 *   - sell_price (B2C)               → always visible (even unauth)
 *   - base_price (supplier net)      → super_admin OR offer's operator
 *                                      OR (TODO) operators with a
 *                                      partnership agreement
 *   - agent_net (Zulu→agent price)   → super_admin OR agent owner with
 *                                      agreement OR offer's operator
 *
 * Partnership-based visibility is stubbed for Phase 1 — add a real
 * partnership lookup in Phase 2 when the partnership table is wired.
 */
trait AppliesPricingVisibility
{
    /**
     * True when the current request's user is allowed to see the
     * supplier net price for an offer owned by `$ownerCompanyId`.
     *
     * Rules:
     *   - Super admin: always
     *   - Offer's owning operator (user belongs to the same company): yes
     *   - Anyone else (including unauthenticated): no
     */
    protected function canSeeNetPrice(Request $request, ?int $ownerCompanyId): bool
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        if ($ownerCompanyId === null) {
            return false;
        }

        // User belongs to the offer's owning operator company.
        return $user->companies()->whereKey($ownerCompanyId)->exists();
    }

    /**
     * Returns the canonical pricing payload with `base_price` ONLY when
     * the caller is allowed to see it. Always includes `sell_price` +
     * `currency`.
     *
     * Output keys (stable contract for frontends):
     *   - sell_price (float, always)
     *   - currency   (string|null, always)
     *   - base_price (float|null, only when allowed)
     *
     * `calculated_price` is preserved as an alias of `sell_price` so
     * existing frontends that read `pricing.calculated_price` keep
     * working. Will be dropped in Phase 2.
     *
     * @return array<string, mixed>
     */
    protected function safePricing(
        Request $request,
        string|float|null $basePrice,
        ?string $currency,
        ?int $ownerCompanyId
    ): array {
        $calculator = app(PriceCalculatorService::class);
        // Per-operator markup so the DISPLAYED sell price matches what the
        // booking CHARGE computes for the SAME operator (PricingResolver's
        // no-rule fallback calls the same b2cPriceForOperator). Public reads
        // are B2C ('client'); an operator with no markup column set falls back
        // to the global default (today's 15%), so this is backward-compatible.
        $sellPrice = $calculator->b2cPriceForOperator($basePrice ?? 0, $ownerCompanyId, 'client');
        $currencyUpper = $currency !== null ? strtoupper($currency) : null;

        // Part A — additive DISPLAY-currency conversion of the B2C sell price
        // only. base_price (operator-net) is NEVER converted here.
        // B4 — $ownerCompanyId is the offer/package operator; threading it lets
        // an AMD display mirror the future seller-rate charge when a setting
        // exists (agent unknown on public reads → null → operator/platform).
        $displayService = app(DisplayCurrencyService::class);
        $displayCurrency = $displayService->sanitize($request->query('display_currency'));
        $displayFields = $displayService->fieldsFor($sellPrice, $currencyUpper, $displayCurrency, $ownerCompanyId);

        $payload = [
            'sell_price' => $sellPrice,
            'calculated_price' => $sellPrice, // back-compat alias
            'currency' => $currencyUpper,
            'display_price' => $displayFields['display_price'],
            'display_currency' => $displayFields['display_currency'],
            'fx_rate' => $displayFields['fx_rate'],
            'fx_basis' => $displayFields['fx_basis'],
        ];

        if ($this->canSeeNetPrice($request, $ownerCompanyId)) {
            $payload['base_price'] = $basePrice !== null ? round((float) $basePrice, 2) : null;
        }

        return $payload;
    }

    /**
     * Return the raw `base_price` field value when allowed, else null.
     * Used by resources that surface base_price as a top-level field
     * alongside the `pricing` object.
     */
    protected function safeBasePrice(
        Request $request,
        string|float|null $basePrice,
        ?int $ownerCompanyId
    ): ?float {
        if ($basePrice === null) {
            return null;
        }

        if (! $this->canSeeNetPrice($request, $ownerCompanyId)) {
            return null;
        }

        return round((float) $basePrice, 2);
    }
}
