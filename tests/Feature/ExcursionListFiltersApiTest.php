<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\User;
use App\Services\Excursions\ExcursionService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExcursionListFiltersApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function makeExcursionOffer(Company $company, string $title = 'Tour', float $price = 50): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'excursion',
            'title' => $title,
            'price' => $price,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }

    public function test_filter_price_range_and_schedule_and_booking_links(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new ExcursionService;

        $offerCheap = $this->makeExcursionOffer($company, 'Cheap', 40);
        $e1 = $service->create([
            'offer_id' => $offerCheap->id,
            'company_id' => $company->id,
            'location' => 'Square',
            'duration' => '2h',
            'group_size' => 10,
            'country' => 'AM',
            'city' => 'Yerevan',
            'category' => 'walking',
            'starts_at' => '2026-06-10T09:00:00Z',
            'ends_at' => '2026-06-10T12:00:00Z',
            'status' => 'published',
        ]);

        $offerPricey = $this->makeExcursionOffer($company, 'Pricey', 200);
        $e2 = $service->create([
            'offer_id' => $offerPricey->id,
            'company_id' => $company->id,
            'location' => 'Lake',
            'duration' => '1d',
            'group_size' => 5,
            'country' => 'GE',
            'city' => 'Tbilisi',
            'category' => 'hike',
            'starts_at' => '2026-08-01T09:00:00Z',
            'ends_at' => '2026-08-05T18:00:00Z',
            'status' => 'draft',
        ]);

        $headers = $this->authHeaders($admin);

        $byPrice = $this->getJson('/api/excursions?price_min=30&price_max=100', $headers);
        $byPrice->assertOk();
        $ids = collect($byPrice->json('data'))->pluck('id')->all();
        $this->assertContains($e1->id, $ids);
        $this->assertNotContains($e2->id, $ids);

        $byDay = $this->getJson('/api/excursions?date=2026-06-10', $headers);
        $byDay->assertOk();
        $this->assertContains($e1->id, collect($byDay->json('data'))->pluck('id')->all());
        $this->assertNotContains($e2->id, collect($byDay->json('data'))->pluck('id')->all());

        $byRange = $this->getJson('/api/excursions?date_from=2026-08-01&date_to=2026-08-03', $headers);
        $byRange->assertOk();
        $this->assertContains($e2->id, collect($byRange->json('data'))->pluck('id')->all());

        $booking = Booking::query()->create([
            'user_id' => $admin->id,
            'company_id' => $company->id,
            'status' => Booking::STATUS_CONFIRMED,
            'total_price' => 40,
        ]);
        BookingItem::query()->create([
            'booking_id' => $booking->id,
            'offer_id' => $offerCheap->id,
            'price' => 40,
        ]);
        $invoice = Invoice::query()->create([
            'booking_id' => $booking->id,
            'unique_booking_reference' => 'EXC-ORDER-99',
            'total_amount' => 40,
            'currency' => 'USD',
            'status' => Invoice::STATUS_ISSUED,
            'issuing_date' => '2026-04-01',
        ]);

        $byEmail = $this->getJson('/api/excursions?user_email=admin@zulu', $headers);
        $byEmail->assertOk();
        $this->assertContains($e1->id, collect($byEmail->json('data'))->pluck('id')->all());

        $byInv = $this->getJson('/api/excursions?invoice_id='.$invoice->id, $headers);
        $byInv->assertOk();
        $this->assertContains($e1->id, collect($byInv->json('data'))->pluck('id')->all());

        $byOrder = $this->getJson('/api/excursions?order_number=EXC-ORDER', $headers);
        $byOrder->assertOk();
        $this->assertContains($e1->id, collect($byOrder->json('data'))->pluck('id')->all());
    }

    public function test_min_price_alias_maps_to_price_min(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $admin = User::query()->where('email', 'admin@zulu.local')->firstOrFail();
        $company = Company::query()->firstOrFail();
        $service = new ExcursionService;
        $offer = $this->makeExcursionOffer($company, 'X', 75);
        $ex = $service->create([
            'offer_id' => $offer->id,
            'company_id' => $company->id,
            'location' => 'A',
            'duration' => '1h',
            'group_size' => 4,
        ]);

        $headers = $this->authHeaders($admin);
        $res = $this->getJson('/api/excursions?min_price=70&max_price=80', $headers);
        $res->assertOk();
        $this->assertContains($ex->id, collect($res->json('data'))->pluck('id')->all());
    }
}
