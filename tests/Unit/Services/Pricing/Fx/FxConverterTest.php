<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pricing\Fx;

use App\Models\ExchangeRate;
use App\Services\Pricing\Fx\FxConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FxConverterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Each test starts with a clean array-cache so previous test
        // lookups don't bleed across cases.
        Cache::driver('array')->flush();
    }

    private function fx(): FxConverter
    {
        return app(FxConverter::class);
    }

    public function test_same_currency_returns_one(): void
    {
        $this->assertSame('1.000000', $this->fx()->rate('USD', 'USD'));
    }

    public function test_returns_null_when_no_rate_seeded(): void
    {
        $this->assertNull($this->fx()->rate('USD', 'AMD'));
    }

    public function test_returns_cba_rate_when_only_cba_present(): void
    {
        ExchangeRate::create([
            'source_currency' => 'USD',
            'target_currency' => 'AMD',
            'rate' => '387.250000',
            'source' => ExchangeRate::SOURCE_CBA,
            'fetched_at' => now(),
            'is_active' => true,
        ]);

        $this->assertSame('387.250000', $this->fx()->rate('USD', 'AMD'));
    }

    public function test_partner_override_beats_manual_beats_cba(): void
    {
        ExchangeRate::create([
            'source_currency' => 'USD',
            'target_currency' => 'AMD',
            'rate' => '387.250000',
            'source' => ExchangeRate::SOURCE_CBA,
            'fetched_at' => now(),
            'is_active' => true,
        ]);
        ExchangeRate::create([
            'source_currency' => 'USD',
            'target_currency' => 'AMD',
            'rate' => '390.000000',
            'source' => ExchangeRate::SOURCE_MANUAL,
            'fetched_at' => now(),
            'is_active' => true,
        ]);
        ExchangeRate::create([
            'source_currency' => 'USD',
            'target_currency' => 'AMD',
            'rate' => '395.000000',
            'source' => ExchangeRate::SOURCE_PARTNER_OVERRIDE,
            'fetched_at' => now(),
            'is_active' => true,
        ]);

        $this->assertSame('395.000000', $this->fx()->rate('USD', 'AMD'));
    }

    public function test_convert_uses_bcmath_precision(): void
    {
        ExchangeRate::create([
            'source_currency' => 'USD',
            'target_currency' => 'AMD',
            'rate' => '387.250000',
            'source' => ExchangeRate::SOURCE_CBA,
            'fetched_at' => now(),
            'is_active' => true,
        ]);

        // 100 USD = 38725.00 AMD
        $this->assertSame('38725.0000', $this->fx()->convert('100', 'USD', 'AMD'));
    }

    public function test_inactive_rows_are_ignored(): void
    {
        ExchangeRate::create([
            'source_currency' => 'USD',
            'target_currency' => 'AMD',
            'rate' => '999.999999',
            'source' => ExchangeRate::SOURCE_CBA,
            'fetched_at' => now()->subDays(7),
            'is_active' => false,
        ]);
        ExchangeRate::create([
            'source_currency' => 'USD',
            'target_currency' => 'AMD',
            'rate' => '387.250000',
            'source' => ExchangeRate::SOURCE_CBA,
            'fetched_at' => now(),
            'is_active' => true,
        ]);

        $this->assertSame('387.250000', $this->fx()->rate('USD', 'AMD'));
    }
}
