<?php

namespace Tests\Feature;

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
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogOfferDetailNormalizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_detail_includes_normalized_offer_for_published_flight(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Catalog flight',
            'price' => 50,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        Flight::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'flight_code_internal' => 'CAT-F1',
            'service_type' => 'scheduled',
            'departure_country' => 'AM',
            'departure_city' => 'Yerevan',
            'departure_airport' => 'EVN',
            'arrival_country' => 'EG',
            'arrival_city' => 'Sharm',
            'arrival_airport' => 'SSH',
            'departure_at' => '2026-09-01 08:00:00',
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
            'adult_price' => 50,
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
            'is_package_eligible' => false,
            'status' => 'draft',
        ]);

        $res = $this->getJson('/api/catalog/offers/'.$offer->id);
        $res->assertOk()->assertJsonPath('success', true);

        $norm = $res->json('data.normalized_offer');
        $this->assertIsArray($norm);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($norm));
        $this->assertSame('flight', $norm['module_type']);
        $this->assertSame($offer->id, $norm['offer_id']);
    }

    public function test_catalog_detail_includes_normalized_offer_for_published_hotel(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'Catalog hotel',
            'price' => 120,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        Hotel::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'hotel_name' => 'ZULU Cat Hotel',
            'property_type' => 'hotel',
            'hotel_type' => 'resort',
            'country' => 'AM',
            'city' => 'Yerevan',
            'meal_type' => 'bed_and_breakfast',
            'is_package_eligible' => false,
            'status' => 'draft',
        ]);

        $res = $this->getJson('/api/catalog/offers/'.$offer->id);
        $res->assertOk()->assertJsonPath('success', true);

        $norm = $res->json('data.normalized_offer');
        $this->assertIsArray($norm);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($norm));
        $this->assertSame('hotel', $norm['module_type']);
    }

    public function test_catalog_detail_includes_normalized_offer_for_published_transfer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'transfer',
            'title' => 'Catalog transfer',
            'price' => 55,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        Transfer::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'transfer_title' => 'Airport run',
            'transfer_type' => 'private_transfer',
            'pickup_country' => 'AM',
            'pickup_city' => 'Yerevan',
            'pickup_point_type' => 'airport',
            'pickup_point_name' => 'EVN',
            'dropoff_country' => 'AM',
            'dropoff_city' => 'Yerevan',
            'dropoff_point_type' => 'hotel',
            'dropoff_point_name' => 'Downtown',
            'vehicle_category' => 'sedan',
            'passenger_capacity' => 3,
            'luggage_capacity' => 2,
            'minimum_passengers' => 1,
            'maximum_passengers' => 3,
            'service_date' => '2026-09-01',
            'pickup_time' => '10:00:00',
            'estimated_duration_minutes' => 45,
            'pricing_mode' => 'per_vehicle',
            'base_price' => 55,
            'cancellation_policy_type' => 'non_refundable',
            'availability_status' => 'available',
            'bookable' => true,
            'free_cancellation' => false,
            'is_package_eligible' => false,
            'status' => 'draft',
        ]);

        $res = $this->getJson('/api/catalog/offers/'.$offer->id);
        $res->assertOk()->assertJsonPath('success', true);

        $norm = $res->json('data.normalized_offer');
        $this->assertIsArray($norm);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($norm));
        $this->assertSame('transfer', $norm['module_type']);
    }

    public function test_catalog_detail_includes_normalized_offer_for_published_car(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'car',
            'title' => 'Catalog car',
            'price' => 30,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        Car::query()->create([
            'offer_id' => $offer->id,
            'pickup_location' => 'Lot A',
            'dropoff_location' => 'Lot B',
            'vehicle_class' => 'economy',
        ]);

        $res = $this->getJson('/api/catalog/offers/'.$offer->id);
        $res->assertOk()->assertJsonPath('success', true);

        $norm = $res->json('data.normalized_offer');
        $this->assertIsArray($norm);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($norm));
        $this->assertSame('car', $norm['module_type']);
        $this->assertSame('Lot A', $norm['from_location']);
        $this->assertSame('economy', $norm['vehicle_type']);
        $this->assertArrayHasKey('car', $res->json('data'));
    }

    public function test_catalog_detail_includes_normalized_offer_for_published_excursion(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'excursion',
            'title' => 'Catalog tour',
            'price' => 40,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        Excursion::query()->create([
            'offer_id' => $offer->id,
            'location' => 'Garni',
            'duration' => '4 hours',
            'group_size' => 8,
        ]);

        $res = $this->getJson('/api/catalog/offers/'.$offer->id);
        $res->assertOk()->assertJsonPath('success', true);

        $norm = $res->json('data.normalized_offer');
        $this->assertIsArray($norm);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($norm));
        $this->assertSame('excursion', $norm['module_type']);
        $this->assertSame('Garni', $norm['destination_location']);
        $this->assertSame(8, $norm['max_passengers']);
    }

    public function test_catalog_detail_includes_normalized_offer_for_published_package(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'Catalog package',
            'price' => 900,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        Package::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'destination_city' => 'Sharm',
            'duration_days' => 5,
            'package_type' => 'fixed',
        ]);

        $res = $this->getJson('/api/catalog/offers/'.$offer->id);
        $res->assertOk()->assertJsonPath('success', true);

        $norm = $res->json('data.normalized_offer');
        $this->assertIsArray($norm);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($norm));
        $this->assertSame('package', $norm['module_type']);
        $this->assertSame('Sharm', $norm['destination_location']);
        $this->assertSame(5, $norm['duration']);
        $this->assertTrue($norm['is_package_eligible']);
        $this->assertSame('package', $norm['package_role']);
    }

    public function test_catalog_detail_includes_normalized_offer_for_published_visa(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'visa',
            'title' => 'Catalog visa',
            'price' => 60,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        Visa::query()->create([
            'offer_id' => $offer->id,
            'country' => 'AE',
            'visa_type' => 'visit',
            'processing_days' => 5,
        ]);

        $res = $this->getJson('/api/catalog/offers/'.$offer->id);
        $res->assertOk()->assertJsonPath('success', true);

        $norm = $res->json('data.normalized_offer');
        $this->assertIsArray($norm);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($norm));
        $this->assertSame('visa', $norm['module_type']);
        $this->assertSame('AE', $norm['destination_location']);
        $this->assertSame('visit', $norm['subtitle']);
        $this->assertSame(5, $norm['duration']);
    }

    public function test_catalog_list_never_includes_normalized_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();

        $flightOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'List flight',
            'price' => 10,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        Flight::query()->create([
            'offer_id' => $flightOffer->id,
            'company_id' => $company->id,
            'flight_code_internal' => 'LST-F1',
            'service_type' => 'scheduled',
            'departure_country' => 'AM',
            'departure_city' => 'Yerevan',
            'departure_airport' => 'EVN',
            'arrival_country' => 'EG',
            'arrival_city' => 'Sharm',
            'arrival_airport' => 'SSH',
            'departure_at' => '2026-09-01 08:00:00',
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
            'adult_price' => 10,
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
            'is_package_eligible' => false,
            'status' => 'draft',
        ]);

        $carOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'car',
            'title' => 'List car',
            'price' => 20,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        Car::query()->create([
            'offer_id' => $carOffer->id,
            'pickup_location' => 'P',
            'dropoff_location' => 'D',
            'vehicle_class' => 'compact',
        ]);

        $excursionOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'excursion',
            'title' => 'List excursion',
            'price' => 15,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        Excursion::query()->create([
            'offer_id' => $excursionOffer->id,
            'location' => 'X',
            'duration' => '1h',
            'group_size' => 5,
        ]);

        $packageOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'package',
            'title' => 'List package',
            'price' => 500,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        Package::query()->create([
            'offer_id' => $packageOffer->id,
            'company_id' => $company->id,
            'destination_city' => 'Z',
            'duration_days' => 3,
            'package_type' => 'dynamic',
        ]);

        $visaOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'visa',
            'title' => 'List visa',
            'price' => 25,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        Visa::query()->create([
            'offer_id' => $visaOffer->id,
            'country' => 'JP',
            'visa_type' => 'transit',
            'processing_days' => null,
        ]);

        $res = $this->getJson('/api/catalog/offers');
        $res->assertOk()->assertJsonPath('success', true);

        $rows = $res->json('data');
        $this->assertIsArray($rows);
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $this->assertArrayNotHasKey('normalized_offer', $row);
        }
    }

    public function test_catalog_detail_hides_flight_when_not_visible_for_web(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Hidden flight',
            'price' => 70,
            'currency' => 'USD',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        Flight::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'flight_code_internal' => 'HIDE-WEB-1',
            'service_type' => 'scheduled',
            'departure_country' => 'AM',
            'departure_city' => 'Yerevan',
            'departure_airport' => 'EVN',
            'arrival_country' => 'EG',
            'arrival_city' => 'Sharm',
            'arrival_airport' => 'SSH',
            'departure_at' => '2026-09-01 08:00:00',
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
            'adult_price' => 70,
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
            'is_package_eligible' => false,
            'appears_in_web' => false,
            'status' => 'draft',
        ]);

        $this->getJson('/api/catalog/offers/'.$offer->id)->assertStatus(404);
    }
}
