<?php

namespace App\Services\Packages\Saga\Reservers;

use App\Models\OrderItem;
use App\Models\SagaComponentState;
use App\Services\Packages\Saga\Contracts\ComponentReserverInterface;
use App\Services\Packages\Saga\DTOs\ConfirmationResult;
use App\Services\Packages\Saga\DTOs\ReservationResult;
use App\Services\Packages\Saga\DTOs\RollbackResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generic per-service-type supplier-API reserver.
 *
 * Each service type (flight / hotel / transfer / car / excursion / visa /
 * insurance) can map to a vendor whose API speaks one of two flavors:
 *
 *   1. Custom REST API with /reserve, /confirm, /rollback endpoints
 *      (transfer dispatch, car rental, excursion provider, insurance,
 *       visa — typically a single SaaS vendor per service)
 *   2. Industry-specific protocol (Amadeus NDC for flights, hotel PMS
 *      OTA-style XML for hotels — these need a dedicated reserver
 *      subclass that overrides reserve() / confirm() / rollback())
 *
 * This base class handles category (1): the saga reads
 * `supplier.{service_type}.endpoint` + `.api_key` from config, POSTs the
 * component state to /reserve, parses the supplier_ref out of the
 * response, and similarly for /confirm and /rollback.
 *
 * When credentials are missing for a service type, the reserver falls
 * back to StubComponentReserver behavior so existing tests + dev flows
 * keep passing. Production switches behavior the moment env vars land.
 */
class SupplierApiReserver implements ComponentReserverInterface
{
    private StubComponentReserver $stubFallback;

    private ?string $endpoint;

    private ?string $apiKey;

    private int $timeoutSeconds;

    public function __construct(private string $serviceType)
    {
        $this->stubFallback = new StubComponentReserver($serviceType);
        $this->endpoint = config("supplier.{$serviceType}.endpoint");
        $this->apiKey = config("supplier.{$serviceType}.api_key");
        $this->timeoutSeconds = (int) config("supplier.{$serviceType}.timeout", 15);
    }

    public function reserve(SagaComponentState $component, ?OrderItem $item = null): ReservationResult
    {
        if (! $this->isConfigured()) {
            return $this->stubFallback->reserve($component, $item);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'X-Idempotency-Key' => $component->idempotency_key,
            ])
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($this->endpoint.'/reserve', [
                    'service_type' => $this->serviceType,
                    'component_id' => $component->id,
                    'idempotency_key' => $component->idempotency_key,
                    'item_id' => $item?->id,
                    'payload' => $component->reservation_payload ?? [],
                ]);

            $body = $response->json();

            if ($response->failed() || ($body['success'] ?? false) !== true) {
                $error = (string) ($body['error'] ?? 'Supplier reserve failed');
                Log::warning("Supplier reserve failed [{$this->serviceType}]", [
                    'status' => $response->status(),
                    'body' => $body,
                    'component_id' => $component->id,
                ]);

                return ReservationResult::failure($error, [
                    'service_type' => $this->serviceType,
                    'reserver' => 'supplier-api',
                    'http_status' => $response->status(),
                ]);
            }

            return ReservationResult::success(
                supplierRef: (string) $body['supplier_ref'],
                payload: array_merge([
                    'reserver' => 'supplier-api',
                    'service_type' => $this->serviceType,
                    'item_id' => $item?->id,
                    'reserved_at' => now()->toIso8601String(),
                ], (array) ($body['payload'] ?? [])),
            );
        } catch (\Throwable $e) {
            Log::error("Supplier reserve threw [{$this->serviceType}]", [
                'error' => $e->getMessage(),
                'component_id' => $component->id,
            ]);

            return ReservationResult::failure($e->getMessage(), [
                'service_type' => $this->serviceType,
                'reserver' => 'supplier-api',
                'exception' => get_class($e),
            ]);
        }
    }

    public function confirm(SagaComponentState $component): ConfirmationResult
    {
        if (! $this->isConfigured()) {
            return $this->stubFallback->confirm($component);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'X-Idempotency-Key' => $component->idempotency_key,
            ])
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($this->endpoint.'/confirm', [
                    'service_type' => $this->serviceType,
                    'supplier_ref' => $component->supplier_ref,
                ]);

            $body = $response->json();

            if ($response->failed() || ($body['success'] ?? false) !== true) {
                Log::warning("Supplier confirm failed [{$this->serviceType}]", [
                    'status' => $response->status(),
                    'body' => $body,
                    'component_id' => $component->id,
                ]);

                return ConfirmationResult::failure(
                    (string) ($body['error'] ?? 'Supplier confirm failed')
                );
            }

            return ConfirmationResult::success([
                'reserver' => 'supplier-api',
                'service_type' => $this->serviceType,
                'confirmed_at' => now()->toIso8601String(),
                'supplier_payload' => $body['payload'] ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::error("Supplier confirm threw [{$this->serviceType}]", [
                'error' => $e->getMessage(),
                'component_id' => $component->id,
            ]);

            return ConfirmationResult::failure($e->getMessage());
        }
    }

    public function rollback(SagaComponentState $component): RollbackResult
    {
        if (! $this->isConfigured()) {
            return $this->stubFallback->rollback($component);
        }

        if (empty($component->supplier_ref)) {
            return RollbackResult::success();
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'X-Idempotency-Key' => $component->idempotency_key,
            ])
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($this->endpoint.'/rollback', [
                    'service_type' => $this->serviceType,
                    'supplier_ref' => $component->supplier_ref,
                ]);

            $body = $response->json();

            if ($response->failed() || ($body['success'] ?? false) !== true) {
                return RollbackResult::failure(
                    (string) ($body['error'] ?? 'Supplier rollback failed')
                );
            }

            return RollbackResult::success();
        } catch (\Throwable $e) {
            Log::error("Supplier rollback threw [{$this->serviceType}]", [
                'error' => $e->getMessage(),
                'supplier_ref' => $component->supplier_ref,
            ]);

            return RollbackResult::failure($e->getMessage());
        }
    }

    public function serviceType(): string
    {
        return $this->serviceType;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->endpoint) && ! empty($this->apiKey);
    }
}
