<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Flight;
use App\Models\Offer;
use App\Models\User;
use App\Services\Offers\OfferNormalizationService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferShowNormalizationApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_offer_show_includes_normalized_offer_for_flight_when_module_loaded(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'API flight',
            'price' => 50,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        Flight::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'flight_code_internal' => 'API-F1',
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

        $res = $this->getJson('/api/offers/'.$offer->id, $this->authHeaders($user));
        $res->assertOk()->assertJsonPath('success', true);

        $norm = $res->json('data.normalized_offer');
        $this->assertIsArray($norm);
        $this->assertSame(OfferNormalizationService::NORMALIZED_KEYS, array_keys($norm));
        $this->assertSame('flight', $norm['module_type']);
        $this->assertSame($offer->id, $norm['offer_id']);
    }

    public function test_offer_index_does_not_include_normalized_offer_without_module_load(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'List flight',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        Flight::query()->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'flight_code_internal' => 'L-F1',
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

        $res = $this->getJson('/api/offers', $this->authHeaders($user));
        $res->assertOk();
        $rows = $res->json('data');
        $this->assertIsArray($rows);
        $hit = collect($rows)->firstWhere('id', $offer->id);
        $this->assertIsArray($hit);
        $this->assertArrayNotHasKey('normalized_offer', $hit);
    }
}
