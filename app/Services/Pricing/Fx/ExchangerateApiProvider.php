<?php

declare(strict_types=1);

namespace App\Services\Pricing\Fx;

use Illuminate\Support\Facades\Http;

/**
 * Phase 1 / Step C.4 — exchangerate-api.com last-resort FX provider.
 *
 * Free tier (no key) allows ~1500 requests/month. URL:
 *   https://open.er-api.com/v6/latest/{BASE}
 *
 * Returns a JSON dict of base->target rates. We make one request per
 * source currency and read the target rate from the dict.
 */
class ExchangerateApiProvider
{
    private const URL_TEMPLATE = 'https://open.er-api.com/v6/latest/%s';

    public function fetch(string $sourceCurrency, string $targetCurrency): ?string
    {
        $src = strtoupper($sourceCurrency);
        $tgt = strtoupper($targetCurrency);

        if ($src === $tgt) {
            return '1.000000';
        }

        try {
            $response = Http::timeout(8)->get(sprintf(self::URL_TEMPLATE, $src));
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();
        if (! is_array($body) || ($body['result'] ?? null) !== 'success') {
            return null;
        }

        $rate = $body['rates'][$tgt] ?? null;
        if (! is_numeric($rate) || (float) $rate <= 0) {
            return null;
        }

        return number_format((float) $rate, 6, '.', '');
    }
}
