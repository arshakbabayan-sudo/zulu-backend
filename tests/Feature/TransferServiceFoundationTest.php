<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Offer;
use App\Models\Transfer;
use App\Services\Transfers\TransferService;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransferServiceFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTransferOffer(Company $company, float $price = 75): Offer
    {
        return Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'transfer',
            'title' => 'Airport run',
            'price' => $price,
            'currency' => 'USD',
            'status' => 'draft',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validTransferPayload(int $offerId): array
    {
        $offerPrice = (float) Offer::query()->findOrFail($offerId)->price;

        return [
            'offer_id' => $offerId,
            'transfer_title' => 'ZULU Test Transfer',
            'transfer_type' => 'private_transfer',
            'origin_location_id' => $this->locationIds()['yerevan_city'],
            'destination_location_id' => $this->locationIds()['gyumri_city'],
            'pickup_point_type' => 'airport',
            'pickup_point_name' => 'EVN Terminal 1',
            'dropoff_point_type' => 'hotel',
            'dropoff_point_name' => 'Grand Hotel Yerevan',
            'service_date' => '2026-09-01',
            'pickup_time' => '10:00:00',
            'estimated_duration_minutes' => 45,
            'vehicle_category' => 'sedan',
            'passenger_capacity' => 3,
            'luggage_capacity' => 3,
            'minimum_passengers' => 1,
            'maximum_passengers' => 3,
            'pricing_mode' => 'per_vehicle',
            'base_price' => $offerPrice,
            'cancellation_policy_type' => 'non_refundable',
        ];
    }

    public function test_create_transfer_success(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company, 80);
        $service = new TransferService;

        $transfer = $service->create($this->validTransferPayload($offer->id));

        $this->assertInstanceOf(Transfer::class, $transfer);
        $this->assertSame($offer->id, $transfer->offer_id);
        $this->assertSame($company->id, $transfer->company_id);
        $this->assertSame('ZULU Test Transfer', $transfer->transfer_title);
        $this->assertSame('private_transfer', $transfer->transfer_type);
        $this->assertSame('sedan', $transfer->vehicle_category);
        $this->assertSame(3, $transfer->passenger_capacity);
        $this->assertEqualsWithDelta(80.0, (float) $transfer->base_price, 0.01);
        $offer->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $offer->price, 0.01);
    }

    public function test_create_defaults_base_price_from_offer_when_omitted(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company, 99.5);
        $service = new TransferService;

        $payload = $this->validTransferPayload($offer->id);
        unset($payload['base_price']);
        $transfer = $service->create($payload);

        $this->assertEqualsWithDelta(99.5, (float) $transfer->base_price, 0.01);
    }

    public function test_create_rejects_maximum_passengers_above_capacity(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company);
        $service = new TransferService;
        $payload = $this->validTransferPayload($offer->id);
        $payload['passenger_capacity'] = 3;
        $payload['maximum_passengers'] = 8;
        $payload['minimum_passengers'] = 1;

        try {
            $service->create($payload);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('maximum_passengers', $e->errors());
        }
    }

    public function test_create_rejects_minimum_above_maximum(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company);
        $service = new TransferService;
        $payload = $this->validTransferPayload($offer->id);
        $payload['minimum_passengers'] = 5;
        $payload['maximum_passengers'] = 2;
        $payload['passenger_capacity'] = 8;

        try {
            $service->create($payload);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('maximum_passengers', $e->errors());
        }
    }

    public function test_create_rejects_non_transfer_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = Offer::query()->create([
            'company_id' => $company->id,
            'type' => 'hotel',
            'title' => 'Not a transfer',
            'price' => 10,
            'currency' => 'USD',
            'status' => 'draft',
        ]);

        $service = new TransferService;

        try {
            $service->create($this->validTransferPayload($offer->id));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('offer_id', $e->errors());
        }
    }

    public function test_create_rejects_duplicate_transfer_per_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company);
        $service = new TransferService;
        $payload = $this->validTransferPayload($offer->id);

        $service->create($payload);

        try {
            $service->create($payload);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('offer_id', $e->errors());
        }
    }

    public function test_company_id_inherited_from_offer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $companyA = Company::query()->firstOrFail();
        $companyB = Company::query()->create([
            'name' => 'Other Tenant Co',
            'type' => 'agency',
            'status' => 'active',
        ]);
        $offer = $this->makeTransferOffer($companyA);
        $service = new TransferService;

        $payload = $this->validTransferPayload($offer->id);
        $payload['company_id'] = $companyB->id;

        $transfer = $service->create($payload);

        $this->assertSame($companyA->id, $transfer->company_id);
        $this->assertNotSame($companyB->id, $transfer->company_id);
    }

    public function test_delete_removes_transfer(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->firstOrFail();
        $offer = $this->makeTransferOffer($company);
        $service = new TransferService;
        $transfer = $service->create($this->validTransferPayload($offer->id));

        $service->delete($transfer);

        $this->assertDatabaseMissing('transfers', ['id' => $transfer->id]);
    }
}
