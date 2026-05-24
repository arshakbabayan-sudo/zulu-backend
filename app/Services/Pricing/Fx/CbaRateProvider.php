<?php

declare(strict_types=1);

namespace App\Services\Pricing\Fx;

use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * Phase 1 / Step C.4 — CBA (Central Bank of Armenia) FX provider.
 *
 * Source: https://api.cba.am/exchangerates.asmx — public, no auth.
 * Returns AMD-relative rates. Cross-rate pairs (e.g. USD->EUR) are
 * synthesized via AMD bridge.
 *
 * CBA quotes "X currency units → Y AMD", so for USD->AMD we get the
 * rate directly. For AMD->USD we invert. For USD->EUR we go
 * USD->AMD then AMD->EUR (inverted from EUR->AMD).
 */
class CbaRateProvider
{
    private const URL = 'https://api.cba.am/exchangerates.asmx/ExchangeRatesLatest';

    /**
     * @return string|null  the rate with up to 6 decimals, or null if unavailable
     */
    public function fetch(string $sourceCurrency, string $targetCurrency): ?string
    {
        $src = strtoupper($sourceCurrency);
        $tgt = strtoupper($targetCurrency);

        if ($src === $tgt) {
            return '1.000000';
        }

        try {
            $response = Http::timeout(8)->get(self::URL);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        try {
            $xml = new SimpleXMLElement($response->body());
        } catch (\Throwable) {
            return null;
        }

        // The XML shape is <ExchangeRates><ExchangeRate><ISO>USD</ISO>
        // <Amount>1</Amount><Rate>387.25</Rate>...</ExchangeRate>...
        $rates = [];
        foreach ($xml->Rates->ExchangeRate ?? [] as $row) {
            $iso = strtoupper((string) $row->ISO);
            $amount = (float) $row->Amount;
            $rate = (float) $row->Rate;
            if ($amount > 0) {
                // CBA quotes X-units → Y AMD; normalise to 1-unit → AMD.
                $rates[$iso] = $rate / $amount;
            }
        }
        // AMD is always 1 unit per 1 AMD.
        $rates['AMD'] = 1.0;

        return $this->computeRate($rates, $src, $tgt);
    }

    /**
     * @param  array<string, float>  $amdRates  ISO => "1 ISO unit in AMD"
     */
    private function computeRate(array $amdRates, string $src, string $tgt): ?string
    {
        $srcInAmd = $amdRates[$src] ?? null;
        $tgtInAmd = $amdRates[$tgt] ?? null;

        if ($srcInAmd === null || $tgtInAmd === null) {
            return null;
        }
        if ($tgtInAmd <= 0) {
            return null;
        }

        return number_format($srcInAmd / $tgtInAmd, 6, '.', '');
    }
}
