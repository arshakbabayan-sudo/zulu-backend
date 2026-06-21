<?php

namespace Tests\Unit\Services\Pricing;

use App\Services\Pricing\DisplayCurrencyService;
use App\Services\Pricing\Fx\FxConverter;
use Tests\TestCase;

class DisplayCurrencyServiceTest extends TestCase
{
    /**
     * A deterministic Fx stub: USD→EUR = 0.9, everything else unknown.
     * Avoids any DB / exchange_rates dependency so the contract logic is
     * tested in isolation.
     */
    private function service(): DisplayCurrencyService
    {
        $fx = new class extends FxConverter
        {
            public function rate(string $source, string $target): ?string
            {
                $source = strtoupper($source);
                $target = strtoupper($target);
                if ($source === $target) {
                    return '1.000000';
                }
                if ($source === 'USD' && $target === 'EUR') {
                    return '0.9';
                }

                return null; // no rate available for other pairs
            }

            public function convert(string $amount, string $source, string $target): ?string
            {
                $rate = $this->rate($source, $target);

                return $rate === null ? null : bcmul($amount, $rate, 4);
            }
        };

        return new DisplayCurrencyService($fx);
    }

    public function test_sanitize_upper_cases_allowed_and_rejects_unknown(): void
    {
        $s = $this->service();

        $this->assertSame('USD', $s->sanitize('usd'));
        $this->assertSame('EUR', $s->sanitize('EUR'));
        $this->assertSame('AMD', $s->sanitize(' amd '));
        $this->assertNull($s->sanitize('GBP'));
        $this->assertNull($s->sanitize(''));
        $this->assertNull($s->sanitize(null));
        $this->assertNull($s->sanitize(123));
    }

    public function test_absent_display_currency_echoes_native_with_rate_one(): void
    {
        $fields = $this->service()->fieldsFor(100, 'USD', null);

        $this->assertSame(100.0, $fields['display_price']);
        $this->assertSame('USD', $fields['display_currency']);
        $this->assertSame('1', $fields['fx_rate']);
    }

    public function test_same_currency_echoes_native_with_rate_one(): void
    {
        $fields = $this->service()->fieldsFor(100, 'USD', 'USD');

        $this->assertSame(100.0, $fields['display_price']);
        $this->assertSame('USD', $fields['display_currency']);
        $this->assertSame('1', $fields['fx_rate']);
    }

    public function test_missing_rate_falls_back_to_native_never_a_wrong_number(): void
    {
        // USD→AMD has no rate in the stub → must NOT invent a number.
        $fields = $this->service()->fieldsFor(100, 'USD', 'AMD');

        $this->assertSame(100.0, $fields['display_price']);
        $this->assertSame('USD', $fields['display_currency']);
        $this->assertSame('1', $fields['fx_rate']);
    }

    public function test_real_conversion_uses_fx_converter(): void
    {
        $fields = $this->service()->fieldsFor(100, 'USD', 'EUR');

        $this->assertSame(90.0, $fields['display_price']);
        $this->assertSame('EUR', $fields['display_currency']);
        $this->assertSame('0.9', $fields['fx_rate']);
    }

    public function test_conversion_rounds_to_two_decimals(): void
    {
        // 33.33 * 0.9 = 29.997 → 30.00
        $fields = $this->service()->fieldsFor(33.33, 'USD', 'EUR');

        $this->assertSame(30.0, $fields['display_price']);
    }

    public function test_attach_is_additive_and_never_overwrites_existing_keys(): void
    {
        $row = ['id' => 7, 'currency' => 'USD', 'base_price' => 100];
        $out = $this->service()->attach($row, 100, 'USD', 'EUR');

        // original keys preserved
        $this->assertSame(7, $out['id']);
        $this->assertSame('USD', $out['currency']);
        $this->assertSame(100, $out['base_price']);
        // additive display fields present
        $this->assertSame(90.0, $out['display_price']);
        $this->assertSame('EUR', $out['display_currency']);
        $this->assertSame('0.9', $out['fx_rate']);
    }

    public function test_null_item_currency_falls_back_gracefully(): void
    {
        $fields = $this->service()->fieldsFor(50, null, 'EUR');

        $this->assertSame(50.0, $fields['display_price']);
        $this->assertNull($fields['display_currency']);
        $this->assertSame('1', $fields['fx_rate']);
    }
}
