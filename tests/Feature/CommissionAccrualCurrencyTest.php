<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CommissionPolicy;
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

        $record = app(CommissionService::class)->accrueForBooking($booking);

        $this->assertNotNull($record);
        $this->assertSame('EUR', $record->currency);
        $this->assertEqualsWithDelta(10.00, (float) $record->commission_amount_snapshot, 0.0001);
    }

    public function test_accrue_for_booking_uses_booking_currency_amd(): void
    {
        $booking = $this->createBookingForCurrencyCase('AMD');

        $record = app(CommissionService::class)->accrueForBooking($booking);

        $this->assertNotNull($record);
        $this->assertSame('AMD', $record->currency);
        $this->assertEqualsWithDelta(10.00, (float) $record->commission_amount_snapshot, 0.0001);
    }

    public function test_accrue_for_booking_defaults_to_usd_when_missing(): void
    {
        $booking = $this->createBookingForCurrencyCase('USD');
        $attributes = $booking->getAttributes();
        unset($attributes['currency']);
        $booking->setRawAttributes($attributes, true);

        $record = app(CommissionService::class)->accrueForBooking($booking);

        $this->assertNotNull($record);
        $this->assertSame('USD', $record->currency);
        $this->assertEqualsWithDelta(10.00, (float) $record->commission_amount_snapshot, 0.0001);
    }

    public function test_accrue_for_booking_normalizes_lowercase_currency_to_upper(): void
    {
        $booking = $this->createBookingForCurrencyCase('amd');

        $record = app(CommissionService::class)->accrueForBooking($booking);

        $this->assertNotNull($record);
        $this->assertSame('AMD', $record->currency);
        $this->assertEqualsWithDelta(10.00, (float) $record->commission_amount_snapshot, 0.0001);
    }

    private function createBookingForCurrencyCase(string $currency): Booking
    {
        $company = Company::query()->create([
            'name' => 'Currency Test Company '.str()->uuid(),
            'type' => 'operator',
            'status' => 'active',
        ]);

        CommissionPolicy::query()->create([
            'company_id' => $company->id,
            'service_type' => 'general',
            'commission_mode' => 'percent',
            'percent' => 10,
            'status' => 'active',
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
