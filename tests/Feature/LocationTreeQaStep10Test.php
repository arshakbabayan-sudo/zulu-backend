<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Hotel;
use App\Models\Location;
use App\Models\Offer;
use App\Models\User;
use Database\Seeders\LocationQaSeeder;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocationTreeQaStep10Test extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('qa')->plainTextToken];
    }

    private function admin(): User
    {
        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }

    private function company(): Company
    {
        return Company::query()->firstOrFail();
    }

    private function makeOffer(Company $company, string $type, string $title): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => $type,
            'title' => $title,
            'price' => 100,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }

    private function location(string $name, string $type): Location
    {
        return Location::query()->where('name', $name)->where('type', $type)->firstOrFail();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacBootstrapSeeder::class);
        $this->seed(LocationQaSeeder::class);
    }

    public function test_ajax_tree_children_and_full_path_name_work(): void
    {
        $headers = $this->authHeaders($this->admin());

        $countries = $this->getJson('/api/locations/tree/children', $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');

        $armenia = collect($countries)->firstWhere('name', 'Armenia');
        $this->assertNotNull($armenia);

        $regions = $this->getJson('/api/locations/tree/children?parent_id='.$armenia['id'], $headers)
            ->assertOk()
            ->json('data');
        $kotayk = collect($regions)->firstWhere('name', 'Kotayk');
        $this->assertNotNull($kotayk);

        $cities = $this->getJson('/api/locations/tree/children?parent_id='.$kotayk['id'], $headers)
            ->assertOk()
            ->json('data');
        $tsaghkadzor = collect($cities)->firstWhere('name', 'Tsaghkadzor');
        $this->assertNotNull($tsaghkadzor);

        $node = $this->getJson('/api/locations/tree/node/'.$tsaghkadzor['id'], $headers)
            ->assertOk()
            ->json('data');

        $this->assertSame('Armenia, Kotayk, Tsaghkadzor', $node['full_path_name']);
    }

    public function test_hotel_create_uses_location_id_and_derives_legacy_fields(): void
    {
        $admin = $this->admin();
        $company = $this->company();
        $city = $this->location('Tsaghkadzor', Location::TYPE_CITY);

        $offer = $this->makeOffer($company, 'hotel', 'QA Hotel Offer');

        $response = $this->postJson('/api/hotels', [
            'offer_id' => $offer->id,
            'hotel_name' => 'QA Hotel',
            'property_type' => 'hotel',
            'hotel_type' => 'mountain',
            'location_id' => $city->id,
            'meal_type' => 'breakfast',
            'status' => 'draft',
            'availability_status' => 'available',
        ], $this->authHeaders($admin));

        $response->assertStatus(201)
            ->assertJsonPath('data.location_id', $city->id);

        if (Schema::hasColumn('hotels', 'country')) {
            $response->assertJsonPath('data.country', 'Armenia');
        }
        if (Schema::hasColumn('hotels', 'city')) {
            $response->assertJsonPath('data.city', 'Tsaghkadzor');
        }
    }

    public function test_visa_allows_country_only_and_rejects_non_country(): void
    {
        $admin = $this->admin();
        $company = $this->company();
        $headers = $this->authHeaders($admin);
        $country = $this->location('Armenia', Location::TYPE_COUNTRY);
        $region = $this->location('Kotayk', Location::TYPE_REGION);

        $offerBad = $this->makeOffer($company, 'visa', 'QA Visa Bad');
        $this->postJson('/api/visas', [
            'offer_id' => $offerBad->id,
            'visa_type' => 'tourist',
            'location_id' => $region->id,
        ], $headers)->assertStatus(422)->assertJsonValidationErrors(['location_id']);

        $offerGood = $this->makeOffer($company, 'visa', 'QA Visa Good');
        $this->postJson('/api/visas', [
            'offer_id' => $offerGood->id,
            'visa_type' => 'tourist',
            'location_id' => $country->id,
        ], $headers)->assertStatus(201)
            ->assertJsonPath('data.location_id', $country->id);
    }

    public function test_transfer_accepts_two_different_locations_and_rejects_same_location(): void
    {
        $admin = $this->admin();
        $company = $this->company();
        $headers = $this->authHeaders($admin);
        $tsaghkadzor = $this->location('Tsaghkadzor', Location::TYPE_CITY);
        $paris = $this->location('Paris', Location::TYPE_CITY);

        $sameOffer = $this->makeOffer($company, 'transfer', 'QA Transfer Same');
        $samePayload = $this->validTransferPayload($sameOffer->id, $tsaghkadzor->id, $tsaghkadzor->id);
        $this->postJson('/api/transfers', $samePayload, $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destination_location_id']);

        $okOffer = $this->makeOffer($company, 'transfer', 'QA Transfer OK');
        $okPayload = $this->validTransferPayload($okOffer->id, $tsaghkadzor->id, $paris->id);
        $this->postJson('/api/transfers', $okPayload, $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.origin_location_id', $tsaghkadzor->id)
            ->assertJsonPath('data.destination_location_id', $paris->id);
    }

    public function test_scope_for_location_country_includes_descendant_city_products(): void
    {
        $admin = $this->admin();
        $company = $this->company();
        $city = $this->location('Tsaghkadzor', Location::TYPE_CITY);
        $country = $this->location('Armenia', Location::TYPE_COUNTRY);
        $offer = $this->makeOffer($company, 'hotel', 'Scope QA');

        $this->postJson('/api/hotels', [
            'offer_id' => $offer->id,
            'hotel_name' => 'Scope QA Hotel',
            'property_type' => 'hotel',
            'hotel_type' => 'city',
            'location_id' => $city->id,
            'meal_type' => 'breakfast',
            'availability_status' => 'available',
            'status' => 'draft',
        ], $this->authHeaders($admin))->assertStatus(201);

        $hotel = Hotel::query()->latest('id')->firstOrFail();

        $ids = Hotel::query()->forLocation($country->id)->pluck('id')->all();
        $this->assertContains($hotel->id, $ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function validTransferPayload(int $offerId, int $originId, int $destinationId): array
    {
        return [
            'offer_id' => $offerId,
            'transfer_title' => 'QA Transfer',
            'transfer_type' => 'city_transfer',
            'origin_location_id' => $originId,
            'destination_location_id' => $destinationId,
            'pickup_point_type' => 'address',
            'pickup_point_name' => 'Pickup Point',
            'dropoff_point_type' => 'address',
            'dropoff_point_name' => 'Dropoff Point',
            'service_date' => now()->addDay()->toDateString(),
            'pickup_time' => '10:00:00',
            'estimated_duration_minutes' => 90,
            'vehicle_category' => 'sedan',
            'passenger_capacity' => 3,
            'luggage_capacity' => 2,
            'minimum_passengers' => 1,
            'maximum_passengers' => 3,
            'pricing_mode' => 'per_vehicle',
            'base_price' => 50,
            'cancellation_policy_type' => 'non_refundable',
            'availability_status' => 'available',
            'status' => 'draft',
        ];
    }
}

