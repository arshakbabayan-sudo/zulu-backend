<?php

namespace Tests\Unit;

use App\Models\SagaComponentState;
use App\Services\Packages\Saga\Reservers\AmadeusFlightReserver;
use App\Services\Packages\Saga\Reservers\SupplierApiReserver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupplierApiReserverTest extends TestCase
{
    public function test_unconfigured_reserver_falls_back_to_stub(): void
    {
        config()->set('supplier.hotel.endpoint', '');
        config()->set('supplier.hotel.api_key', '');

        $reserver = new SupplierApiReserver('hotel');

        $this->assertFalse($reserver->isConfigured());

        $component = new SagaComponentState;
        $component->id = 1;
        $component->idempotency_key = 'idem-abc123';

        $result = $reserver->reserve($component);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->supplierRef);
        $this->assertStringStartsWith('STUB-hotel-', (string) $result->supplierRef);
        $this->assertSame('stub', $result->payload['reserver']);
    }

    public function test_configured_reserver_posts_to_endpoint_and_returns_supplier_ref(): void
    {
        Http::fake([
            'https://supplier.test/reserve' => Http::response([
                'success' => true,
                'supplier_ref' => 'VENDOR-XYZ-123',
                'payload' => ['vendor_note' => 'held'],
            ], 200),
        ]);

        config()->set('supplier.transfer.endpoint', 'https://supplier.test');
        config()->set('supplier.transfer.api_key', 'k-secret');

        $reserver = new SupplierApiReserver('transfer');
        $this->assertTrue($reserver->isConfigured());

        $component = new SagaComponentState;
        $component->id = 7;
        $component->idempotency_key = 'idem-xfer-1';
        $component->reservation_payload = ['from' => 'YVR', 'to' => 'hotel'];

        $result = $reserver->reserve($component);

        $this->assertTrue($result->success);
        $this->assertSame('VENDOR-XYZ-123', $result->supplierRef);
        $this->assertSame('supplier-api', $result->payload['reserver']);
        $this->assertSame('held', $result->payload['vendor_note']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://supplier.test/reserve'
                && $request->hasHeader('Authorization', 'Bearer k-secret')
                && $request->hasHeader('X-Idempotency-Key', 'idem-xfer-1');
        });
    }

    public function test_configured_reserver_propagates_supplier_failure(): void
    {
        Http::fake([
            'https://supplier.test/reserve' => Http::response([
                'success' => false,
                'error' => 'Out of stock',
            ], 200),
        ]);

        config()->set('supplier.car.endpoint', 'https://supplier.test');
        config()->set('supplier.car.api_key', 'k-secret');

        $reserver = new SupplierApiReserver('car');
        $component = new SagaComponentState;
        $component->id = 9;
        $component->idempotency_key = 'idem-car-1';

        $result = $reserver->reserve($component);

        $this->assertFalse($result->success);
        $this->assertSame('Out of stock', $result->errorMessage);
    }

    public function test_http_exception_returns_failure_result_not_thrown(): void
    {
        Http::fake([
            'https://supplier.test/reserve' => Http::response([], 500),
        ]);

        config()->set('supplier.excursion.endpoint', 'https://supplier.test');
        config()->set('supplier.excursion.api_key', 'k-secret');

        $reserver = new SupplierApiReserver('excursion');
        $component = new SagaComponentState;
        $component->id = 11;
        $component->idempotency_key = 'idem-exc-1';

        $result = $reserver->reserve($component);

        $this->assertFalse($result->success);
    }

    public function test_rollback_skips_call_when_supplier_ref_empty(): void
    {
        Http::fake();

        config()->set('supplier.visa.endpoint', 'https://supplier.test');
        config()->set('supplier.visa.api_key', 'k-secret');

        $reserver = new SupplierApiReserver('visa');
        $component = new SagaComponentState;
        $component->id = 13;
        $component->idempotency_key = 'idem-visa-1';
        $component->supplier_ref = null;

        $result = $reserver->rollback($component);

        $this->assertTrue($result->success);
        Http::assertNothingSent();
    }

    public function test_amadeus_reserver_falls_back_to_stub_when_unconfigured(): void
    {
        config()->set('supplier.flight.amadeus.base_url', '');
        config()->set('supplier.flight.amadeus.api_key', '');
        config()->set('supplier.flight.amadeus.api_secret', '');

        $reserver = new AmadeusFlightReserver;
        $this->assertFalse($reserver->isConfigured());

        $component = new SagaComponentState;
        $component->id = 21;
        $component->idempotency_key = 'idem-flight-1';

        $result = $reserver->reserve($component);
        $this->assertTrue($result->success);
        $this->assertStringStartsWith('STUB-flight-', (string) $result->supplierRef);
    }

    public function test_amadeus_reserver_uses_pnr_when_configured(): void
    {
        Http::fake([
            'https://amadeus.test/v1/security/oauth2/token' => Http::response([
                'access_token' => 'tk-abc',
                'expires_in' => 1799,
            ], 200),
            'https://amadeus.test/v1/booking/flight-orders' => Http::response([
                'data' => [
                    'id' => 'eJzTd9f3MzIJdwwBAAp%2FAiY=',
                    'type' => 'flight-order',
                    'associatedRecords' => [['reference' => 'ABC123']],
                ],
            ], 200),
        ]);

        config()->set('supplier.flight.amadeus.base_url', 'https://amadeus.test');
        config()->set('supplier.flight.amadeus.api_key', 'apk');
        config()->set('supplier.flight.amadeus.api_secret', 'aps');

        $reserver = new AmadeusFlightReserver;
        $this->assertTrue($reserver->isConfigured());

        $component = new SagaComponentState;
        $component->id = 31;
        $component->idempotency_key = 'idem-flight-2';
        $component->reservation_payload = [
            'amadeus_offer' => ['id' => '1'],
            'travelers' => [['id' => '1', 'name' => ['firstName' => 'A', 'lastName' => 'B']]],
        ];

        $result = $reserver->reserve($component);

        $this->assertTrue($result->success);
        $this->assertStringStartsWith('AMD-', (string) $result->supplierRef);
        $this->assertSame('amadeus', $result->payload['reserver']);
        $this->assertSame([['reference' => 'ABC123']], $result->payload['associated_records']);
    }

    public function test_amadeus_confirm_is_no_op_when_auto_ticketed(): void
    {
        config()->set('supplier.flight.amadeus.base_url', 'https://amadeus.test');
        config()->set('supplier.flight.amadeus.api_key', 'apk');
        config()->set('supplier.flight.amadeus.api_secret', 'aps');
        config()->set('supplier.flight.amadeus.requires_ticket_call', false);

        Http::fake();

        $reserver = new AmadeusFlightReserver;
        $component = new SagaComponentState;
        $component->id = 41;
        $component->idempotency_key = 'idem-flight-3';
        $component->supplier_ref = 'AMD-PNR123';

        $result = $reserver->confirm($component);

        $this->assertTrue($result->success);
        $this->assertSame('amadeus', $result->payload['reserver']);
        $this->assertSame('auto-ticketed at reserve time', $result->payload['note']);
    }
}
