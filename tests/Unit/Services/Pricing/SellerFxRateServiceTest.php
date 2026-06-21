<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pricing;

use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\SellerFxRate;
use App\Services\Pricing\SellerFxRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerFxRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $operatorCompany;

    private Company $agentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        // seller_fx_rates.company_id has a FK to companies — create real rows
        // so the inserts below satisfy the constraint.
        $this->operatorCompany = Company::create(['name' => 'Operator Co', 'type' => 'operator']);
        $this->agentCompany = Company::create(['name' => 'Agent Co', 'type' => 'agency']);
    }

    private function service(): SellerFxRateService
    {
        return app(SellerFxRateService::class);
    }

    private function seedCbaUsdAmd(string $rate = '387.250000'): void
    {
        ExchangeRate::create([
            'source_currency' => 'USD',
            'target_currency' => 'AMD',
            'rate' => $rate,
            'source' => ExchangeRate::SOURCE_CBA,
            'fetched_at' => now(),
            'is_active' => true,
        ]);
    }

    public function test_resolve_setting_precedence_agent_over_operator_over_platform(): void
    {
        $opId = $this->operatorCompany->id;
        $agId = $this->agentCompany->id;

        $platform = SellerFxRate::create([
            'scope' => SellerFxRate::SCOPE_PLATFORM,
            'company_id' => null,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_CBA,
            'margin' => '1',
            'is_active' => true,
        ]);
        $operator = SellerFxRate::create([
            'scope' => SellerFxRate::SCOPE_OPERATOR,
            'company_id' => $opId,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_CBA,
            'margin' => '2',
            'is_active' => true,
        ]);
        $agent = SellerFxRate::create([
            'scope' => SellerFxRate::SCOPE_AGENT,
            'company_id' => $agId,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_CBA,
            'margin' => '3',
            'is_active' => true,
        ]);

        // Agent present → agent wins.
        $this->assertSame(
            $agent->id,
            $this->service()->resolveSetting($opId, $agId, 'AMD')?->id
        );

        // No agent → operator wins.
        $this->assertSame(
            $operator->id,
            $this->service()->resolveSetting($opId, null, 'AMD')?->id
        );

        // Neither agent nor operator setting matches → platform wins.
        $this->assertSame(
            $platform->id,
            $this->service()->resolveSetting(999999, 888888, 'AMD')?->id
        );
    }

    public function test_resolve_setting_returns_null_when_none(): void
    {
        $this->assertNull($this->service()->resolveSetting(999999, 888888, 'AMD'));
    }

    public function test_effective_rate_cba_source_is_cba_rate_plus_absolute_margin(): void
    {
        $this->seedCbaUsdAmd('400.000000');

        $setting = SellerFxRate::create([
            'scope' => SellerFxRate::SCOPE_OPERATOR,
            'company_id' => $this->operatorCompany->id,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_CBA,
            'margin' => '2',
            'is_active' => true,
        ]);

        // 400 + 2 = 402.0000
        $this->assertSame('402.0000', $this->service()->effectiveRate('USD', 'AMD', $setting));
    }

    public function test_effective_rate_manual_source_is_manual_entry_plus_margin(): void
    {
        $setting = SellerFxRate::create([
            'scope' => SellerFxRate::SCOPE_OPERATOR,
            'company_id' => $this->operatorCompany->id,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_MANUAL,
            'margin' => '2.5',
            'manual_rates' => ['USD' => '400.0000', 'EUR' => '430.0000'],
            'is_active' => true,
        ]);

        // 400 + 2.5 = 402.5000
        $this->assertSame('402.5000', $this->service()->effectiveRate('USD', 'AMD', $setting));
        // 430 + 2.5 = 432.5000
        $this->assertSame('432.5000', $this->service()->effectiveRate('EUR', 'AMD', $setting));
    }

    public function test_effective_rate_same_currency_is_one_with_no_margin(): void
    {
        $setting = SellerFxRate::create([
            'scope' => SellerFxRate::SCOPE_OPERATOR,
            'company_id' => $this->operatorCompany->id,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_CBA,
            'margin' => '50',
            'is_active' => true,
        ]);

        // Same currency → rate 1, margin NOT applied.
        $this->assertSame('1', $this->service()->effectiveRate('AMD', 'AMD', $setting));
    }

    public function test_effective_rate_returns_null_when_no_base_rate(): void
    {
        // CBA setting but no exchange_rates cba row seeded.
        $cbaSetting = SellerFxRate::create([
            'scope' => SellerFxRate::SCOPE_PLATFORM,
            'company_id' => null,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_CBA,
            'margin' => '2',
            'is_active' => true,
        ]);
        $this->assertNull($this->service()->effectiveRate('USD', 'AMD', $cbaSetting));

        // Manual setting but the requested currency is missing from manual_rates.
        $manualSetting = SellerFxRate::create([
            'scope' => SellerFxRate::SCOPE_OPERATOR,
            'company_id' => $this->operatorCompany->id,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_MANUAL,
            'margin' => '2',
            'manual_rates' => ['USD' => '400.0000'],
            'is_active' => true,
        ]);
        $this->assertNull($this->service()->effectiveRate('EUR', 'AMD', $manualSetting));
    }

    public function test_convert_returns_amount_and_snapshot_shape(): void
    {
        $this->seedCbaUsdAmd('400.000000');

        $setting = SellerFxRate::create([
            'scope' => SellerFxRate::SCOPE_OPERATOR,
            'company_id' => $this->operatorCompany->id,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_CBA,
            'margin' => '2',
            'is_active' => true,
        ]);

        $result = $this->service()->convert('100', 'USD', 'AMD', $this->operatorCompany->id, null);

        $this->assertNotNull($result);
        // 100 * (400 + 2) = 40200.00
        $this->assertSame(40200.00, $result['amount']);
        $this->assertSame('402.0000', $result['rate']);
        $this->assertSame('AMD', $result['target_currency']);
        $this->assertSame(SellerFxRate::SOURCE_CBA, $result['source']);
        $this->assertSame($setting->id, $result['setting_id']);
    }

    public function test_convert_same_currency_leaves_amount_unchanged_rate_one(): void
    {
        // No setting at all → effectiveRate short-circuits to '1' for same cur.
        $result = $this->service()->convert('100', 'AMD', 'AMD', $this->operatorCompany->id, null);

        $this->assertNotNull($result);
        $this->assertSame(100.00, $result['amount']);
        $this->assertSame('1', $result['rate']);
        $this->assertSame('AMD', $result['target_currency']);
        $this->assertNull($result['source']);
        $this->assertNull($result['setting_id']);
    }

    public function test_convert_returns_null_when_no_base_rate(): void
    {
        SellerFxRate::create([
            'scope' => SellerFxRate::SCOPE_PLATFORM,
            'company_id' => null,
            'target_currency' => 'AMD',
            'source' => SellerFxRate::SOURCE_CBA,
            'margin' => '2',
            'is_active' => true,
        ]);

        // No exchange_rates cba row → no base rate → null.
        $this->assertNull($this->service()->convert('100', 'USD', 'AMD', null, null));
    }
}
