<?php

namespace Tests\Feature;

use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Commissions\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionAccrualCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_accrue_for_order_uses_order_currency_eur(): void
    {
        $order = $this->createBookingForCurrencyCase('EUR');

        $transaction = app(CommissionService::class)->accrueForOrder($order);

        $this->assertNotNull($transaction);
        $this->assertSame('EUR', $transaction->commission_currency);
        // Phase 1 / B.4 — OrderService now applies the 15% markup
        // server-side, so the order total = 115 (not 100). Commission
        // resolver computes 10% * 115 = 11.5.
        $this->assertEqualsWithDelta(11.50, (float) $transaction->commission_amount, 0.0001);
        $this->assertIsArray($transaction->snapshot);
        $this->assertSame('percentage', $transaction->snapshot['type'] ?? null);
        $this->assertEqualsWithDelta(10.0, (float) ($transaction->snapshot['percentage_value'] ?? 0), 0.0001);
    }

    public function test_accrue_for_order_uses_order_currency_amd(): void
    {
        $order = $this->createBookingForCurrencyCase('AMD');

        $transaction = app(CommissionService::class)->accrueForOrder($order);

        $this->assertNotNull($transaction);
        $this->assertSame('AMD', $transaction->commission_currency);
        // Phase 1 / B.4 — OrderService now applies the 15% markup
        // server-side, so the order total = 115 (not 100). Commission
        // resolver computes 10% * 115 = 11.5.
        $this->assertEqualsWithDelta(11.50, (float) $transaction->commission_amount, 0.0001);
    }

    public function test_accrue_for_order_defaults_to_usd_when_missing(): void
    {
        $order = $this->createBookingForCurrencyCase('USD');
        $attributes = $order->getAttributes();
        unset($attributes['currency']);
        $order->setRawAttributes($attributes, true);

        $transaction = app(CommissionService::class)->accrueForOrder($order);

        $this->assertNotNull($transaction);
        $this->assertSame('USD', $transaction->commission_currency);
        // Phase 1 / B.4 — OrderService now applies the 15% markup
        // server-side, so the order total = 115 (not 100). Commission
        // resolver computes 10% * 115 = 11.5.
        $this->assertEqualsWithDelta(11.50, (float) $transaction->commission_amount, 0.0001);
    }

    public function test_accrue_for_order_normalizes_lowercase_currency_to_upper(): void
    {
        $order = $this->createBookingForCurrencyCase('amd');

        $transaction = app(CommissionService::class)->accrueForOrder($order);

        $this->assertNotNull($transaction);
        $this->assertSame('AMD', $transaction->commission_currency);
        // Phase 1 / B.4 — OrderService now applies the 15% markup
        // server-side, so the order total = 115 (not 100). Commission
        // resolver computes 10% * 115 = 11.5.
        $this->assertEqualsWithDelta(11.50, (float) $transaction->commission_amount, 0.0001);
    }

    private function createBookingForCurrencyCase(string $currency): Order
    {
        $company = Company::query()->create([
            'name' => 'Currency Test Company '.str()->uuid(),
            'type' => 'operator',
        ]);

        CommissionRule::query()->create([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => 'flight',
            'percentage_value' => 10,
            'direction' => 'zulu_from_seller',
            'priority' => 0,
            'effective_from' => now()->subMinute(),
            'status' => 'active',
            'active' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Currency Test User',
            'email' => 'currency-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Currency Flight '.str()->uuid(),
            'price' => 100.00,
            'currency' => strtoupper($currency),
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        $bookingService = app(BookingService::class);
        $order = $bookingService->create(
            [
                'company_id' => $company->id,
                'user_id' => $user->id,
                'currency' => $currency,
                'status' => 'pending_payment',
            ],
            [
                ['offer_id' => $offer->id, 'price' => 100.00],
            ]
        );

        return $bookingService->confirm($order->fresh());
    }
}
