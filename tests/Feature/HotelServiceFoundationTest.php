<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Hotel;
use App\Models\HotelRoom;
use App\Models\HotelRoomPricing;
use App\Models\Offer;
use App\Services\Hotels\HotelService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HotelServiceFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function makeHotelOffer(Company $company, float $price = 99): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'Stay offer',
            'price' => $price,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validHotelPayload(int $offerId): array
    {
        return [
            'offer_id' => $offerId,
            'location_id' => $this->locationIds()['yerevan_city'],
            'hotel_name' => 'ZULU Test Hotel',
            'property_type' => 'hotel',
            'hotel_type' => 'resort',
            'country' => 'AM',
            'city' => 'Yerevan',
            'meal_type' => 'bed_and_breakfast',
            'rooms' => [
                [
                    'room_type' => 'double',
                    'room_name' => 'Deluxe double',
                    'max_adults' => 2,
                    'max_children' => 1,
                    'max_total_guests' => 3,
                    'pricings' => [
                        [
                            'price' => 120.5,
                            'currency' => 'USD',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_create_hotel_success_for_hotel_offer_with_rooms_and_pricing(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company, 50);
        $service = new HotelService;

        $hotel = $service->create($this->validHotelPayload($offer->id));

        $this->assertInstanceOf(Hotel::class, $hotel);
        $this->assertSame($offer->id, $hotel->offer_id);
        $this->assertSame($company->id, $hotel->company_id);
        $offer->refresh();
        $this->assertEqualsWithDelta(120.5, (float) $offer->price, 0.01, 'offer.price syncs from minimum active room pricing');

        $this->assertCount(1, $hotel->rooms);
        $room = $hotel->rooms->first();
        $this->assertInstanceOf(HotelRoom::class, $room);
        $this->assertSame('double', $room->room_type);
        $this->assertSame(3, $room->max_total_guests);
        $this->assertCount(1, $room->pricings);
        $pricing = $room->pricings->first();
        $this->assertInstanceOf(HotelRoomPricing::class, $pricing);
        $this->assertEqualsWithDelta(120.5, (float) $pricing->price, 0.01);
        $this->assertSame('USD', $pricing->currency);
        $this->assertSame('per_night', $pricing->pricing_mode);
    }

    public function test_create_rejects_non_hotel_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'flight',
            'title' => 'Not a hotel',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $service = new HotelService;

        try {
            $service->create($this->validHotelPayload($offer->id));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('offer_id', $e->errors());
        }
    }

    public function test_create_rejects_duplicate_hotel_per_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $service = new HotelService;
        $payload = $this->validHotelPayload($offer->id);

        $service->create($payload);

        try {
            $service->create($payload);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('offer_id', $e->errors());
        }
    }

    public function test_create_rejects_when_max_total_guests_less_than_max_adults(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $service = new HotelService;

        $payload = $this->validHotelPayload($offer->id);
        $payload['rooms'][0]['max_adults'] = 4;
        $payload['rooms'][0]['max_total_guests'] = 2;

        try {
            $service->create($payload);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rooms.0.max_total_guests', $e->errors());
        }
    }

    public function test_delete_removes_hotel_rooms_and_pricings(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = $this->makeHotelOffer($company);
        $service = new HotelService;
        $hotel = $service->create($this->validHotelPayload($offer->id));
        $roomId = $hotel->rooms->first()->id;
        $pricingId = $hotel->rooms->first()->pricings->first()->id;

        $service->delete($hotel);

        $this->assertDatabaseMissing('hotels', ['id' => $hotel->id]);
        $this->assertDatabaseMissing('hotel_rooms', ['id' => $roomId]);
        $this->assertDatabaseMissing('hotel_room_pricings', ['id' => $pricingId]);
    }
}
