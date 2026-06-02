<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\PlatformAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase 5 — getPlatformStats / getFinanceSummary scoped to visibleCompanyIds.
 * Service-level: super (null scope) = platform-wide; a scoped caller sees only
 * their companies' numbers; empty scope = zeroes. (HTTP path is exercised by
 * BookingsStatsScopeTest; the gated dashboard endpoint needs a real caller.)
 */
class PlatformStatsScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_platform_stats_scopes_orders_to_company(): void
    {
        $a = Company::query()->create(['name' => 'PS A', 'type' => 'operator', 'status' => 'active']);
        $b = Company::query()->create(['name' => 'PS B', 'type' => 'operator', 'status' => 'active']);
        $buyer = User::factory()->create();
        foreach ([$a->id, $a->id, $b->id] as $cid) {
            Order::query()->create([
                'order_number' => 'PS-'.str()->uuid(), 'user_id' => $buyer->id,
                'company_id' => $cid, 'status' => 'paid', 'currency' => 'USD', 'total' => 10,
            ])->forceFill(['metadata' => ['legacy_origin' => 'booking']])->save();
        }

        $svc = app(PlatformAdminService::class);

        // Super (null) sees all 3 bookings + both companies.
        $all = $svc->getPlatformStats(null, 'all');
        $this->assertSame(3, $all['bookings_total']);
        $this->assertGreaterThanOrEqual(2, $all['companies_total']);

        // Scoped to company A → 2 bookings, 1 company.
        $scoped = $svc->getPlatformStats([$a->id], 'scope_a');
        $this->assertSame(2, $scoped['bookings_total']);
        $this->assertSame(1, $scoped['companies_total']);

        // Empty scope → zeroes.
        $empty = $svc->getPlatformStats([], 'scope_empty');
        $this->assertSame(0, $empty['bookings_total']);
        $this->assertSame(0, $empty['companies_total']);
    }

    public function test_finance_summary_scoped_queries_execute_and_empty_is_zero(): void
    {
        // Validates the scoped finance queries RUN without SQL error — the
        // payments whereExists(invoice→order→company) subquery is the risky
        // part. Correctness with real money is verified live (super has real
        // payments/commissions); building valid commission_transactions rows
        // needs commission_rules + order_items FKs, out of scope for a unit.
        $a = Company::query()->create(['name' => 'FS A', 'type' => 'operator', 'status' => 'active']);
        $svc = app(PlatformAdminService::class);

        $all = $svc->getFinanceSummary(null, 'all');
        $scoped = $svc->getFinanceSummary([$a->id], 'scope_a');
        $empty = $svc->getFinanceSummary([], 'scope_empty');

        // Shape present, no exception.
        foreach (['total_payments_paid', 'total_commission_accrued', 'payments_count_paid', 'commission_records_count'] as $k) {
            $this->assertArrayHasKey($k, $all);
            $this->assertArrayHasKey($k, $scoped);
        }
        // Empty scope = nothing.
        $this->assertSame(0.0, (float) $empty['total_payments_paid']);
        $this->assertSame(0.0, (float) $empty['total_commission_accrued']);
        $this->assertSame(0, $empty['payments_count_paid']);
        $this->assertSame(0, $empty['commission_records_count']);
    }
}
