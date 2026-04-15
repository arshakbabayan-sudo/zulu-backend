<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Offer;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightWriteApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * @return array<string, mixed>
     */
    private function validFlightPayload(int $offerId): array
    {
        return [
            'offer_id' => $offerId,
            'location_id' => $this->locationIds()['yerevan_city'],
            'flight_code_internal' => 'W-API-1',
            'service_type' => 'scheduled',
            'departure_country' => 'AM',
            'departure_city' => 'Yerevan',
            'departure_airport' => 'EVN',
            'arrival_country' => 'EG',
            'arrival_city' => 'Sharm',
            'arrival_airport' => 'SSH',
            'departure_at' => '2026-09-01 10:00:00',
            'arrival_at' => '2026-09-01 15:00:00',
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
            'adult_price' => 500,
            'child_price' => 150,
            'infant_price' => 20,
            'hand_baggage_included' => true,
            'checked_baggage_included' => true,
            'reservation_allowed' => true,
            'online_checkin_allowed' => true,
            'airport_checkin_allowed' => true,
            'cancellation_policy_type' => 'non_refundable',
            'change_policy_type' => 'not_allowed',
            'seat_map_available' => false,
            'extra_baggage_allowed' => false,
            'appears_in_web' => true,
            'appears_in_admin' => true,
            'appears_in_zulu_admin' => true,
            'is_package_eligible' => true,
            'status' => 'draft',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validCabinPayload(string $cabinClass, float $adultPrice): array
    {
        return [
            'cabin_class' => $cabinClass,
            'seat_capacity_total' => 60,
            'seat_capacity_available' => 20,
            'adult_price' => $adultPrice,
            'child_price' => 100,
            'infant_price' => 15,
            'hand_baggage_included' => true,
            'hand_baggage_weight' => null,
            'checked_baggage_included' => true,
            'checked_baggage_weight' => null,
            'extra_baggage_allowed' => false,
            'baggage_notes' => null,
            'fare_family' => null,
            'seat_map_available' => false,
            'seat_selection_policy' => null,
        ];
    }

    public function test_add_cabin_sets_offer_price_to_minimum_cabin_adult_price(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Cabin sync',
            'price' => 1,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $headers = $this->authHeaders($user);
        $payload = $this->validFlightPayload($offer->id);
        $payload['flight_code_internal'] = 'CAB-SYNC-1';
        $payload['adult_price'] = 500;

        $this->postJson('/api/flights', $payload, $headers)->assertStatus(201);
        $offer->refresh();
        $this->assertEqualsWithDelta(500.0, (float) $offer->price, 0.01);

        $flightId = (int) Offer::query()->findOrFail($offer->id)->flight->id;

        $this->postJson('/api/flights/'.$flightId.'/cabins', $this->validCabinPayload('economy', 320), $headers)
            ->assertStatus(201);

        $offer->refresh();
        $this->assertEqualsWithDelta(320.0, (float) $offer->price, 0.01);

        $this->postJson('/api/flights/'.$flightId.'/cabins', $this->validCabinPayload('business', 900), $headers)
            ->assertStatus(201);

        $offer->refresh();
        $this->assertEqualsWithDelta(320.0, (float) $offer->price, 0.01);
    }

    public function test_flight_adult_price_patch_ignored_for_offer_when_cabins_exist(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Ignore flight adult',
            'price' => 1,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $headers = $this->authHeaders($user);
        $payload = $this->validFlightPayload($offer->id);
        $payload['flight_code_internal'] = 'IGN-ADULT-1';
        $payload['adult_price'] = 400;

        $this->postJson('/api/flights', $payload, $headers)->assertStatus(201);
        $flightId = (int) Offer::query()->findOrFail($offer->id)->flight->id;

        $this->postJson('/api/flights/'.$flightId.'/cabins', $this->validCabinPayload('economy', 275), $headers)
            ->assertStatus(201);

        $offer->refresh();
        $this->assertEqualsWithDelta(275.0, (float) $offer->price, 0.01);

        $this->patchJson('/api/flights/'.$flightId, ['adult_price' => 999], $headers)->assertOk();

        $offer->refresh();
        $this->assertEqualsWithDelta(275.0, (float) $offer->price, 0.01);
    }

    public function test_cabin_adult_price_update_recomputes_offer_minimum(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Recompute min',
            'price' => 1,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $headers = $this->authHeaders($user);
        $payload = $this->validFlightPayload($offer->id);
        $payload['flight_code_internal'] = 'REMIN-1';

        $this->postJson('/api/flights', $payload, $headers)->assertStatus(201);
        $flightId = (int) Offer::query()->findOrFail($offer->id)->flight->id;

        $eco = $this->postJson('/api/flights/'.$flightId.'/cabins', $this->validCabinPayload('economy', 200), $headers)
            ->assertStatus(201)
            ->json('data.id');
        $this->postJson('/api/flights/'.$flightId.'/cabins', $this->validCabinPayload('business', 450), $headers)
            ->assertStatus(201);

        $offer->refresh();
        $this->assertEqualsWithDelta(200.0, (float) $offer->price, 0.01);

        $this->patchJson('/api/flights/'.$flightId.'/cabins/'.$eco, ['adult_price' => 120], $headers)->assertOk();

        $offer->refresh();
        $this->assertEqualsWithDelta(120.0, (float) $offer->price, 0.01);
    }

    public function test_delete_last_cabin_falls_back_to_flight_adult_price(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Fallback',
            'price' => 1,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $headers = $this->authHeaders($user);
        $payload = $this->validFlightPayload($offer->id);
        $payload['flight_code_internal'] = 'FB-1';
        $payload['adult_price'] = 600;

        $this->postJson('/api/flights', $payload, $headers)->assertStatus(201);
        $flightId = (int) Offer::query()->findOrFail($offer->id)->flight->id;

        $cabinId = $this->postJson('/api/flights/'.$flightId.'/cabins', $this->validCabinPayload('economy', 220), $headers)
            ->assertStatus(201)
            ->json('data.id');

        $offer->refresh();
        $this->assertEqualsWithDelta(220.0, (float) $offer->price, 0.01);

        $this->deleteJson('/api/flights/'.$flightId.'/cabins/'.$cabinId, [], $headers)->assertOk();

        $offer->refresh();
        $this->assertEqualsWithDelta(600.0, (float) $offer->price, 0.01);
    }
}
