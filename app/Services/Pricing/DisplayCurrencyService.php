<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Services\Payments\ChargeAmountResolver;
use App\Services\Pricing\Fx\FxConverter;

/**
 * Part A — DISPLAY-currency conversion for public B2C reads.
 *
 * Given a B2C sell price + its native currency + an optional target display
 * currency, produces the three ADDITIVE contract fields:
 *
 *   - display_price    (float, 2dp)  — the price converted into display_currency
 *   - display_currency (string|null) — 3-letter upper code that display_price is in
 *   - fx_rate          (string)      — the rate used ("1" when no conversion)
 *
 * SHARED CONTRACT (graceful, never blank, never a wrong number):
 *   if display_currency is absent OR equals the item currency OR no rate exists
 *     -> display_price = price, display_currency = item currency, fx_rate = "1"
 *   else
 *     -> display_price = round(convert(price, itemCurrency, displayCurrency), 2)
 *        display_currency = displayCurrency
 *        fx_rate = rate(itemCurrency, displayCurrency)
 *
 * This is purely a DISPLAY concern — it NEVER changes the actual charge
 * currency (that is Part B). It also never sums across currencies; every
 * call converts a single priced amount in isolation.
 *
 * All MID-MARKET conversion is delegated to {@see FxConverter} (bcmath, cached,
 * provider-precedence). This service must NEVER do its own FX math.
 *
 * B4 — CHARGE-CURRENCY ALIGNMENT (additive, safe-by-default).
 * When the requested display currency IS the customer charge currency
 * ({@see ChargeAmountResolver::CHARGE_CURRENCY} = 'AMD') AND an explicit
 * seller FX setting applies to the offer's operator/agent, the displayed price
 * must equal what the customer will actually be CHARGED — i.e. the seller-rate
 * AMD ({@see SellerFxRateService::convert}), not the mid-market rate. In that
 * one case fieldsFor() fills display_price / fx_rate / display_currency from the
 * seller rate and tags the result `fx_basis => 'seller'`. In EVERY other case
 * (display currency != AMD, OR no seller setting at any scope, OR no base rate)
 * the existing Part A mid-market result is returned UNCHANGED (tagged
 * `fx_basis => 'market'`). With zero seller_fx_rates rows the platform sees
 * ZERO behaviour change — the seller branch is non-breaking by construction.
 *
 * The new `fx_basis` key is the only addition to the contract; the three
 * existing display_* keys keep identical names/types.
 */
class DisplayCurrencyService
{
    /**
     * Currencies the customer is allowed to request as a display currency.
     *
     * @var list<string>
     */
    public const ALLOWED = ['USD', 'EUR', 'AMD'];

    public function __construct(
        private readonly FxConverter $fx,
        private readonly SellerFxRateService $sellerFx,
    ) {}

    /**
     * Normalize + validate a raw requested display currency against the
     * allow-list. Unknown / blank / null values are treated as "absent"
     * (returns null) so callers degrade gracefully to the native currency.
     *
     * This is DISTINCT from the existing `currency` FILTER param — it only
     * controls how prices are PRESENTED, never which rows are returned.
     */
    public function sanitize(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $upper = strtoupper(trim($value));

        return in_array($upper, self::ALLOWED, true) ? $upper : null;
    }

    /**
     * Compute the display-* contract fields for one priced amount.
     *
     * @param  string|float|int|null  $price          The B2C sell price (native currency).
     * @param  string|null  $itemCurrency             The native currency of $price.
     * @param  string|null  $displayCurrency          The (already-sanitized) requested display currency, or null.
     * @param  int|null  $operatorCompanyId           The offer/package operator company id (for the seller-rate branch). Null on public reads with no operator context.
     * @param  int|null  $agentCompanyId              The agent company id, if any. Always null on public reads (no authenticated agent on the wire).
     * @return array{display_price: float, display_currency: string|null, fx_rate: string, fx_basis: string}
     */
    public function fieldsFor(
        string|float|int|null $price,
        ?string $itemCurrency,
        ?string $displayCurrency,
        ?int $operatorCompanyId = null,
        ?int $agentCompanyId = null,
    ): array {
        $native = round((float) ($price ?? 0), 2);
        $itemCurrency = $itemCurrency !== null && $itemCurrency !== ''
            ? strtoupper($itemCurrency)
            : null;

        // B4 — CHARGE-CURRENCY ALIGNMENT (additive, safe-by-default).
        // Only when there is actually something to convert (a display currency
        // is requested, a native currency exists, and they differ) AND the
        // requested display currency is the customer CHARGE currency (AMD), do
        // we attempt to mirror the exact future charge via the seller rate.
        // The seller rate is used ONLY if an explicit setting applies to this
        // offer's operator/agent (convert() returns non-null with a real,
        // non-1 rate in AMD). In any other outcome we FALL THROUGH untouched to
        // the existing Part A mid-market logic below — no behaviour change
        // without a configured setting.
        if (
            $displayCurrency !== null
            && $itemCurrency !== null
            && $displayCurrency !== $itemCurrency
            && $displayCurrency === ChargeAmountResolver::CHARGE_CURRENCY
        ) {
            $res = $this->sellerFx->convert(
                (string) $native,
                $itemCurrency,
                ChargeAmountResolver::CHARGE_CURRENCY,
                $operatorCompanyId,
                $agentCompanyId,
            );

            if (
                $res !== null
                && $res['rate'] !== '1'
                && $res['target_currency'] === ChargeAmountResolver::CHARGE_CURRENCY
            ) {
                return [
                    'display_price' => round((float) $res['amount'], 2),
                    'display_currency' => ChargeAmountResolver::CHARGE_CURRENCY,
                    'fx_rate' => $res['rate'],
                    'fx_basis' => 'seller',
                ];
            }

            // res === null (no seller setting / no base rate) or a degenerate
            // rate-1 result → fall through to the mid-market path unchanged.
        }

        // Graceful fallback: no display currency requested, no native currency
        // to convert from, or display already equals native → echo the
        // original price with rate "1".
        if (
            $displayCurrency === null
            || $itemCurrency === null
            || $displayCurrency === $itemCurrency
        ) {
            return [
                'display_price' => $native,
                'display_currency' => $itemCurrency,
                'fx_rate' => '1',
                'fx_basis' => 'market',
            ];
        }

        $rate = $this->fx->rate($itemCurrency, $displayCurrency);
        if ($rate === null) {
            // No rate from any provider → honest fallback to native price.
            return [
                'display_price' => $native,
                'display_currency' => $itemCurrency,
                'fx_rate' => '1',
                'fx_basis' => 'market',
            ];
        }

        $converted = $this->fx->convert((string) $native, $itemCurrency, $displayCurrency);
        if ($converted === null) {
            return [
                'display_price' => $native,
                'display_currency' => $itemCurrency,
                'fx_rate' => '1',
                'fx_basis' => 'market',
            ];
        }

        return [
            'display_price' => round((float) $converted, 2),
            'display_currency' => $displayCurrency,
            'fx_rate' => $rate,
            'fx_basis' => 'market',
        ];
    }

    /**
     * Convenience: merge the display-* fields into an existing array (additive
     * only — never removes or renames an existing key).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function attach(
        array $row,
        string|float|int|null $price,
        ?string $itemCurrency,
        ?string $displayCurrency,
        ?int $operatorCompanyId = null,
        ?int $agentCompanyId = null,
    ): array {
        return $row + $this->fieldsFor($price, $itemCurrency, $displayCurrency, $operatorCompanyId, $agentCompanyId);
    }
}
