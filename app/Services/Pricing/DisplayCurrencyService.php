<?php

declare(strict_types=1);

namespace App\Services\Pricing;

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
 * All conversion is delegated to {@see FxConverter} (bcmath, cached,
 * provider-precedence). This service must NEVER do its own FX math.
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
     * Compute the three display-* contract fields for one priced amount.
     *
     * @param  string|float|int|null  $price          The B2C sell price (native currency).
     * @param  string|null  $itemCurrency             The native currency of $price.
     * @param  string|null  $displayCurrency          The (already-sanitized) requested display currency, or null.
     * @return array{display_price: float, display_currency: string|null, fx_rate: string}
     */
    public function fieldsFor(
        string|float|int|null $price,
        ?string $itemCurrency,
        ?string $displayCurrency,
    ): array {
        $native = round((float) ($price ?? 0), 2);
        $itemCurrency = $itemCurrency !== null && $itemCurrency !== ''
            ? strtoupper($itemCurrency)
            : null;

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
            ];
        }

        $rate = $this->fx->rate($itemCurrency, $displayCurrency);
        if ($rate === null) {
            // No rate from any provider → honest fallback to native price.
            return [
                'display_price' => $native,
                'display_currency' => $itemCurrency,
                'fx_rate' => '1',
            ];
        }

        $converted = $this->fx->convert((string) $native, $itemCurrency, $displayCurrency);
        if ($converted === null) {
            return [
                'display_price' => $native,
                'display_currency' => $itemCurrency,
                'fx_rate' => '1',
            ];
        }

        return [
            'display_price' => round((float) $converted, 2),
            'display_currency' => $displayCurrency,
            'fx_rate' => $rate,
        ];
    }

    /**
     * Convenience: merge the three display-* fields into an existing array
     * (additive only — never removes or renames an existing key).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function attach(
        array $row,
        string|float|int|null $price,
        ?string $itemCurrency,
        ?string $displayCurrency,
    ): array {
        return $row + $this->fieldsFor($price, $itemCurrency, $displayCurrency);
    }
}
