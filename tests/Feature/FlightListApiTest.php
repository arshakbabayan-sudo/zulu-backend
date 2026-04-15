<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Offer;
use App\Models\User;
use App\Services\Flights\FlightService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightListApiTest extends TestCase
{
    use RefreshDatabase;

    private FlightService $flights;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flights = app(FlightService::class);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();

        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $offerId, string $code): array
    {
        return [
            'offer_id' => $offerId,
            'location_id' => $this->locationIds()['yerevan_city'],
            'flight_code_internal' => $code,
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
            'adult_price' => 200,
            'child_price' => 0,
            'infant_price' => 0,
            'hand_baggage_included' => true,
            'checked_baggage_included' => true,
            'reservation_allowed' => true,
            'online_checkin_allowed' => true,
            'airport_checkin_allowed' => true,
            'cancellation_policy_type' => 'non_refundable',
            'change_policy_type' => 'not_allowed',
            'seat_map_available' => false,
            'extra_baggage_allowed' => false,
            'is_package_eligible' => true,
            'status' => 'active',
        ];
    }

    private function seedFlight(Company $company, array $overrides = [], string $offerTitle = 'O'): Offer
    {
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => $offerTitle,
            'price' => 1,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        $this->flights->create(array_merge($this->basePayload($offer->id, 'L-'.$offer->id), $overrides));

        return $offer->fresh(['flight']);
    }

    public function test_get_flights_requires_authentication(): void
    {
        $this->getJson('/api/flights')->assertUnauthorized();
    }

    public function test_get_flights_filters_by_departure_city(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->seedFlight($company, [], 'A');
        $this->seedFlight($company, [
            'departure_city' => 'Paris',
            'departure_country' => 'FR',
            'flight_code_internal' => 'L-PAR',
        ], 'B');

        $res = $this->getJson('/api/flights?departure_city=Yerevan', $this->authHeaders());
        $res->assertOk()->assertJsonPath('success', true);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Yerevan', $data[0]['departure_city']);
    }

    public function test_get_flights_filters_by_offer_price_range(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $o1 = $this->seedFlight($company, ['flight_code_internal' => 'P-A', 'adult_price' => 50], 'P1');
        $o1->update(['price' => 600]);
        $o2 = $this->seedFlight($company, [
            'flight_code_internal' => 'P-B',
            'adult_price' => 50,
            'departure_city' => 'Paris',
            'departure_country' => 'FR',
        ], 'P2');
        $o2->update(['price' => 120]);

        $res = $this->getJson('/api/flights?min_price=500&max_price=700', $this->authHeaders());
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame(600, (int) $res->json('data.0.offer.price'));
    }

    public function test_get_flights_only_active_flights(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->seedFlight($company, ['status' => 'active', 'flight_code_internal' => 'ACT'], 'A');
        $this->seedFlight($company, ['status' => 'draft', 'flight_code_internal' => 'DRF'], 'B');

        $res = $this->getJson('/api/flights?only_active_flights=1', $this->authHeaders());
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('active', $res->json('data.0.status'));
    }

    public function test_get_flights_returns_resource_shape_with_offer_and_company(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->seedFlight($company, [], 'S');

        $res = $this->getJson('/api/flights', $this->authHeaders());
        $res->assertOk();
        $row = $res->json('data.0');
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('departure_city', $row);
        $this->assertArrayHasKey('cabin_class', $row);
        $this->assertArrayHasKey('offer', $row);
        $this->assertArrayHasKey('price', $row['offer']);
        $this->assertArrayHasKey('currency', $row['offer']);
        $this->assertArrayHasKey('company', $row);
        $this->assertArrayHasKey('id', $row['company']);
        $this->assertArrayNotHasKey('flight', $row['offer']);
    }

    public function test_get_flight_show_includes_offer_and_company(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $o = $this->seedFlight($company, [], 'D');
        $id = $o->flight->id;

        $res = $this->getJson('/api/flights/'.$id, $this->authHeaders());
        $res->assertOk()->assertJsonPath('success', true);
        $this->assertIsArray($res->json('data.offer'));
        $this->assertIsArray($res->json('data.company'));
        $this->assertSame($company->id, $res->json('data.company.id'));
    }

    public function test_get_flights_pagination_meta_when_page_present(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        for ($i = 0; $i < 3; $i++) {
            $this->seedFlight($company, [
                'flight_code_internal' => 'PG-'.$i,
                'departure_at' => '2026-10-0'.(1 + $i).' 10:00:00',
                'arrival_at' => '2026-10-0'.(1 + $i).' 14:00:00',
            ], 'Pg'.$i);
        }

        $res = $this->getJson('/api/flights?page=1&per_page=2', $this->authHeaders());
        $res->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
        $this->assertCount(2, $res->json('data'));
    }

    public function test_get_flights_combines_tenant_scope_with_filters(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->seedFlight($company, ['cabin_class' => 'economy', 'flight_code_internal' => 'E1'], 'E1');
        $this->seedFlight($company, ['cabin_class' => 'business', 'flight_code_internal' => 'B1'], 'B1');

        $res = $this->getJson('/api/flights?cabin_class=economy', $this->authHeaders());
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('economy', $res->json('data.0.cabin_class'));
    }

    public function test_get_flights_honors_admin_visibility_flag(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->seedFlight($company, [
            'flight_code_internal' => 'VISIBLE-ADMIN',
            'appears_in_admin' => true,
        ], 'VA');
        $this->seedFlight($company, [
            'flight_code_internal' => 'HIDDEN-ADMIN',
            'appears_in_admin' => false,
            'departure_city' => 'Paris',
            'departure_country' => 'FR',
        ], 'HA');

        $res = $this->getJson('/api/flights', $this->authHeaders());
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('VISIBLE-ADMIN', $res->json('data.0.flight_code_internal'));
    }
}
