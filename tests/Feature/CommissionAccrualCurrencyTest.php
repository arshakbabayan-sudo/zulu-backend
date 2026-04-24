<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\User;
use App\Services\Commissions\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionAccrualCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_accrue_for_booking_uses_booking_currency_eur(): void
    {
        $booking = $this->createBookingForCurrencyCase('EUR');

        $transaction = app(CommissionService::class)->accrueForBooking($booking);

        $this->assertNotNull($transaction);
        $this->assertSame('EUR', $transaction->commission_currency);
        $this->assertEqualsWithDelta(10.00, (float) $transaction->commission_amount, 0.0001);
        $this->assertIsArray($transaction->snapshot);
        $this->assertSame('percentage', $transaction->snapshot['type'] ?? null);
        $this->assertEqualsWithDelta(10.0, (float) ($transaction->snapshot['percentage_value'] ?? 0), 0.0001);
    }

    public function test_accrue_for_booking_uses_booking_currency_amd(): void
    {
        $booking = $this->createBookingForCurrencyCase('AMD');

        $transaction = app(CommissionService::class)->accrueForBooking($booking);

        $this->assertNotNull($transaction);
        $this->assertSame('AMD', $transaction->commission_currency);
        $this->assertEqualsWithDelta(10.00, (float) $transaction->commission_amount, 0.0001);
    }

    public function test_accrue_for_booking_defaults_to_usd_when_missing(): void
    {
        $booking = $this->createBookingForCurrencyCase('USD');
        $attributes = $booking->getAttributes();
        unset($attributes['currency']);
        $booking->setRawAttributes($attributes, true);

        $transaction = app(CommissionService::class)->accrueForBooking($booking);

        $this->assertNotNull($transaction);
        $this->assertSame('USD', $transaction->commission_currency);
        $this->assertEqualsWithDelta(10.00, (float) $transaction->commission_amount, 0.0001);
    }

    public function test_accrue_for_booking_normalizes_lowercase_currency_to_upper(): void
    {
        $booking = $this->createBookingForCurrencyCase('amd');

        $transaction = app(CommissionService::class)->accrueForBooking($booking);

        $this->assertNotNull($transaction);
        $this->assertSame('AMD', $transaction->commission_currency);
        $this->assertEqualsWithDelta(10.00, (float) $transaction->commission_amount, 0.0001);
    }

    private function createBookingForCurrencyCase(string $currency): Booking
    {
        $company = Company::query()->create([
            'name' => 'Currency Test Company '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);

        CommissionRule::query()->create([
            'type' => 'percentage',
            'level' => 'seller',
            'scope_id' => $company->id,
            'service_type' => 'general',
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

        return Booking::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'total_price' => 100.00,
            'currency' => $currency,
        ]);
    }
}
