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
 * Amadeus PNR-based flight reserver.
 *
 * Two-step protocol:
 *   - reserve():   call /v1/booking/flight-orders with the offer
 *                  payload, receive a PNR (passenger name record)
 *                  reference, store it as supplier_ref.
 *   - confirm():   issue tickets via /v1/booking/flight-orders/{id}/ticket
 *                  (single-step bookings auto-issue, see Amadeus docs).
 *   - rollback():  DELETE /v1/booking/flight-orders/{id} releases the
 *                  reservation while still cancellable.
 *
 * STATE (2026-05-12): Self-Service or Enterprise Amadeus contract not
 * yet signed. Driver short-circuits to StubComponentReserver behavior
 * via the unconfigured() guard until AMADEUS_BASE_URL +
 * AMADEUS_API_KEY + AMADEUS_API_SECRET land in .env.
 *
 * Activation steps (post-contract):
 *   1. Set AMADEUS_BASE_URL=https://test.api.amadeus.com (sandbox) or
 *      https://api.amadeus.com (prod) in .env
 *   2. Set AMADEUS_API_KEY + AMADEUS_API_SECRET
 *   3. Register this reserver in AppServiceProvider for service type
 *      "flight" (replaces SupplierApiReserver / StubComponentReserver)
 *   4. Walk one real test booking on sandbox; flip to prod URL.
 */
class AmadeusFlightReserver implements ComponentReserverInterface
{
    private StubComponentReserver $stubFallback;

    private ?string $baseUrl;

    private ?string $apiKey;

    private ?string $apiSecret;

    private ?string $cachedAccessToken = null;

    public function __construct()
    {
        $this->stubFallback = new StubComponentReserver('flight');
        $this->baseUrl = config('supplier.flight.amadeus.base_url');
        $this->apiKey = config('supplier.flight.amadeus.api_key');
        $this->apiSecret = config('supplier.flight.amadeus.api_secret');
    }

    public function serviceType(): string
    {
        return 'flight';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->apiKey) && ! empty($this->apiSecret);
    }

    public function reserve(SagaComponentState $component, ?OrderItem $item = null): ReservationResult
    {
        if (! $this->isConfigured()) {
            return $this->stubFallback->reserve($component, $item);
        }

        $token = $this->fetchAccessToken();
        if ($token === null) {
            return ReservationResult::failure(
                'Amadeus OAuth token fetch failed',
                ['reserver' => 'amadeus', 'service_type' => 'flight']
            );
        }

        try {
            $offerPayload = (array) ($component->reservation_payload['amadeus_offer'] ?? []);
            $travelers = (array) ($component->reservation_payload['travelers'] ?? []);

            $response = Http::withToken($token)
                ->withHeaders(['X-Idempotency-Key' => $component->idempotency_key])
                ->timeout(20)
                ->acceptJson()
                ->post($this->baseUrl.'/v1/booking/flight-orders', [
                    'data' => [
                        'type' => 'flight-order',
                        'flightOffers' => $offerPayload === [] ? [] : [$offerPayload],
                        'travelers' => $travelers,
                    ],
                ]);

            $body = $response->json();

            if ($response->failed()) {
                $error = $body['errors'][0]['detail'] ?? 'Amadeus flight-orders failed';

                return ReservationResult::failure((string) $error, [
                    'reserver' => 'amadeus',
                    'service_type' => 'flight',
                    'http_status' => $response->status(),
                ]);
            }

            $pnr = (string) ($body['data']['id'] ?? '');
            if ($pnr === '') {
                return ReservationResult::failure('Amadeus did not return a PNR', [
                    'reserver' => 'amadeus',
                    'service_type' => 'flight',
                ]);
            }

            return ReservationResult::success(
                supplierRef: 'AMD-'.$pnr,
                payload: [
                    'reserver' => 'amadeus',
                    'service_type' => 'flight',
                    'pnr' => $pnr,
                    'associated_records' => $body['data']['associatedRecords'] ?? [],
                    'reserved_at' => now()->toIso8601String(),
                ],
            );
        } catch (\Throwable $e) {
            Log::error('Amadeus reserve threw', ['error' => $e->getMessage(), 'component_id' => $component->id]);

            return ReservationResult::failure($e->getMessage(), [
                'reserver' => 'amadeus',
                'service_type' => 'flight',
                'exception' => get_class($e),
            ]);
        }
    }

    public function confirm(SagaComponentState $component): ConfirmationResult
    {
        if (! $this->isConfigured()) {
            return $this->stubFallback->confirm($component);
        }

        // Amadeus Self-Service flight-orders are auto-ticketed at
        // creation time; an explicit ticket call is only needed on
        // Enterprise. Treat confirm as a no-op success unless the env
        // configures AMADEUS_REQUIRES_TICKET_CALL=true.
        if (! config('supplier.flight.amadeus.requires_ticket_call', false)) {
            return ConfirmationResult::success([
                'reserver' => 'amadeus',
                'service_type' => 'flight',
                'confirmed_at' => now()->toIso8601String(),
                'note' => 'auto-ticketed at reserve time',
            ]);
        }

        $token = $this->fetchAccessToken();
        if ($token === null) {
            return ConfirmationResult::failure('Amadeus OAuth token fetch failed');
        }

        $pnr = ltrim((string) $component->supplier_ref, 'AMD-');

        try {
            $response = Http::withToken($token)
                ->timeout(20)
                ->post($this->baseUrl.'/v1/booking/flight-orders/'.$pnr.'/ticket');

            if ($response->failed()) {
                return ConfirmationResult::failure(
                    (string) ($response->json()['errors'][0]['detail'] ?? 'Ticketing failed')
                );
            }

            return ConfirmationResult::success([
                'reserver' => 'amadeus',
                'service_type' => 'flight',
                'confirmed_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
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

        $token = $this->fetchAccessToken();
        if ($token === null) {
            return RollbackResult::failure('Amadeus OAuth token fetch failed');
        }

        $pnr = ltrim((string) $component->supplier_ref, 'AMD-');

        try {
            $response = Http::withToken($token)
                ->timeout(20)
                ->delete($this->baseUrl.'/v1/booking/flight-orders/'.$pnr);

            if ($response->failed() && $response->status() !== 404) {
                return RollbackResult::failure(
                    (string) ($response->json()['errors'][0]['detail'] ?? 'Amadeus cancel failed')
                );
            }

            return RollbackResult::success();
        } catch (\Throwable $e) {
            return RollbackResult::failure($e->getMessage());
        }
    }

    /**
     * Amadeus uses OAuth2 client_credentials. Cache the access_token on
     * the instance for the duration of one saga; the token TTL is 30 min
     * and a saga finishes in seconds, so per-call refresh is OK without
     * a shared cache.
     */
    private function fetchAccessToken(): ?string
    {
        if ($this->cachedAccessToken !== null) {
            return $this->cachedAccessToken;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post($this->baseUrl.'/v1/security/oauth2/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->apiKey,
                    'client_secret' => $this->apiSecret,
                ]);

            if ($response->failed()) {
                return null;
            }

            $token = (string) ($response->json()['access_token'] ?? '');
            if ($token === '') {
                return null;
            }

            $this->cachedAccessToken = $token;

            return $token;
        } catch (\Throwable $e) {
            Log::error('Amadeus OAuth fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
