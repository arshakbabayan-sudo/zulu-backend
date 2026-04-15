<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Offer;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightAdminCrudTest extends TestCase
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
            'flight_code_internal' => 'T-CRUD-1',
            'service_type' => 'scheduled',
            'departure_country' => 'AM',
            'departure_city' => 'Yerevan',
            'departure_airport' => 'EVN',
            'arrival_country' => 'EG',
            'arrival_city' => 'Sharm',
            'arrival_airport' => 'SSH',
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

    public function test_flight_crud_duplicate_guard_and_relations(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Flight offer',
            'price' => 100,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $headers = $this->authHeaders($user);
        $payload = $this->validFlightPayload($offer->id);

        $create = $this->postJson('/api/flights', $payload, $headers);
        $create->assertStatus(201)->assertJsonPath('success', true);
        $id = (int) $create->json('data.id');
        $this->assertGreaterThan(0, $id);
        $offer->refresh();
        $this->assertEqualsWithDelta(199.0, (float) $offer->price, 0.01);

        $this->postJson('/api/flights', $payload, $headers)->assertStatus(422);

        $this->patchJson('/api/flights/'.$id, ['status' => 'active'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->getJson('/api/flights/'.$id, $headers)
            ->assertOk()
            ->assertJsonPath('data.offer.id', $offer->id)
            ->assertJsonPath('data.company.id', $company->id);

        $this->deleteJson('/api/flights/'.$id, [], $headers)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_flight_create_rejects_non_flight_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'Hotel offer',
            'price' => 50,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $this->postJson('/api/flights', $this->validFlightPayload($offer->id), $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offer_id']);
    }

    public function test_flight_adult_price_syncs_offer_price_on_update_child_does_not(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Sync offer',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $headers = $this->authHeaders($user);
        $payload = $this->validFlightPayload($offer->id);
        $payload['flight_code_internal'] = 'SYNC-1';
        $payload['adult_price'] = 300;
        $payload['child_price'] = 100;

        $this->postJson('/api/flights', $payload, $headers)->assertStatus(201);
        $offer->refresh();
        $this->assertEqualsWithDelta(300.0, (float) $offer->price, 0.01);

        $id = (int) Offer::query()->findOrFail($offer->id)->flight->id;

        $this->patchJson('/api/flights/'.$id, ['child_price' => 88], $headers)->assertOk();
        $offer->refresh();
        $this->assertEqualsWithDelta(300.0, (float) $offer->price, 0.01);

        $this->patchJson('/api/flights/'.$id, ['adult_price' => 400], $headers)->assertOk();
        $offer->refresh();
        $this->assertEqualsWithDelta(400.0, (float) $offer->price, 0.01);
    }

    public function test_flight_create_rejects_non_positive_adult_price(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Bad price',
            'price' => 1,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $payload = $this->validFlightPayload($offer->id);
        $payload['flight_code_internal'] = 'BAD-P';
        $payload['adult_price'] = 0;

        $this->postJson('/api/flights', $payload, $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['adult_price']);
    }

    public function test_flight_active_requires_positive_adult_price(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Zero adult',
            'price' => 50,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $headers = $this->authHeaders($user);
        $payload = $this->validFlightPayload($offer->id);
        $payload['flight_code_internal'] = 'ZERO-A';
        $payload['adult_price'] = 50;

        $this->postJson('/api/flights', $payload, $headers)->assertStatus(201);
        $id = (int) Offer::query()->findOrFail($offer->id)->flight->id;

        $this->patchJson('/api/flights/'.$id, ['adult_price' => 0], $headers)->assertOk();

        $this->patchJson('/api/flights/'.$id, ['status' => 'active'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['adult_price']);
    }

    public function test_flight_patch_rejects_negative_child_price(): void
    {
        $this->seed(RbacBootstrapSeeder::class);

        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();

        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Neg child',
            'price' => 1,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $headers = $this->authHeaders($user);
        $payload = $this->validFlightPayload($offer->id);
        $payload['flight_code_internal'] = 'NEG-C';

        $this->postJson('/api/flights', $payload, $headers)->assertStatus(201);
        $id = (int) Offer::query()->findOrFail($offer->id)->flight->id;

        $this->patchJson('/api/flights/'.$id, ['child_price' => -5], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['child_price']);
    }
}
