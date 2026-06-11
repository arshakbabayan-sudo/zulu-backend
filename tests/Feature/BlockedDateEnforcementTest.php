<?php

namespace Tests\Feature;

use App\Models\BlockedDate;
use App\Models\Company;
use App\Models\Flight;
use App\Models\Hotel;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Cart\CartService;
use App\Services\Packages\PackageOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roadmap §4 — booking-time enforcement of operator "Block dates".
 *
 * Covers the three creation paths wired to BlockedDateChecker:
 *   - BookingService::assertItemsAvailability (offer bookings)
 *   - Cart add + checkout (POST /api/customer/cart/items, /checkout)
 *   - PackageOrderService::createOrder (flight components)
 *
 * A NULL item_id row blocks the whole item type for the company;
 * adjacent (non-overlapping) windows must pass the check.
 */
class BlockedDateEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_service_rejects_offer_level_blocked_date(): void
    {
        $company = $this->makeCompany();
        [$offer] = $this->makeHotelOffer($company);

        BlockedDate::query()->create([
            'company_id' => $company->id,
            'item_type' => 'offer',
            'item_id' => $offer->id,
            'blocked_from' => '2026-07-01',
            'blocked_to' => '2026-07-10',
            'reason' => 'sold out',
        ]);

        try {
            app(BookingService::class)->assertItemsAvailability([
                ['offer_id' => $offer->id, 'start_date' => '2026-07-05', 'end_date' => '2026-07-06'],
            ]);
            $this->fail('Expected ValidationException for blocked date.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
            $this->assertStringContainsString('blocked from 2026-07-01 to 2026-07-10', $e->errors()['items'][0]);
        }
    }

    public function test_booking_service_rejects_whole_type_block_with_null_item_id(): void
    {
        $company = $this->makeCompany();
        [$offer] = $this->makeHotelOffer($company);

        BlockedDate::query()->create([
            'company_id' => $company->id,
            'item_type' => 'hotel',
            'item_id' => null,
            'blocked_from' => '2026-07-01',
            'blocked_to' => '2026-07-10',
        ]);

        try {
            app(BookingService::class)->assertItemsAvailability([
                ['offer_id' => $offer->id, 'start_date' => '2026-07-10'],
            ]);
            $this->fail('Expected ValidationException for whole-type blocked date.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
    }

    public function test_booking_service_passes_adjacent_dates(): void
    {
        $company = $this->makeCompany();
        [$offer, $hotel] = $this->makeHotelOffer($company);

        BlockedDate::query()->create([
            'company_id' => $company->id,
            'item_type' => 'hotel',
            'item_id' => $hotel->id,
            'blocked_from' => '2026-07-01',
            'blocked_to' => '2026-07-10',
        ]);

        app(BookingService::class)->assertItemsAvailability([
            ['offer_id' => $offer->id, 'start_date' => '2026-07-11', 'end_date' => '2026-07-12'],
        ]);

        $this->assertTrue(true); // no exception past the blocked-date check
    }

    public function test_cart_add_item_on_blocked_date_returns_422(): void
    {
        $company = $this->makeCompany();
        [, $hotel] = $this->makeHotelOffer($company);

        BlockedDate::query()->create([
            'company_id' => $company->id,
            'item_type' => 'hotel',
            'item_id' => $hotel->id,
            'blocked_from' => '2026-08-01',
            'blocked_to' => '2026-08-05',
            'reason' => 'maintenance',
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/customer/cart/items', [
            'item_type' => 'hotel',
            'item_id' => $hotel->id,
            'unit_price' => 100,
            'currency' => 'USD',
            'date_from' => '2026-08-02',
            'date_to' => '2026-08-03',
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);
    }

    public function test_checkout_on_blocked_date_returns_422(): void
    {
        $company = $this->makeCompany();
        [, $hotel] = $this->makeHotelOffer($company);
        $user = User::factory()->create();

        // Item is carted BEFORE the operator blocks the dates.
        $cart = app(CartService::class)->addItem($user, [
            'item_type' => 'hotel',
            'item_id' => $hotel->id,
            'unit_price' => 100,
            'currency' => 'USD',
            'date_from' => '2026-08-02',
            'date_to' => '2026-08-03',
        ]);

        BlockedDate::query()->create([
            'company_id' => $company->id,
            'item_type' => 'hotel',
            'item_id' => $hotel->id,
            'blocked_from' => '2026-08-01',
            'blocked_to' => '2026-08-05',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/customer/cart/checkout', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        // Transaction rolled back — the cart stays a cart.
        $this->assertSame('cart', Order::query()->whereKey($cart->id)->value('status'));
    }

    public function test_checkout_with_adjacent_dates_succeeds(): void
    {
        $company = $this->makeCompany();
        [, $hotel] = $this->makeHotelOffer($company);
        $user = User::factory()->create();

        BlockedDate::query()->create([
            'company_id' => $company->id,
            'item_type' => 'hotel',
            'item_id' => $hotel->id,
            'blocked_from' => '2026-08-01',
            'blocked_to' => '2026-08-05',
        ]);

        app(CartService::class)->addItem($user, [
            'item_type' => 'hotel',
            'item_id' => $hotel->id,
            'unit_price' => 100,
            'currency' => 'USD',
            'date_from' => '2026-08-06',
            'date_to' => '2026-08-07',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/customer/cart/checkout', []);
        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'pending_payment');
    }

    public function test_package_order_rejects_blocked_flight_component(): void
    {
        $company = $this->makeCompany();
        $package = $this->makePackageWithFlightComponent($company, '2026-09-01 08:00:00');

        BlockedDate::query()->create([
            'company_id' => $company->id,
            'item_type' => 'flight',
            'item_id' => null,
            'blocked_from' => '2026-08-30',
            'blocked_to' => '2026-09-02',
        ]);

        try {
            app(PackageOrderService::class)->createOrder($package, User::factory()->create(), [
                'booking_channel' => 'public_b2c',
                'adults_count' => 1,
            ]);
            $this->fail('Expected ValidationException for blocked flight component.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        $this->assertSame(0, Order::query()->count());
    }

    public function test_package_order_allows_flight_outside_blocked_range(): void
    {
        $company = $this->makeCompany();
        $package = $this->makePackageWithFlightComponent($company, '2026-09-01 08:00:00');

        BlockedDate::query()->create([
            'company_id' => $company->id,
            'item_type' => 'flight',
            'item_id' => null,
            'blocked_from' => '2026-09-05',
            'blocked_to' => '2026-09-10',
        ]);

        $order = app(PackageOrderService::class)->createOrder($package, User::factory()->create(), [
            'booking_channel' => 'public_b2c',
            'adults_count' => 1,
        ]);

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame('pending_payment', $order->status);
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Blocked Enforcement '.str()->uuid(),
            'type' => 'operator',
        ]);
    }

    /**
     * @return array{0: Offer, 1: Hotel}
     */
    private function makeHotelOffer(Company $company): array
    {
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'Blocked Dates Hotel '.str()->uuid(),
            'price' => 120,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        $hotel = Hotel::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'location_id' => $this->locationIds()['yerevan_city'],
            'hotel_name' => 'ZULU Blocked Dates Hotel',
            'property_type' => 'hotel',
            'hotel_type' => 'resort',
            'meal_type' => 'bed_and_breakfast',
            'is_package_eligible' => false,
            'status' => 'draft',
        ]);

        return [$offer, $hotel];
    }

    private function makePackageWithFlightComponent(Company $company, string $departureAt): Package
    {
        $packageOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'Package Offer '.str()->uuid(),
            'price' => 200,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        $flightOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Flight Offer '.str()->uuid(),
            'price' => 120,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        Flight::query()->create([
            'offer_id' => $flightOffer->id,
            'company_id' => $company->id,
            'location_id' => $this->locationIds()['yerevan_city'],
            'flight_code_internal' => 'BLK-F1',
            'service_type' => 'scheduled',
            'departure_airport' => 'EVN',
            'arrival_airport' => 'SSH',
            'departure_at' => $departureAt,
            'arrival_at' => '2026-09-01 12:00:00',
            'duration_minutes' => 240,
            'connection_type' => 'direct',
            'stops_count' => 0,
            'cabin_class' => 'economy',
            'seat_capacity_total' => 150,
            'seat_capacity_available' => 20,
            'adult_age_from' => 18,
            'child_age_from' => 2,
            'child_age_to' => 11,
            'infant_age_from' => 0,
            'infant_age_to' => 1,
            'adult_price' => 120,
            'child_price' => 0,
            'infant_price' => 0,
            'hand_baggage_included' => false,
            'checked_baggage_included' => false,
            'reservation_allowed' => true,
            'online_checkin_allowed' => true,
            'airport_checkin_allowed' => true,
            'cancellation_policy_type' => 'non_refundable',
            'change_policy_type' => 'not_allowed',
            'seat_map_available' => false,
            'extra_baggage_allowed' => false,
            'is_package_eligible' => true,
            'status' => 'draft',
        ]);

        $package = Package::query()->create([
            'offer_id' => $packageOffer->id,
            'company_id' => $company->id,
            'package_type' => 'fixed',
            'package_title' => 'Blocked Enforcement Package',
            'currency' => 'USD',
            'is_public' => true,
            'is_bookable' => true,
            'status' => 'active',
        ]);

        PackageComponent::query()->create([
            'package_id' => $package->id,
            'offer_id' => $flightOffer->id,
            'module_type' => 'flight',
            'package_role' => 'flight',
            'is_required' => true,
            'sort_order' => 1,
            'selection_mode' => 'fixed',
            'price_override' => 120.00,
        ]);

        return $package;
    }
}
