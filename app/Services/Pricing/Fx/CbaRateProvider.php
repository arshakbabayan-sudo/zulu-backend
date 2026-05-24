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
    // Phase Զ.15 — try the SOAP endpoint first, then the public XML
    // dump as a fallback. The ASMX wrapper has been intermittently
    // returning ASP.NET runtime error HTML in 2026; the .xml endpoint
    // is the same data but more stable.
    private const URLS = [
        'https://api.cba.am/exchangerates.asmx/ExchangeRatesLatest',
        'https://www.cba.am/_layouts/15/cba/main/exchangerates.xml',
    ];

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

        $body = null;
        foreach (self::URLS as $url) {
            try {
                $response = Http::timeout(6)->get($url);
            } catch (\Throwable) {
                continue;
            }
            if (! $response->successful()) {
                continue;
            }
            $candidate = $response->body();
            // Detect the ASP.NET runtime error page (returned with 200 OK
            // sometimes) and skip — these don't contain valid <Rates>.
            if (str_contains($candidate, 'Runtime Error') || str_contains($candidate, 'YSOD')) {
                continue;
            }
            // Quick sanity: must contain an <ExchangeRate> or <Rate> tag.
            if (! str_contains($candidate, '<ExchangeRate') && ! str_contains($candidate, '<Rate')) {
                continue;
            }
            $body = $candidate;
            break;
        }

        if ($body === null) {
            return null;
        }

        try {
            $xml = new SimpleXMLElement($body);
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
