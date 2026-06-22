<?php

namespace Tests\Unit\Services\Pricing;

use App\Services\Pricing\DisplayCurrencyService;
use App\Services\Pricing\Fx\FxConverter;
use App\Services\Pricing\SellerFxRateService;
use Tests\TestCase;

class DisplayCurrencyServiceTest extends TestCase
{
    /**
     * A deterministic Fx stub: USD→EUR = 0.9, USD→AMD = 380 (mid-market),
     * everything else unknown. Avoids any DB / exchange_rates dependency so the
     * contract logic is tested in isolation.
     */
    private function fxStub(): FxConverter
    {
        return new class extends FxConverter
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
                if ($source === 'USD' && $target === 'AMD') {
                    return '380'; // mid-market USD→AMD (Part A path)
                }

                return null; // no rate available for other pairs
            }

            public function convert(string $amount, string $source, string $target): ?string
            {
                $rate = $this->rate($source, $target);

                return $rate === null ? null : bcmul($amount, $rate, 4);
            }
        };
    }

    /**
     * A SellerFxRateService stub whose convert() returns null for every pair —
     * i.e. NO seller setting configured anywhere. This is the safe-by-default
     * baseline: with no setting the service falls through to mid-market exactly
     * as Part A did, so all the pre-B4 assertions below remain unchanged.
     */
    private function noSellerStub(): SellerFxRateService
    {
        return new class($this->fxStub()) extends SellerFxRateService
        {
            public function convert(
                string $amount,
                string $sourceCurrency,
                string $targetCurrency,
                ?int $operatorCompanyId,
                ?int $agentCompanyId,
            ): ?array {
                return null;
            }
        };
    }

    /**
     * A SellerFxRateService stub that simulates an operator (id 42) having a
     * configured AMD seller setting: USD→AMD at an effective rate of 402 (e.g.
     * CBA 400 + 2 absolute margin). Any other operator / pair → null (no
     * setting), so safe-by-default still holds for everyone else.
     */
    private function sellerStub(): SellerFxRateService
    {
        return new class($this->fxStub()) extends SellerFxRateService
        {
            public function convert(
                string $amount,
                string $sourceCurrency,
                string $targetCurrency,
                ?int $operatorCompanyId,
                ?int $agentCompanyId,
            ): ?array {
                $sourceCurrency = strtoupper($sourceCurrency);
                $targetCurrency = strtoupper($targetCurrency);

                if ($operatorCompanyId === 42 && $sourceCurrency === 'USD' && $targetCurrency === 'AMD') {
                    $rate = '402.0000';

                    return [
                        'amount' => round((float) bcmul($amount, $rate, 4), 2),
                        'rate' => $rate,
                        'target_currency' => 'AMD',
                        'source' => 'cba',
                        'setting_id' => 7,
                    ];
                }

                return null; // no setting for this operator/pair → fall through
            }
        };
    }

    private function service(): DisplayCurrencyService
    {
        // Default service for the Part A contract tests: NO seller setting, so
        // behaviour is identical to pre-B4.
        return new DisplayCurrencyService($this->fxStub(), $this->noSellerStub());
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
        $this->assertSame('market', $fields['fx_basis']);
    }

    public function test_same_currency_echoes_native_with_rate_one(): void
    {
        $fields = $this->service()->fieldsFor(100, 'USD', 'USD');

        $this->assertSame(100.0, $fields['display_price']);
        $this->assertSame('USD', $fields['display_currency']);
        $this->assertSame('1', $fields['fx_rate']);
        $this->assertSame('market', $fields['fx_basis']);
    }

    public function test_missing_rate_falls_back_to_native_never_a_wrong_number(): void
    {
        // EUR→AMD has no rate in the stub → must NOT invent a number.
        $fields = $this->service()->fieldsFor(100, 'EUR', 'AMD');

        $this->assertSame(100.0, $fields['display_price']);
        $this->assertSame('EUR', $fields['display_currency']);
        $this->assertSame('1', $fields['fx_rate']);
        $this->assertSame('market', $fields['fx_basis']);
    }

    public function test_real_conversion_uses_fx_converter(): void
    {
        $fields = $this->service()->fieldsFor(100, 'USD', 'EUR');

        $this->assertSame(90.0, $fields['display_price']);
        $this->assertSame('EUR', $fields['display_currency']);
        $this->assertSame('0.9', $fields['fx_rate']);
        $this->assertSame('market', $fields['fx_basis']);
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
        $this->assertSame('market', $out['fx_basis']);
    }

    public function test_null_item_currency_falls_back_gracefully(): void
    {
        $fields = $this->service()->fieldsFor(50, null, 'EUR');

        $this->assertSame(50.0, $fields['display_price']);
        $this->assertNull($fields['display_currency']);
        $this->assertSame('1', $fields['fx_rate']);
        $this->assertSame('market', $fields['fx_basis']);
    }

    // ---------------------------------------------------------------------
    // B4 — charge-currency (AMD) alignment with the seller rate.
    // ---------------------------------------------------------------------

    /**
     * (a) Offer whose operator (id 42) HAS an AMD seller setting + display=AMD
     *     → display_price EQUALS SellerFxRateService::convert(...) amount (the
     *     exact future charge), fx_rate is the seller rate, fx_basis='seller'.
     *     This is NOT the mid-market value (mid-market USD→AMD would be 38000).
     */
    public function test_amd_display_with_seller_setting_uses_seller_rate_equal_to_charge(): void
    {
        $service = new DisplayCurrencyService($this->fxStub(), $this->sellerStub());

        $fields = $service->fieldsFor(100, 'USD', 'AMD', 42);

        // 100 * 402 = 40200.00 — exactly what ChargeAmountResolver would charge.
        $this->assertSame(40200.00, $fields['display_price']);
        $this->assertSame('AMD', $fields['display_currency']);
        $this->assertSame('402.0000', $fields['fx_rate']);
        $this->assertSame('seller', $fields['fx_basis']);

        // Sanity: it is explicitly NOT the mid-market 380 → 38000 result.
        $this->assertNotSame(38000.00, $fields['display_price']);
    }

    /**
     * (b) NO seller setting (operator with no configured rate) + display=AMD
     *     → display_price = mid-market (Part A) UNCHANGED, fx_basis='market'.
     *     Safe-by-default: the seller branch returns null and we fall through.
     */
    public function test_amd_display_without_seller_setting_stays_mid_market(): void
    {
        // sellerStub() only knows operator 42; operator 99 has no setting.
        $service = new DisplayCurrencyService($this->fxStub(), $this->sellerStub());

        $fields = $service->fieldsFor(100, 'USD', 'AMD', 99);

        // Mid-market USD→AMD = 380 → 100 * 380 = 38000.00 (Part A unchanged).
        $this->assertSame(38000.00, $fields['display_price']);
        $this->assertSame('AMD', $fields['display_currency']);
        $this->assertSame('380', $fields['fx_rate']);
        $this->assertSame('market', $fields['fx_basis']);
    }

    /**
     * (b') Same as (b) but NO operator context at all (null) — public reads that
     *      can't resolve an operator must also keep the mid-market Part A value.
     */
    public function test_amd_display_with_null_operator_stays_mid_market(): void
    {
        $service = new DisplayCurrencyService($this->fxStub(), $this->noSellerStub());

        $fields = $service->fieldsFor(100, 'USD', 'AMD');

        $this->assertSame(38000.00, $fields['display_price']);
        $this->assertSame('AMD', $fields['display_currency']);
        $this->assertSame('380', $fields['fx_rate']);
        $this->assertSame('market', $fields['fx_basis']);
    }

    /**
     * (c) Non-AMD display currency → mid-market UNCHANGED even when the operator
     *     HAS a seller setting. The seller branch must only fire for the charge
     *     currency (AMD); EUR display never consults SellerFxRateService.
     */
    public function test_non_amd_display_stays_mid_market_even_with_seller_setting(): void
    {
        $service = new DisplayCurrencyService($this->fxStub(), $this->sellerStub());

        $fields = $service->fieldsFor(100, 'USD', 'EUR', 42);

        // USD→EUR mid-market 0.9 → 90.00 (Part A), seller rate ignored.
        $this->assertSame(90.0, $fields['display_price']);
        $this->assertSame('EUR', $fields['display_currency']);
        $this->assertSame('0.9', $fields['fx_rate']);
        $this->assertSame('market', $fields['fx_basis']);
    }
}
