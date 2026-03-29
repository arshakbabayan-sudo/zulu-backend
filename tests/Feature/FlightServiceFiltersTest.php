<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Flight;
use App\Models\Offer;
use App\Services\Flights\FlightService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightServiceFiltersTest extends TestCase
{
    use RefreshDatabase;

    private FlightService $flights;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flights = app(FlightService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseFlightPayload(int $offerId, string $codeSuffix): array
    {
        return [
            'offer_id' => $offerId,
            'flight_code_internal' => 'FLT-FIL-'.$codeSuffix,
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
            'is_package_eligible' => true,
            'status' => 'active',
        ];
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     */
    private function createFlightOffer(Company $company, array $payloadOverrides = [], string $offerTitle = 'O'): Offer
    {
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => $offerTitle,
            'price' => 1,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $payload = array_merge($this->baseFlightPayload($offer->id, (string) $offer->id), $payloadOverrides);
        $this->flights->create($payload);

        return $offer->fresh(['flight']);
    }

    public function test_filtered_query_empty_and_unknown_keys_are_safe(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->createFlightOffer($company, [], 'A');

        $all = Flight::query()->count();
        $this->assertSame($all, $this->flights->filteredQuery([])->count());
        $this->assertSame($all, $this->flights->filteredQuery(['not_a_real_filter' => 'x'])->count());
    }

    public function test_filter_departure_city_and_arrival_city(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->createFlightOffer($company, ['departure_city' => 'Yerevan', 'arrival_city' => 'Sharm'], '1');
        $this->createFlightOffer($company, [
            'departure_city' => 'Paris',
            'departure_country' => 'FR',
            'arrival_city' => 'Sharm',
            'flight_code_internal' => 'FLT-FIL-PAR',
        ], '2');

        $idsY = $this->flights->filteredQuery(['departure_city' => 'Yerevan'])->pluck('id')->sort()->values()->all();
        $this->assertCount(1, $idsY);

        $idsA = $this->flights->filteredQuery(['arrival_city' => 'Sharm'])->pluck('id')->sort()->values()->all();
        $this->assertCount(2, $idsA);
    }

    public function test_filter_cabin_class_and_connection_type(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->createFlightOffer($company, ['cabin_class' => 'economy', 'connection_type' => 'direct'], 'E');
        $this->createFlightOffer($company, [
            'cabin_class' => 'business',
            'connection_type' => 'connected',
            'stops_count' => 2,
            'flight_code_internal' => 'FLT-FIL-BIZ',
        ], 'B');

        $eco = $this->flights->filteredQuery(['cabin_class' => 'economy'])->count();
        $this->assertSame(1, $eco);

        $conn = $this->flights->filteredQuery(['connection_type' => 'connected'])->count();
        $this->assertSame(1, $conn);
    }

    public function test_filter_status_and_package_eligibility(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->createFlightOffer($company, ['status' => 'active', 'is_package_eligible' => true], 'A');
        $this->createFlightOffer($company, [
            'status' => 'draft',
            'is_package_eligible' => false,
            'flight_code_internal' => 'FLT-FIL-D',
        ], 'D');

        $this->assertSame(1, $this->flights->filteredQuery(['status' => 'draft'])->count());
        $this->assertSame(1, $this->flights->filteredQuery(['is_package_eligible' => '1'])->count());
        $this->assertSame(1, $this->flights->filteredQuery(['is_package_eligible' => false])->count());
    }

    public function test_filter_price_range_uses_offer_price_not_adult_price(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $o1 = $this->createFlightOffer($company, ['adult_price' => 100, 'flight_code_internal' => 'P1'], 'P1');
        $o1->update(['price' => 500]);

        $o2 = $this->createFlightOffer($company, [
            'adult_price' => 900,
            'flight_code_internal' => 'P2',
            'departure_city' => 'Paris',
            'departure_country' => 'FR',
        ], 'P2');
        $o2->update(['price' => 150]);

        $inRange = $this->flights->filteredQuery(['min_price' => 400, 'max_price' => 600])->pluck('id')->all();
        $this->assertCount(1, $inRange);
        $this->assertSame($o1->flight->id, $inRange[0]);
    }

    public function test_filter_departure_date_range(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->createFlightOffer($company, [
            'departure_at' => '2026-07-10 08:00:00',
            'arrival_at' => '2026-07-10 12:00:00',
            'flight_code_internal' => 'D1',
        ], 'D1');
        $this->createFlightOffer($company, [
            'departure_at' => '2026-12-01 08:00:00',
            'arrival_at' => '2026-12-01 12:00:00',
            'flight_code_internal' => 'D2',
        ], 'D2');

        $q = $this->flights->filteredQuery([
            'departure_at_from' => '2026-08-01',
            'departure_at_to' => '2026-11-30',
        ]);
        $this->assertSame(0, $q->count());

        $q2 = $this->flights->filteredQuery([
            'departure_at_from' => '2026-11-01',
            'departure_at_to' => '2026-12-31',
        ]);
        $this->assertSame(1, $q2->count());
    }

    public function test_combine_multiple_filters(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->createFlightOffer($company, [
            'departure_city' => 'Yerevan',
            'cabin_class' => 'economy',
            'status' => 'active',
        ], 'M1');
        $this->createFlightOffer($company, [
            'departure_city' => 'Yerevan',
            'cabin_class' => 'business',
            'status' => 'active',
            'flight_code_internal' => 'M2',
        ], 'M2');

        $n = $this->flights->filteredQuery([
            'departure_city' => 'Yerevan',
            'cabin_class' => 'economy',
            'status' => 'active',
        ])->count();
        $this->assertSame(1, $n);
    }

    public function test_only_active_flights_and_only_published_offers_flags(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();

        $pub = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Pub',
            'price' => 50,
            'currency' => 'USD',
            'status' => 'published',
        ]);
        $this->flights->create(array_merge($this->baseFlightPayload($pub->id, 'pub'), [
            'status' => 'active',
            'flight_code_internal' => 'FLT-PUB',
        ]));

        $draftOffer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Dr',
            'price' => 50,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        $this->flights->create(array_merge($this->baseFlightPayload($draftOffer->id, 'dr'), [
            'status' => 'active',
            'flight_code_internal' => 'FLT-DR',
        ]));

        $this->assertSame(1, $this->flights->filteredQuery(['only_published_offers' => true])->count());
        $this->assertSame(2, $this->flights->filteredQuery(['only_active_flights' => true])->count());

        $draftOffer->flight->update(['status' => 'draft']);
        $this->assertSame(1, $this->flights->filteredQuery(['only_active_flights' => true])->count());
    }

    public function test_stops_count_max(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->createFlightOffer($company, ['stops_count' => 0, 'connection_type' => 'direct'], 'S0');
        $this->createFlightOffer($company, [
            'stops_count' => 3,
            'connection_type' => 'connected',
            'flight_code_internal' => 'S3',
        ], 'S3');

        $this->assertSame(1, $this->flights->filteredQuery(['stops_count_max' => 1])->count());
    }

    public function test_apply_filters_on_existing_query(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $this->createFlightOffer($company, ['company_id' => $company->id], 'C1');

        $q = Flight::query()->where('flight_code_internal', 'like', 'FLT-FIL-%');
        $this->flights->applyFilters($q, ['status' => 'active']);
        $this->assertGreaterThanOrEqual(1, $q->count());
    }
}
