<?php

namespace App\Services\Bookings;

use App\Models\Flight;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Availability\BlockedDateChecker;
use App\Services\Finance\FinanceService;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\OrderService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private OrderService $orderService,
        private ?NotificationService $notificationService = null,
        private ?BlockedDateChecker $blockedDateChecker = null,
    ) {}

    private function notifications(): NotificationService
    {
        return $this->notificationService ?? app(NotificationService::class);
    }

    private function blockedDates(): BlockedDateChecker
    {
        return $this->blockedDateChecker ?? app(BlockedDateChecker::class);
    }

    /**
     * @param  array<int,array{offer_id:int,price:numeric,start_date?:string,end_date?:string}>  $itemsData
     */
    public function assertItemsAvailability(array $itemsData): void
    {
        foreach ($itemsData as $itemData) {
            $offerId = (int) ($itemData['offer_id'] ?? 0);
            if ($offerId <= 0) {
                continue;
            }

            $offer = Offer::query()->with('flight')->find($offerId);
            if (! $offer) {
                continue;
            }

            if ($offer->type === 'flight' && $offer->flight) {
                if ((int) ($offer->flight->seat_capacity_available ?? 0) <= 0) {
                    throw ValidationException::withMessages([
                        'items' => ['No seats available for flight: '.($offer->flight->flight_code_internal ?? 'unknown')],
                    ]);
                }
            }

            // Phase Զ.16 / Item 11 + roadmap §4 — block-dates enforcement.
            // Reject if the requested booking window overlaps any blocked
            // date range for this offer's item OR the offer itself (a NULL
            // item_id row blocks the whole item type). No dates = no-op.
            $startDate = isset($itemData['start_date']) ? (string) $itemData['start_date'] : null;
            $endDate = isset($itemData['end_date']) ? (string) $itemData['end_date'] : ($startDate ?: null);
            $this->blockedDates()->assertOfferNotBlocked($offer, $startDate, $endDate);
        }
    }

    /**
     * @return Collection<int, Order>
     */
    public function listForCompanies(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        return Order::query()
            ->where('metadata->legacy_origin', 'booking')
            ->whereIn('company_id', $companyIds)
            ->with(['items', 'user'])
            ->orderBy('created_at')
            ->get();
    }

    public function paginateForCompanies(array $companyIds, int $perPage = 20): LengthAwarePaginator
    {
        $query = Order::query()
            ->where('metadata->legacy_origin', 'booking')
            ->with(['items', 'user'])
            ->orderBy('created_at');

        if ($companyIds === []) {
            return Order::query()->whereRaw('0 = 1')->paginate($perPage);
        }

        return $query
            ->whereIn('company_id', $companyIds)
            ->paginate($perPage);
    }

    /**
     * @param  array{user_id:int,company_id:int,status?:string,currency?:string,meta?:array<string,mixed>|null}  $bookingData
     * @param  array<int,array{offer_id:int,price?:numeric,quantity?:int}>  $itemsData
     * @param  array<int, array<string, mixed>>  $passengersData
     */
    public function create(array $bookingData, array $itemsData, array $passengersData = []): Order
    {
        $this->assertItemsAvailability($itemsData);

        $currency = strtoupper((string) ($bookingData['currency'] ?? 'USD'));
        $offers = Offer::query()
            ->with('flight')
            ->whereIn('id', collect($itemsData)->pluck('offer_id')->filter()->unique()->values()->all())
            ->get()
            ->keyBy('id');

        $mappedPassengers = $this->normalizePassengers($passengersData);
        $itemsPayload = $this->buildOrderItemsPayload($itemsData, $offers->all(), $currency, $mappedPassengers);

        // Attribute the sale to the acting seller employee (operator/agent) when
        // the order is created by a member of the selling company — null for B2C
        // self-service. Powers CRM "Team" attribution + employee row-scope.
        $actingUser = auth()->user();
        $sellerCompanyIds = array_values(array_filter([
            $bookingData['company_id'] ?? null,
            $bookingData['agent_company_id'] ?? null,
        ]));
        $soldByUserId = $actingUser !== null && $sellerCompanyIds !== []
            && $actingUser->companies()->whereIn('companies.id', $sellerCompanyIds)->exists()
            ? $actingUser->id
            : null;

        // C4 — record the booking selection (pax/dates/contact/notes) under
        // metadata.meta WITHOUT clobbering legacy_origin (queried by
        // listForCompanies/paginateForCompanies). Absent meta => metadata is
        // exactly ['legacy_origin' => 'booking'], i.e. zero behaviour change.
        $metadata = ['legacy_origin' => 'booking'];
        if (! empty($bookingData['meta'])) {
            $metadata['meta'] = $bookingData['meta'];
        }

        $order = $this->orderService->create(
            [
                'user_id' => $bookingData['user_id'] ?? null,
                'company_id' => $bookingData['company_id'] ?? null,
                'agent_company_id' => $bookingData['agent_company_id'] ?? null,
                'sold_by_user_id' => $soldByUserId,
                'currency' => $currency,
                'buyer_type' => 'client',
                'status' => 'pending_payment',
                'metadata' => $metadata,
            ],
            $itemsPayload
        );

        // Notify the attributed company's staff (admin top-bar bell) that a B2C
        // booking was just placed, so the seller can follow up and close the
        // sale. Best-effort; a notification failure never blocks the booking.
        if ($order->status === 'pending_payment') {
            try {
                $this->notifications()->notifyCompanyStaffOfBooking($order);
            } catch (\Throwable $e) {
                Log::warning('Booking-placed notification dispatch failed', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $order;
    }

    public function confirm(Order $order): Order
    {
        $wasConfirmed = $order->getOriginal('status') === 'confirmed';
        $order->status = 'confirmed';
        $order->save();

        // Decrement flight seat capacity once per confirm transition.
        if (! $wasConfirmed) {
            $this->adjustFlightSeatCapacity($order, -1);
        }

        try {
            app(FinanceService::class)->createEntitlementsForOrder($order->fresh(['items']));
        } catch (\Throwable $e) {
            Log::warning('Order entitlement creation failed during booking confirm', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $order->fresh(['items']);
    }

    public function cancel(Order $order): Order
    {
        $wasConfirmed = $order->getOriginal('status') === 'confirmed';
        $order->status = 'cancelled';
        $order->save();

        // Return seats only if they were previously decremented.
        if ($wasConfirmed) {
            $this->adjustFlightSeatCapacity($order, +1);
        }

        return $order->fresh(['items']);
    }

    /**
     * Adjust flight seat capacity for every flight item on the order by the
     * given sign (-1 to consume seats on confirm, +1 to return seats on
     * cancel). Capacity is clamped at zero so a double-confirm or stale
     * webhook never drives a flight negative.
     */
    private function adjustFlightSeatCapacity(Order $order, int $sign): void
    {
        if ($sign === 0) {
            return;
        }

        $items = $order->relationLoaded('items')
            ? $order->items
            : $order->items()->get();

        foreach ($items as $item) {
            if (! $item instanceof OrderItem || $item->item_type !== 'flight') {
                continue;
            }
            $flightId = (int) ($item->item_id ?? 0);
            if ($flightId <= 0) {
                continue;
            }
            $quantity = (int) ($item->quantity ?? 1);
            if ($quantity <= 0) {
                continue;
            }
            $delta = $sign * $quantity;

            try {
                DB::transaction(function () use ($flightId, $delta) {
                    $flight = Flight::query()->lockForUpdate()->find($flightId);
                    if (! $flight) {
                        return;
                    }
                    $current = (int) ($flight->seat_capacity_available ?? 0);
                    $next = max(0, $current + $delta);
                    $flight->seat_capacity_available = $next;
                    $flight->save();
                });
            } catch (\Throwable $e) {
                Log::warning('Flight seat capacity adjust failed', [
                    'order_id' => $order->id,
                    'flight_id' => $flightId,
                    'delta' => $delta,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function getWithDetails(int $id): ?Order
    {
        return Order::query()
            ->where('metadata->legacy_origin', 'booking')
            ->where('metadata->legacy_booking_id', $id)
            ->with(['user', 'company', 'items', 'invoices'])
            ->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $passengersData
     * @return array<int, array<string, mixed>>
     */
    private function normalizePassengers(array $passengersData): array
    {
        $rows = [];

        foreach ($passengersData as $passenger) {
            if (! is_array($passenger)) {
                continue;
            }

            $firstName = isset($passenger['first_name']) ? trim((string) $passenger['first_name']) : '';
            $lastName = isset($passenger['last_name']) ? trim((string) $passenger['last_name']) : '';
            if ($firstName === '' || $lastName === '') {
                continue;
            }

            $rows[] = [
                'booking_item_id' => isset($passenger['booking_item_id']) && $passenger['booking_item_id'] !== ''
                    ? (string) $passenger['booking_item_id']
                    : null,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => $passenger['date_of_birth'] ?? $passenger['birth_date'] ?? null,
                'passport_number' => $passenger['passport_number'] ?? null,
                'passport_expiry' => $passenger['passport_expiry'] ?? $passenger['passport_expiry_date'] ?? null,
                'nationality' => $passenger['nationality'] ?? $passenger['nationality_country_code'] ?? null,
                'gender' => $passenger['gender'] ?? null,
                'email' => $passenger['email'] ?? null,
                'phone' => $passenger['phone'] ?? null,
                'passenger_type' => $passenger['passenger_type'] ?? 'adult',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{offer_id:int,price?:numeric,quantity?:int}>  $itemsData
     * @param  array<int, Offer>  $offersById
     * @param  array<int, array<string, mixed>>  $passengers
     * @return array<int, array<string, mixed>>
     */
    private function buildOrderItemsPayload(array $itemsData, array $offersById, string $currency, array $passengers): array
    {
        $passengersByLegacyItem = [];
        foreach ($passengers as $passenger) {
            $legacyItemId = $passenger['booking_item_id'] ?? null;
            if ($legacyItemId === null) {
                continue;
            }

            $passengersByLegacyItem[$legacyItemId][] = $this->stripPassengerLegacyKeys($passenger);
        }

        $hasPerItemMappings = $passengersByLegacyItem !== [];
        $matchedMappedPassengers = false;
        $sharedPassengers = array_map(
            fn (array $passenger): array => $this->stripPassengerLegacyKeys($passenger),
            $passengers
        );

        $payload = [];
        foreach (array_values($itemsData) as $index => $itemData) {
            $offer = $offersById[(int) ($itemData['offer_id'] ?? 0)] ?? null;
            $flightId = null;
            if ($offer !== null && $offer->type === 'flight') {
                $flightId = $offer->flight_id ?? $offer->flight?->id;
            }

            $legacyItemKey = (string) ($itemData['booking_item_id'] ?? $itemData['id'] ?? ($index + 1));
            $passengerData = $passengersByLegacyItem[$legacyItemKey] ?? null;
            if ($passengerData !== null) {
                $matchedMappedPassengers = true;
            } elseif (! $hasPerItemMappings) {
                $passengerData = $sharedPassengers;
            }

            // Phase 1 / B.4 — caller no longer passes unit_price. Service
            // resolves the price internally via PricingResolver from
            // offer_id. The legacy `itemData.price` value (from the API
            // request body) is IGNORED for security; the resolved price is
            // authoritative. If frontends still send `price`, it's
            // harmless — just no longer trusted.
            $payload[] = [
                'item_type' => 'flight',
                'item_id' => $flightId,
                'currency' => $currency,
                'offer_id' => (int) ($itemData['offer_id'] ?? 0),
                // C4 — thread the on-screen multiplier through to OrderService,
                // which resolves price × quantity via PricingResolver. Floored
                // at >=1 (PricingResolver throws on quantity<=0); absent => 1.
                'quantity' => max(1, (int) ($itemData['quantity'] ?? 1)),
                // Flight cabin pricing — forward the optional supplier_net
                // override (the selected cabin's RAW adult_price) so OrderService
                // feeds it into PricingResolver's buyerContext['price_override'].
                // Null (default / non-flight) preserves the legacy offer.price
                // resolution exactly.
                'price_override' => $itemData['price_override'] ?? null,
                'service_snapshot' => [
                    'legacy_offer_id' => (int) ($itemData['offer_id'] ?? 0),
                ],
                'passenger_data' => $passengerData !== [] ? $passengerData : null,
            ];
        }

        if ($hasPerItemMappings && ! $matchedMappedPassengers && $sharedPassengers !== []) {
            foreach ($payload as &$itemPayload) {
                $itemPayload['passenger_data'] = $sharedPassengers;
            }
            unset($itemPayload);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $passenger
     * @return array<string, mixed>
     */
    private function stripPassengerLegacyKeys(array $passenger): array
    {
        unset($passenger['booking_item_id']);

        return $passenger;
    }
}
