<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HotelRoomPricing;
use App\Models\Offer;
use App\Models\User;
use App\Services\Hotels\HotelService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HotelListApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function makeHotelOffer(Company $company, string $title = 'Stay'): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => $title,
            'price' => 100,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function hotelPayload(int $offerId, string $city = 'Yerevan', string $hotelName = 'Test Hotel', ?int $locationId = null): array
    {
        return [
            'offer_id' => $offerId,
            'location_id' => $locationId ?? $this->locationIds()['yerevan_city'],
            'hotel_name' => $hotelName,
            'property_type' => 'hotel',
            'hotel_type' => 'resort',
            'country' => 'AM',
            'city' => $city,
            'meal_type' => 'bed_and_breakfast',
            'status' => 'draft',
            'availability_status' => 'available',
            'is_package_eligible' => false,
            'rooms' => [
                [
                    'room_type' => 'double',
                    'room_name' => 'Standard',
                    'max_adults' => 2,
                    'max_children' => 0,
                    'max_total_guests' => 2,
                    'pricings' => [
                        ['price' => 100, 'currency' => 'USD'],
                    ],
                ],
            ],
        ];
    }

    public function test_hotels_index_requires_authentication(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $this->getJson('/api/hotels')->assertUnauthorized();
    }

    public function test_hotels_index_returns_paginated_data_when_page_present(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new HotelService;
        $service->create($this->hotelPayload($this->makeHotelOffer($company)->id));

        $res = $this->getJson('/api/hotels?page=1', $this->authHeaders($user));
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'last_page', 'total', 'per_page'],
            ]);
        $this->assertIsArray($res->json('data'));
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
    }

    public function test_hotels_show_returns_detail_with_rooms_and_pricings(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $hotel = (new HotelService)->create($this->hotelPayload($this->makeHotelOffer($company)->id));
        $headers = $this->authHeaders($user);

        $res = $this->getJson('/api/hotels/'.$hotel->id, $headers);
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.offer.type', 'hotel')
            ->assertJsonPath('data.rooms.0.pricings.0.currency', 'USD');

        $this->assertArrayHasKey('free_wifi', $res->json('data'));
        $this->assertArrayHasKey('cancellation_policy_type', $res->json('data'));
    }

    public function test_hotels_index_excludes_rows_when_parent_offer_is_not_hotel_type(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $hotel = (new HotelService)->create($this->hotelPayload($offer->id));

        DB::table('offers')->where('id', $offer->id)->update(['type' => 'flight']);

        $res = $this->getJson('/api/hotels', $this->authHeaders($user));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($hotel->id, $ids);
    }

    public function test_list_starting_price_is_minimum_active_room_pricing(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $payload = $this->hotelPayload($offer->id);
        $payload['rooms'][0]['pricings'] = [
            ['price' => 200, 'currency' => 'USD'],
            ['price' => 49.99, 'currency' => 'USD'],
        ];
        $hotel = (new HotelService)->create($payload);

        $res = $this->getJson('/api/hotels', $this->authHeaders($user));
        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $hotel->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(49.99, (float) $row['starting_price'], 0.01);
        $this->assertSame('USD', $row['currency']);
    }

    public function test_starting_price_ignores_inactive_pricing_rows(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $hotel = (new HotelService)->create($this->hotelPayload($this->makeHotelOffer($company)->id));
        $room = $hotel->rooms->first();
        HotelRoomPricing::query()->create([
            'hotel_room_id' => $room->id,
            'price' => 10,
            'currency' => 'USD',
            'pricing_mode' => 'per_night',
            'status' => 'inactive',
        ]);

        $res = $this->getJson('/api/hotels', $this->authHeaders($user));
        $row = collect($res->json('data'))->firstWhere('id', $hotel->id);
        $this->assertEqualsWithDelta(100.0, (float) $row['starting_price'], 0.01);
    }

    public function test_filter_company_id(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $c1 = Company::query()->firstOrFail();
        $c2 = Company::query()->create([
            'name' => 'Second Agency',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $service = new HotelService;
        $h1 = $service->create($this->hotelPayload($this->makeHotelOffer($c1, 'A')->id, 'CityA', 'H1'));
        $h2 = $service->create($this->hotelPayload($this->makeHotelOffer($c2, 'B')->id, 'CityB', 'H2'));

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/hotels?company_id='.$c1->id, $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($h1->id, $ids);
        $this->assertNotContains($h2->id, $ids);
    }

    public function test_filter_status(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new HotelService;
        $payloadActive = $this->hotelPayload($this->makeHotelOffer($company, 'A')->id, 'X', 'HA');
        $payloadActive['status'] = 'active';
        $ha = $service->create($payloadActive);
        $payloadDraft = $this->hotelPayload($this->makeHotelOffer($company, 'B')->id, 'Y', 'HB');
        $payloadDraft['status'] = 'draft';
        $hb = $service->create($payloadDraft);

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/hotels?status=draft', $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($hb->id, $ids);
        $this->assertNotContains($ha->id, $ids);
    }

    public function test_filter_availability_status(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new HotelService;
        $p1 = $this->hotelPayload($this->makeHotelOffer($company, 'A')->id, 'X', 'H1');
        $p1['availability_status'] = 'available';
        $h1 = $service->create($p1);
        $p2 = $this->hotelPayload($this->makeHotelOffer($company, 'B')->id, 'Y', 'H2');
        $p2['availability_status'] = 'sold_out';
        $h2 = $service->create($p2);

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/hotels?availability_status=sold_out', $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($h2->id, $ids);
        $this->assertNotContains($h1->id, $ids);
    }

    public function test_filter_location_id_city_scope(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new HotelService;
        $h1 = $service->create($this->hotelPayload(
            $this->makeHotelOffer($company, 'A')->id,
            'Gyumri',
            'H1',
            $this->locationIds()['gyumri_city']
        ));
        $h2 = $service->create($this->hotelPayload(
            $this->makeHotelOffer($company, 'B')->id,
            'Tbilisi',
            'H2',
            $this->locationIds()['tbilisi_city']
        ));

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/hotels?location_id='.$this->locationIds()['gyumri_city'], $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($h1->id, $ids);
        $this->assertNotContains($h2->id, $ids);
    }

    public function test_filter_is_package_eligible(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new HotelService;
        $p1 = $this->hotelPayload($this->makeHotelOffer($company, 'A')->id, 'X', 'H1');
        $p1['is_package_eligible'] = true;
        $h1 = $service->create($p1);
        $p2 = $this->hotelPayload($this->makeHotelOffer($company, 'B')->id, 'Y', 'H2');
        $p2['is_package_eligible'] = false;
        $h2 = $service->create($p2);

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/hotels?is_package_eligible=1', $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($h1->id, $ids);
        $this->assertNotContains($h2->id, $ids);
    }

    public function test_filter_location_id_country_scope(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new HotelService;
        $p1 = $this->hotelPayload($this->makeHotelOffer($company, 'A')->id, 'X', 'H1');
        $p1['location_id'] = $this->locationIds()['yerevan_city'];
        $h1 = $service->create($p1);
        $p2 = $this->hotelPayload($this->makeHotelOffer($company, 'B')->id, 'Y', 'H2');
        $p2['location_id'] = $this->locationIds()['tbilisi_city'];
        $h2 = $service->create($p2);

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/hotels?location_id='.$this->locationIds()['am_country'], $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($h1->id, $ids);
        $this->assertNotContains($h2->id, $ids);
    }

    public function test_filter_free_cancellation(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new HotelService;
        $p1 = $this->hotelPayload($this->makeHotelOffer($company, 'A')->id, 'X', 'H1');
        $p1['free_cancellation'] = true;
        $h1 = $service->create($p1);
        $p2 = $this->hotelPayload($this->makeHotelOffer($company, 'B')->id, 'Y', 'H2');
        $p2['free_cancellation'] = false;
        $h2 = $service->create($p2);

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/hotels?free_cancellation=1', $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($h1->id, $ids);
        $this->assertNotContains($h2->id, $ids);
    }

    public function test_filter_starting_price_range(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new HotelService;
        $p1 = $this->hotelPayload($this->makeHotelOffer($company, 'A')->id, 'X', 'H1');
        $p1['rooms'][0]['pricings'][0]['price'] = 50;
        $h1 = $service->create($p1);
        $p2 = $this->hotelPayload($this->makeHotelOffer($company, 'B')->id, 'Y', 'H2');
        $p2['rooms'][0]['pricings'][0]['price'] = 200;
        $h2 = $service->create($p2);

        $all = $service->listForCompanies([$company->id], []);
        $this->assertGreaterThanOrEqual(2, $all->count(), 'unfiltered list should include both hotels');

        $minPriceH1 = DB::table('hotel_room_pricings as hrp')
            ->join('hotel_rooms as hr', 'hr.id', '=', 'hrp.hotel_room_id')
            ->where('hr.hotel_id', $h1->id)
            ->where('hrp.status', 'active')
            ->min('hrp.price');
        $this->assertEqualsWithDelta(50.0, (float) $minPriceH1, 0.01, 'h1 min active room price should be 50');

        $listed = $service->listForCompanies([$company->id], [
            'starting_price_min' => 40,
            'starting_price_max' => 60,
        ]);
        $this->assertTrue(
            $listed->pluck('id')->contains($h1->id),
            'expected h1 in list; count='.$listed->count().' ids='.$listed->pluck('id')->implode(',')
        );
        $this->assertFalse($listed->pluck('id')->contains($h2->id));

        $headers = $this->authHeaders($user);
        $res = $this->getJson('/api/hotels?page=1&starting_price_min=40&starting_price_max=60', $headers);
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($h1->id, $ids);
        $this->assertNotContains($h2->id, $ids);
    }

    public function test_hotels_show_returns_404_when_not_found_or_out_of_scope(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $user = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $headers = $this->authHeaders($user);

        $this->getJson('/api/hotels/999999', $headers)
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
