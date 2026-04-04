<?php

namespace Tests\Unit\Services;

use App\Models\Car;
use App\Models\Company;
use App\Models\Excursion;
use App\Models\Flight;
use App\Models\Hotel;
use App\Models\Offer;
use App\Models\Package;
use App\Models\Transfer;
use App\Models\Visa;
use App\Services\Offers\OfferNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferNormalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private OfferNormalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OfferNormalizationService;
    }

    public function test_normalize_returns_null_for_unsupported_offer_type(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'not_a_real_module',
            'title' => 'X',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->assertNull($this->service->normalize($offer));
    }

    public function test_package_normalization_is_shallow_from_module_fields_only(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'Beach combo',
            'price' => 999,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        Package::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'destination_city' => 'Hurghada',
            'duration_days' => 7,
            'package_type' => 'semi_fixed',
        ]);

        $offer->load('package');
        $n = $this->service->normalize($offer);
        $this->assertNotNull($n);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($n));
        $this->assertSame('package', $n['module_type']);
        $this->assertSame('Beach combo', $n['title']);
        $this->assertSame('semi_fixed', $n['subtitle']);
        $this->assertSame('Hurghada', $n['destination_location']);
        $this->assertSame(7, $n['duration']);
        $this->assertTrue($n['is_package_eligible']);
        $this->assertSame('package', $n['package_role']);
        $this->assertNull($n['from_location']);
        $this->assertNull($n['bookable']);
    }

    public function test_visa_normalization_maps_country_type_and_processing_days(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'visa',
            'title' => 'Tourist visa',
            'price' => 50,
            'currency' => 'EUR',
            'status' => 'draft',
        ]);
        Visa::query()->create([
            'offer_id' => $offer->id,
            'country' => 'EG',
            'visa_type' => 'tourist',
            'processing_days' => 14,
        ]);

        $offer->load('visa');
        $n = $this->service->normalize($offer);
        $this->assertNotNull($n);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($n));
        $this->assertSame('visa', $n['module_type']);
        $this->assertSame('EG', $n['destination_location']);
        $this->assertSame('tourist', $n['subtitle']);
        $this->assertSame(14, $n['duration']);
        $this->assertNull($n['is_package_eligible']);
        $this->assertNull($n['package_role']);
    }

    public function test_normalize_package_returns_null_when_relation_not_loaded(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'P',
            'price' => 1,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->assertNull($this->service->normalize($offer));
    }

    public function test_normalize_car_returns_null_when_relation_not_loaded(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'car',
            'title' => 'Car',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->assertNull($this->service->normalize($offer));
    }

    public function test_car_normalization_maps_only_existing_module_fields(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'car',
            'title' => 'Sedan rental',
            'price' => 80,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        Car::query()->create([
            'offer_id' => $offer->id,
            'pickup_location' => 'EVN Airport',
            'dropoff_location' => 'City center',
            'vehicle_class' => 'economy',
        ]);

        $offer->load('car');
        $n = $this->service->normalize($offer);
        $this->assertNotNull($n);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($n));
        $this->assertSame('car', $n['module_type']);
        $this->assertSame('EVN Airport', $n['from_location']);
        $this->assertSame('City center', $n['to_location']);
        $this->assertSame('economy', $n['vehicle_type']);
        $this->assertIsArray($n['advanced_options']);
        $this->assertSame(1, $n['advanced_options']['v']);
        $this->assertFalse($n['advanced_options']['child_seats']['available']);
        $this->assertSame([], $n['advanced_options']['services']);
        $this->assertSame(80, $n['price']);
        $this->assertNull($n['capacity_type']);
        $this->assertNull($n['price_type']);
        $this->assertNull($n['package_role']);
        $this->assertNull($n['start_datetime']);
    }

    public function test_excursion_normalization_maps_location_duration_and_group_size(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'excursion',
            'title' => 'City tour',
            'price' => 25,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        Excursion::query()->create([
            'offer_id' => $offer->id,
            'location' => 'Yerevan old town',
            'duration' => 'Half day',
            'group_size' => 12,
        ]);

        $offer->load('excursion');
        $n = $this->service->normalize($offer);
        $this->assertNotNull($n);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($n));
        $this->assertSame('excursion', $n['module_type']);
        $this->assertSame('Yerevan old town', $n['destination_location']);
        $this->assertSame('Half day', $n['duration']);
        $this->assertSame(12, $n['max_passengers']);
        $this->assertNull($n['from_location']);
        $this->assertNull($n['to_location']);
        $this->assertNull($n['price_type']);
        $this->assertNull($n['package_role']);
    }

    public function test_normalize_flight_returns_null_when_relation_not_loaded(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'F',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->assertNull($this->service->normalize($offer));
    }

    public function test_flight_normalization_maps_matrix_fields_and_stable_key_order(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Yerevan → Sharm',
            'price' => 199,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        Flight::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'flight_code_internal' => 'X1',
            'service_type' => 'scheduled',
            'departure_country' => 'AM',
            'departure_city' => 'Yerevan',
            'departure_airport' => 'EVN',
            'arrival_country' => 'EG',
            'arrival_city' => 'Sharm',
            'arrival_airport' => 'SSH',
            'departure_airport_code' => 'EVN',
            'arrival_airport_code' => 'SSH',
            'departure_at' => '2026-08-01 10:00:00',
            'arrival_at' => '2026-08-01 15:00:00',
            'duration_minutes' => 300,
            'connection_type' => 'direct',
            'stops_count' => 0,
            'cabin_class' => 'economy',
            'seat_capacity_total' => 180,
            'seat_capacity_available' => 40,
            'adult_age_from' => 18,
            'child_age_from' => 2,
            'child_age_to' => 11,
            'infant_age_from' => 0,
            'infant_age_to' => 1,
            'adult_price' => 199,
            'child_price' => 0,
            'infant_price' => 0,
            'hand_baggage_included' => true,
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

        $offer->load('flight');
        $n = $this->service->normalize($offer);
        $this->assertNotNull($n);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($n));

        $this->assertSame($offer->id, $n['offer_id']);
        $this->assertSame('flight', $n['module_type']);
        $this->assertSame($company->id, $n['company_id']);
        $this->assertSame('Yerevan → Sharm', $n['title']);
        $this->assertStringContainsString('EVN', (string) $n['from_location']);
        $this->assertStringContainsString('SSH', (string) $n['to_location']);
        $this->assertSame(300, $n['duration']);
        $this->assertSame('per_person', $n['price_type']);
        $this->assertSame('seat', $n['capacity_type']);
        $this->assertSame(40, $n['available_quantity']);
        $this->assertTrue($n['bookable']);
        $this->assertSame('non_refundable', $n['refundable_type']);
        $this->assertTrue($n['is_direct']);
        $this->assertTrue($n['has_baggage']);
        $this->assertTrue($n['is_package_eligible']);
        $this->assertSame('flight', $n['package_role']);
        $this->assertArrayNotHasKey('hotel', $n);
        $this->assertNull($n['subtitle']);
        $this->assertNull($n['meal_type']);
        $this->assertNull($n['stars']);
        $this->assertNull($n['vehicle_type']);
    }

    public function test_hotel_normalization_null_safe_for_optional_fields(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'Stay',
            'price' => 120,
            'currency' => 'EUR',
            'status' => 'draft',
        ]);
        Hotel::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'hotel_name' => 'H',
            'property_type' => 'resort',
            'hotel_type' => 'standard',
            'star_rating' => null,
            'country' => 'AM',
            'city' => 'Yerevan',
            'short_description' => null,
            'main_image' => null,
            'meal_type' => 'room_only',
            'free_cancellation' => false,
            'cancellation_policy_type' => null,
            'review_score' => null,
            'review_count' => 0,
            'availability_status' => 'available',
            'bookable' => true,
            'is_package_eligible' => false,
            'status' => 'draft',
        ]);

        $offer->load('hotel');
        $n = $this->service->normalize($offer);
        $this->assertNotNull($n);
        $this->assertSame('hotel', $n['module_type']);
        $this->assertSame('per_room', $n['price_type']);
        $this->assertSame('room', $n['capacity_type']);
        $this->assertSame('stay', $n['package_role']);
        $this->assertSame('Yerevan, AM', $n['destination_location']);
        $this->assertNull($n['subtitle']);
        $this->assertNull($n['stars']);
        $this->assertNull($n['rating']);
        $this->assertSame('room_only', $n['meal_type']);
        $this->assertNull($n['from_location']);
        $this->assertNull($n['to_location']);
        $this->assertNull($n['start_datetime']);
        $this->assertNull($n['end_datetime']);
        $this->assertNull($n['duration']);
        $this->assertNull($n['is_direct']);
        $this->assertNull($n['has_baggage']);
        $this->assertNull($n['vehicle_type']);
    }

    public function test_transfer_normalization_combines_service_date_and_pickup_time(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'transfer',
            'title' => 'Airport run',
            'price' => 45,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        Transfer::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'transfer_title' => 'T1',
            'transfer_type' => 'airport_transfer',
            'pickup_country' => 'AM',
            'pickup_city' => 'Yerevan',
            'pickup_point_type' => 'airport',
            'pickup_point_name' => 'EVN',
            'dropoff_country' => 'AM',
            'dropoff_city' => 'Yerevan',
            'dropoff_point_type' => 'address',
            'dropoff_point_name' => 'Hotel X',
            'service_date' => '2026-07-10',
            'pickup_time' => '09:30:00',
            'estimated_duration_minutes' => 45,
            'vehicle_category' => 'sedan',
            'pricing_mode' => 'per_vehicle',
            'base_price' => 45.00,
            'passenger_capacity' => 4,
            'minimum_passengers' => 1,
            'maximum_passengers' => 3,
            'free_cancellation' => false,
            'cancellation_policy_type' => 'non_refundable',
            'availability_status' => 'available',
            'bookable' => true,
            'is_package_eligible' => false,
            'status' => 'active',
        ]);

        $offer->load('transfer');
        $n = $this->service->normalize($offer);
        $this->assertNotNull($n);
        $this->assertSame('transfer', $n['module_type']);
        $this->assertSame('per_vehicle', $n['price_type']);
        $this->assertSame('vehicle', $n['capacity_type']);
        $this->assertSame('transfer', $n['package_role']);
        $this->assertSame('sedan', $n['vehicle_type']);
        $this->assertSame(3, $n['max_passengers']);
        $this->assertSame(1, $n['min_passengers']);
        $this->assertNull($n['end_datetime']);
        $this->assertNotNull($n['start_datetime']);
        $this->assertStringContainsString('2026-07-10T09:30:00', (string) $n['start_datetime']);
    }

    public function test_no_module_cross_mixing_flight_payload_excludes_hotel_only_semantics(): void
    {
        $company = Company::query()->create([
            'name' => 'C1',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'F',
            'price' => 1,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        Flight::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'flight_code_internal' => 'Z',
            'service_type' => 'scheduled',
            'departure_country' => 'AM',
            'departure_city' => 'Yerevan',
            'departure_airport' => 'EVN',
            'arrival_country' => 'EG',
            'arrival_city' => 'Sharm',
            'arrival_airport' => 'SSH',
            'departure_at' => '2026-08-01 10:00:00',
            'arrival_at' => '2026-08-01 12:00:00',
            'duration_minutes' => 120,
            'connection_type' => 'connected',
            'stops_count' => 1,
            'cabin_class' => 'economy',
            'seat_capacity_total' => 100,
            'seat_capacity_available' => 10,
            'adult_age_from' => 18,
            'child_age_from' => 2,
            'child_age_to' => 11,
            'infant_age_from' => 0,
            'infant_age_to' => 1,
            'adult_price' => 1,
            'child_price' => 0,
            'infant_price' => 0,
            'hand_baggage_included' => false,
            'checked_baggage_included' => false,
            'reservation_allowed' => false,
            'online_checkin_allowed' => false,
            'airport_checkin_allowed' => false,
            'cancellation_policy_type' => 'fully_refundable',
            'change_policy_type' => 'not_allowed',
            'seat_map_available' => false,
            'extra_baggage_allowed' => false,
            'is_package_eligible' => false,
            'status' => 'draft',
        ]);
        $offer->load('flight');
        $n = $this->service->normalize($offer);
        $this->assertSame('flight', $n['module_type']);
        $this->assertSame('flight', $n['package_role']);
        $this->assertFalse($n['is_direct']);
        $this->assertFalse($n['has_baggage']);
        $this->assertNotSame('stay', $n['package_role']);
    }
}
