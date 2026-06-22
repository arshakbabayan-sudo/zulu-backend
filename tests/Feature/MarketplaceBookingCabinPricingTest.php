<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Flight;
use App\Models\FlightCabin;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Flight cabin pricing — POST /api/marketplace/bookings must charge the SELECTED
 * cabin (meta.cabin_id), not the bare offer.price. The chosen cabin's RAW
 * adult_price is threaded as PricingResolver's price_override so the same b2c
 * markup the customer saw on-screen (cabin.b2c_adult_price) is re-applied →
 * screen == charge. SAFE-BY-DEFAULT: no cabin_id (or a non-flight offer) keeps
 * the legacy offer.price path byte-identical. A cabin from a different flight,
 * or a non-existent cabin id, is rejected 422 (a client cannot smuggle a
 * cheaper/foreign cabin).
 */
class MarketplaceBookingCabinPricingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Company, 1: User, 2: Offer, 3: Flight, 4: FlightCabin, 5: FlightCabin}
     */
    private function seedFlightScenario(): array
    {
        $company = Company::query()->create([
            'name' => 'Marketplace Cabin Co '.str()->uuid(),
            'type' => 'operator',
        ]);

        $user = User::query()->create([
            'name' => 'Marketplace Cabin Buyer',
            'email' => 'marketplace-cabin-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Cabin Flight Offer '.str()->uuid(),
            'price' => 100.00,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        $flight = Flight::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'flight_code_internal' => 'CBN-'.str()->uuid(),
            'service_type' => 'scheduled',
            'cabin_class' => 'economy',
            'seat_capacity_total' => 100,
            'seat_capacity_available' => 100,
            'adult_price' => 100.00,
            'status' => 'active',
        ]);

        // Economy = 200 raw → b2c 230.00; Business = 500 raw → b2c 575.00.
        $economy = FlightCabin::query()->create([
            'flight_id' => $flight->id,
            'cabin_class' => 'economy',
            'seat_capacity_total' => 50,
            'seat_capacity_available' => 50,
            'adult_price' => 200.00,
        ]);

        $business = FlightCabin::query()->create([
            'flight_id' => $flight->id,
            'cabin_class' => 'business',
            'seat_capacity_total' => 20,
            'seat_capacity_available' => 20,
            'adult_price' => 500.00,
        ]);

        Sanctum::actingAs($user);

        return [$company, $user, $offer, $flight, $economy, $business];
    }

    public function test_booking_with_business_cabin_charges_that_cabin_b2c_price_times_quantity(): void
    {
        [, , $offer, , , $business] = $this->seedFlightScenario();

        $response = $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
            'quantity' => 2,
            'meta' => [
                'pax' => ['adults' => 2, 'children' => 0],
                'cabin' => 'business',
                'cabin_id' => $business->id,
            ],
        ]);

        $response->assertOk();
        $order = Order::query()->with('items')->findOrFail($response->json('data.order.id'));
        $item = $order->items->first();

        // Business cabin raw 500.00 → b2cPrice(500) = 575.00; × quantity 2 = 1150.00.
        // NOT offer.price (100 → 115). Screen (cabin.b2c_adult_price) == charge.
        $this->assertSame(2, (int) $item->quantity);
        $this->assertSame('575.00', number_format((float) $item->unit_price, 2, '.', ''));
        $this->assertSame('1150.00', number_format((float) $item->total, 2, '.', ''));
        $this->assertSame('1150.00', number_format((float) $order->total, 2, '.', ''));
    }

    public function test_economy_cabin_uses_cabin_price_not_offer_price(): void
    {
        [, , $offer, , $economy] = $this->seedFlightScenario();

        $response = $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
            'meta' => ['cabin_id' => $economy->id],
        ]);

        $response->assertOk();
        $order = Order::query()->with('items')->findOrFail($response->json('data.order.id'));

        // Economy raw 200.00 → b2c 230.00 (offer.price would have been 115.00).
        $this->assertSame('230.00', number_format((float) $order->total, 2, '.', ''));
    }

    public function test_cabin_from_another_flight_is_rejected_422_and_creates_no_order(): void
    {
        [$company, , $offer] = $this->seedFlightScenario();

        // A second flight (under a second offer) with its own cheap cabin.
        $otherOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Other Flight Offer '.str()->uuid(),
            'price' => 100.00,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        $otherFlight = Flight::query()->create([
            'offer_id' => $otherOffer->id,
            'company_id' => $company->id,
            'flight_code_internal' => 'OTH-'.str()->uuid(),
            'service_type' => 'scheduled',
            'cabin_class' => 'economy',
            'seat_capacity_total' => 10,
            'seat_capacity_available' => 10,
            'adult_price' => 10.00,
            'status' => 'active',
        ]);
        $foreignCabin = FlightCabin::query()->create([
            'flight_id' => $otherFlight->id,
            'cabin_class' => 'economy',
            'seat_capacity_total' => 5,
            'seat_capacity_available' => 5,
            'adult_price' => 1.00, // cheap — must NOT be chargeable on $offer
        ]);

        $before = Order::query()->count();

        $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
            'meta' => ['cabin_id' => $foreignCabin->id],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Selected cabin does not belong to this flight.');

        $this->assertSame($before, Order::query()->count());
    }

    public function test_nonexistent_cabin_id_is_rejected_422(): void
    {
        [, , $offer] = $this->seedFlightScenario();

        $before = Order::query()->count();

        $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
            'meta' => ['cabin_id' => 999999],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Selected cabin does not belong to this flight.');

        $this->assertSame($before, Order::query()->count());
    }

    public function test_booking_without_cabin_id_charges_offer_price_b2c_unchanged(): void
    {
        [, , $offer] = $this->seedFlightScenario();

        $response = $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $offer->id,
        ]);

        $response->assertOk();
        $order = Order::query()->with('items')->findOrFail($response->json('data.order.id'));
        $item = $order->items->first();

        // Legacy path: offer.price 100.00 → b2c 115.00, quantity 1. Unchanged.
        $this->assertSame(1, (int) $item->quantity);
        $this->assertSame('115.00', number_format((float) $item->unit_price, 2, '.', ''));
        $this->assertSame('115.00', number_format((float) $order->total, 2, '.', ''));
    }

    public function test_non_flight_offer_ignores_cabin_id_and_uses_offer_price(): void
    {
        $company = Company::query()->create([
            'name' => 'Marketplace Hotel Co '.str()->uuid(),
            'type' => 'operator',
        ]);
        $user = User::query()->create([
            'name' => 'Marketplace Hotel Buyer',
            'email' => 'marketplace-hotel-'.str()->uuid().'@example.test',
            'password' => 'password',
        ]);
        $hotelOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'Hotel Offer '.str()->uuid(),
            'price' => 100.00,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        Sanctum::actingAs($user);

        // cabin_id present but the offer is NOT a flight → cabin_id is ignored,
        // no 422, offer.price b2c used.
        $response = $this->postJson('/api/marketplace/bookings', [
            'offer_id' => $hotelOffer->id,
            'meta' => ['cabin_id' => 12345],
        ]);

        $response->assertOk();
        $order = Order::query()->findOrFail($response->json('data.order.id'));

        $this->assertSame('115.00', number_format((float) $order->total, 2, '.', ''));
    }
}
