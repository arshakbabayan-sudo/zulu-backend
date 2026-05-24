<?php

declare(strict_types=1);

namespace App\Services\Pricing\Fx;

use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * Phase 1 / Step C.4 — ECB (European Central Bank) FX provider.
 *
 * Source: https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml
 *   — public, no auth, EUR-relative rates updated weekday afternoons.
 *
 * EUR is the base (1 EUR = X foreign). For non-EUR pairs we synthesize
 * via EUR bridge (USD->RUB = USD->EUR / RUB->EUR).
 */
class EcbRateProvider
{
    private const URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

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

        // Namespace handling: ECB XML uses default ns. Register a prefix.
        $namespaces = $xml->getNamespaces(true);
        $eurNs = $namespaces[''] ?? 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';
        $xml->registerXPathNamespace('e', $eurNs);

        $cubes = $xml->xpath('//e:Cube/e:Cube/e:Cube');
        if (empty($cubes)) {
            return null;
        }

        // EUR-relative rates: 1 EUR = N foreign units.
        $rates = ['EUR' => 1.0];
        foreach ($cubes as $cube) {
            $iso = strtoupper((string) $cube['currency']);
            $rate = (float) $cube['rate'];
            if ($iso !== '' && $rate > 0) {
                $rates[$iso] = $rate;
            }
        }

        return $this->computeRate($rates, $src, $tgt);
    }

    /**
     * @param  array<string, float>  $eurRates  ISO => "1 EUR in ISO units"
     */
    private function computeRate(array $eurRates, string $src, string $tgt): ?string
    {
        $srcRate = $eurRates[$src] ?? null;
        $tgtRate = $eurRates[$tgt] ?? null;

        if ($srcRate === null || $tgtRate === null) {
            return null;
        }
        if ($srcRate <= 0) {
            return null;
        }

        // 1 SRC unit in TGT = TGT-per-EUR / SRC-per-EUR
        return number_format($tgtRate / $srcRate, 6, '.', '');
    }
}
