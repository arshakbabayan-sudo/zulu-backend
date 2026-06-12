<?php

namespace App\Services\Suppliers;

use App\Models\Location;
use App\Models\Offer;
use App\Models\SupplierConnection;
use App\Models\SupplierImportedItem;
use App\Services\Cars\CarService;
use App\Services\Hotels\HotelService;
use App\Services\Transfers\TransferService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Roadmap §4 — pulls inventory from a connected external base and lands it
 * as DRAFT offers + module rows. Idempotent: the supplier_imported_items
 * ledger maps (connection, type, external_id) → offer, so re-imports update
 * price/title instead of duplicating. Items that cannot be mapped are
 * reported per-item, never abort the whole run.
 *
 * v1 verticals: hotel / car / transfer (per roadmap — flights stay on the
 * Amadeus saga path).
 */
class SupplierImportService
{
    public const MAX_PAGES = 20;

    public const MAX_ITEMS = 500;

    public const SUPPORTED_TYPES = ['hotel', 'car', 'transfer'];

    public function __construct(
        private SupplierConnectorClient $client,
        private HotelService $hotelService,
        private CarService $carService,
        private TransferService $transferService,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   created: int,
     *   updated: int,
     *   skipped: list<array{external_id: string, type: string, reason: string}>,
     *   total_seen: int,
     *   error: string|null
     * }
     */
    public function import(SupplierConnection $connection): array
    {
        $created = 0;
        $updated = 0;
        $skipped = [];
        $totalSeen = 0;

        $page = 1;
        $pagesFetched = 0;

        while ($page !== null && $pagesFetched < self::MAX_PAGES && $totalSeen < self::MAX_ITEMS) {
            $result = $this->client->fetchInventoryPage($connection, $page);
            $pagesFetched++;

            if (! $result['ok']) {
                $connection->update([
                    'status' => SupplierConnection::STATUS_FAILED,
                    'last_error' => $result['error'],
                ]);

                return [
                    'ok' => false,
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'total_seen' => $totalSeen,
                    'error' => $result['error'],
                ];
            }

            foreach ($result['items'] as $item) {
                if ($totalSeen >= self::MAX_ITEMS) {
                    break;
                }
                $totalSeen++;

                $outcome = $this->importOne($connection, $item);
                if ($outcome === 'created') {
                    $created++;
                } elseif ($outcome === 'updated') {
                    $updated++;
                } else {
                    $skipped[] = $outcome;
                }
            }

            $page = $result['next_page'];
        }

        $connection->update([
            'status' => SupplierConnection::STATUS_OK,
            'last_synced_at' => now(),
            'last_error' => null,
            'items_imported' => $connection->importedItems()->count(),
        ]);

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total_seen' => $totalSeen,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return 'created'|'updated'|array{external_id: string, type: string, reason: string}
     */
    private function importOne(SupplierConnection $connection, array $item): string|array
    {
        $type = (string) ($item['type'] ?? '');
        $externalId = (string) ($item['external_id'] ?? '');
        $title = trim((string) ($item['title'] ?? ''));

        $skip = fn (string $reason) => [
            'external_id' => $externalId !== '' ? $externalId : '(missing)',
            'type' => $type !== '' ? $type : '(missing)',
            'reason' => $reason,
        ];

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            return $skip("Unsupported item type '{$type}' (supported: ".implode(', ', self::SUPPORTED_TYPES).').');
        }
        if ($externalId === '') {
            return $skip('Missing external_id.');
        }
        if ($title === '') {
            return $skip('Missing title.');
        }

        $price = is_numeric($item['price'] ?? null) ? (float) $item['price'] : null;
        $currency = strtoupper(trim((string) ($item['currency'] ?? 'USD')));
        if ($price === null || $price <= 0) {
            return $skip('Missing or non-positive price.');
        }

        $existing = SupplierImportedItem::query()
            ->where('supplier_connection_id', $connection->id)
            ->where('item_type', $type)
            ->where('external_id', $externalId)
            ->first();

        if ($existing !== null) {
            $offer = Offer::query()->find($existing->offer_id);
            if ($offer !== null) {
                $offer->update(['title' => $title, 'price' => $price, 'currency' => $currency]);

                return 'updated';
            }
            // Offer vanished (cascade etc.) — fall through and recreate.
            $existing->delete();
        }

        $offer = Offer::query()->create([
            'company_id' => $connection->company_id,
            'type' => $type,
            'title' => $title,
            'price' => $price,
            'currency' => $currency,
            'status' => 'draft',
            'source_lang' => 'en',
        ]);

        try {
            match ($type) {
                'hotel' => $this->createHotel($offer, $item),
                'car' => $this->createCar($offer, $item),
                'transfer' => $this->createTransfer($offer, $item, $price, $currency),
            };
        } catch (ValidationException $e) {
            $offer->delete();

            return $skip($this->flattenValidationErrors($e));
        } catch (\Throwable $e) {
            $offer->delete();
            Log::warning('Supplier import item failed', [
                'connection_id' => $connection->id,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            return $skip('Import failed: '.$e->getMessage());
        }

        SupplierImportedItem::query()->create([
            'supplier_connection_id' => $connection->id,
            'item_type' => $type,
            'external_id' => $externalId,
            'offer_id' => $offer->id,
        ]);

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createHotel(Offer $offer, array $item): void
    {
        $location = $this->resolveCity((string) ($item['city'] ?? ''));

        $rooms = [];
        foreach ((array) ($item['rooms'] ?? []) as $room) {
            if (! is_array($room)) {
                continue;
            }
            $rooms[] = [
                'room_type' => (string) ($room['room_type'] ?? 'double'),
                'room_name' => (string) ($room['room_name'] ?? 'Standard room'),
                'max_adults' => (int) ($room['max_adults'] ?? 2),
                'max_children' => (int) ($room['max_children'] ?? 0),
                'max_total_guests' => (int) ($room['max_total_guests'] ?? ($room['max_adults'] ?? 2)),
                'pricings' => [
                    [
                        'price' => (float) ($room['price'] ?? $item['price']),
                        'currency' => strtoupper((string) ($room['currency'] ?? $item['currency'] ?? 'USD')),
                    ],
                ],
            ];
        }

        $this->hotelService->create([
            'offer_id' => $offer->id,
            'location_id' => $location->id,
            'hotel_name' => (string) $item['title'],
            'property_type' => (string) ($item['property_type'] ?? 'hotel'),
            'hotel_type' => (string) ($item['hotel_type'] ?? 'city'),
            'meal_type' => (string) ($item['meal_type'] ?? 'room_only'),
            'status' => 'draft',
            'availability_status' => 'available',
            'short_description' => (string) ($item['description'] ?? ''),
            'rooms' => $rooms,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createCar(Offer $offer, array $item): void
    {
        $location = $this->resolveCity((string) ($item['city'] ?? ''));

        $this->carService->create([
            'offer_id' => $offer->id,
            'location_id' => $location->id,
            'pickup_location' => (string) ($item['pickup_location'] ?? $location->name),
            'dropoff_location' => (string) ($item['dropoff_location'] ?? $location->name),
            'vehicle_class' => (string) ($item['vehicle_class'] ?? 'economy'),
            'brand' => isset($item['brand']) ? (string) $item['brand'] : null,
            'model' => isset($item['model']) ? (string) $item['model'] : null,
            'seats' => isset($item['seats']) ? (int) $item['seats'] : null,
            'base_price' => (float) $item['price'],
            'status' => 'draft',
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createTransfer(Offer $offer, array $item, float $price, string $currency): void
    {
        $origin = $this->resolveCity((string) ($item['origin_city'] ?? ''));
        $destination = $this->resolveCity((string) ($item['destination_city'] ?? ''));

        $this->transferService->create([
            'offer_id' => $offer->id,
            'transfer_title' => (string) $item['title'],
            'transfer_type' => (string) ($item['transfer_type'] ?? 'airport_transfer'),
            'origin_location_id' => $origin->id,
            'pickup_point_type' => (string) ($item['pickup_point_type'] ?? 'airport'),
            'pickup_point_name' => (string) ($item['pickup_point_name'] ?? ($origin->name.' pickup')),
            'destination_location_id' => $destination->id,
            'dropoff_point_type' => (string) ($item['dropoff_point_type'] ?? 'hotel'),
            'dropoff_point_name' => (string) ($item['dropoff_point_name'] ?? ($destination->name.' drop-off')),
            'service_date' => (string) ($item['service_date'] ?? now()->addDays(7)->toDateString()),
            'pickup_time' => (string) ($item['pickup_time'] ?? '10:00:00'),
            'estimated_duration_minutes' => (int) ($item['duration_minutes'] ?? 60),
            'vehicle_category' => (string) ($item['vehicle_category'] ?? 'sedan'),
            'passenger_capacity' => (int) ($item['passenger_capacity'] ?? 3),
            'maximum_passengers' => (int) ($item['maximum_passengers'] ?? $item['passenger_capacity'] ?? 3),
            'base_price' => $price,
            'currency' => $currency,
            'status' => 'draft',
        ]);
    }

    /**
     * City names arrive as plain text from the external base; resolve them
     * against the ZULU location tree (exact case-insensitive city match).
     *
     * @throws ValidationException when the city is unknown
     */
    private function resolveCity(string $city): Location
    {
        $city = trim($city);
        if ($city === '') {
            throw ValidationException::withMessages(['city' => ['Missing city name.']]);
        }

        $location = Location::query()
            ->where('type', Location::TYPE_CITY)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($city)])
            ->first();

        if ($location === null) {
            throw ValidationException::withMessages([
                'city' => ["Unknown city '{$city}' — it must exist in the ZULU location tree."],
            ]);
        }

        return $location;
    }

    private function flattenValidationErrors(ValidationException $e): string
    {
        $lines = [];
        foreach ($e->errors() as $field => $messages) {
            $lines[] = $field.': '.implode(' ', $messages);
        }

        return implode(' | ', $lines);
    }
}
