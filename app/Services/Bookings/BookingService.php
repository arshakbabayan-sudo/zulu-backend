<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\Flight;
use App\Models\Offer;
use App\Models\Passenger;
use App\Services\Finance\FinanceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingService
{
    /**
     * @param  array<int,array{offer_id:int,price:numeric}>  $itemsData
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
        }
    }

    public function listForCompanies(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        return Booking::query()
            ->with(['items', 'passengers', 'user'])
            ->whereIn('company_id', $companyIds)
            ->orderBy('id')
            ->get();
    }

    public function paginateForCompanies(array $companyIds, int $perPage = 20): LengthAwarePaginator
    {
        $query = Booking::query()
            ->with(['items', 'passengers', 'user'])
            ->orderBy('id');

        if ($companyIds === []) {
            return $query->whereRaw('0 = 1')->paginate($perPage);
        }

        return $query
            ->whereIn('company_id', $companyIds)
            ->paginate($perPage);
    }

    /**
     * @param  array{user_id:int,company_id:int,status?:string,total_price?:numeric}  $bookingData
     * @param  array<int,array{offer_id:int,price:numeric}>  $itemsData
     * @param  array<int, array<string, mixed>>  $passengersData
     */
    public function create(array $bookingData, array $itemsData, array $passengersData = []): Booking
    {
        return DB::transaction(function () use ($bookingData, $itemsData, $passengersData): Booking {
            if (! isset($bookingData['status'])) {
                $bookingData['status'] = 'pending';
            }
            $bookingData['total_price'] = 0;
            $booking = Booking::query()->create($bookingData);
            foreach ($itemsData as $itemData) {
                $booking->items()->create($itemData);
            }
            foreach ($passengersData as $pData) {
                $passenger = null;
                if (isset($pData['id'])) {
                    $passenger = Passenger::query()->find($pData['id']);
                }
                if (! $passenger) {
                    $dob = $pData['date_of_birth'] ?? $pData['birth_date'] ?? null;
                    $passenger = Passenger::query()->create([
                        'first_name' => $pData['first_name'],
                        'last_name' => $pData['last_name'],
                        'date_of_birth' => $dob,
                        'passenger_type' => $pData['passenger_type'] ?? 'adult',
                        'passport_number' => $pData['passport_number'] ?? null,
                        'passport_expiry' => $pData['passport_expiry'] ?? $pData['passport_expiry_date'] ?? null,
                        'nationality' => $pData['nationality'] ?? $pData['nationality_country_code'] ?? null,
                        'gender' => $pData['gender'] ?? null,
                        'email' => $pData['email'] ?? null,
                        'phone' => $pData['phone'] ?? null,
                    ]);
                }
                $booking->passengers()->attach($passenger->id, [
                    'booking_item_id' => $pData['booking_item_id'] ?? null,
                    'seat_number' => $pData['seat_number'] ?? null,
                    'special_requests' => $pData['special_requests'] ?? null,
                ]);
            }

            $booking->load(['items', 'passengers']);
            return $this->recalculateTotal($booking);
        });
    }

    public function recalculateTotal(Booking $booking): Booking
    {
        $total = (float) $booking->items()->sum('price');
        $booking->total_price = $total;
        $booking->save();

        $booking->load(['items', 'passengers']);

        return $booking;
    }

    public function confirm(Booking $booking): Booking
    {
        $booking->status = Booking::STATUS_CONFIRMED;
        $booking->save();
        try {
            DB::transaction(function () use ($booking): void {
                foreach ($booking->items()->with('offer.flight.cabins')->get() as $item) {
                    $flight = $item->offer?->flight ?? null;
                    if ($flight === null) {
                        continue;
                    }

                    $flightRow = Flight::query()
                        ->where('id', $flight->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $flightRow) {
                        continue;
                    }

                    if ((int) ($flightRow->seat_capacity_available ?? 0) <= 0) {
                        continue;
                    }

                    $flightRow->decrement('seat_capacity_available');
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Seat capacity decrement failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        $booking->load(['items', 'passengers']);

        try {
            app(FinanceService::class)->createEntitlementsForBooking($booking);
        } catch (\Throwable $e) {
            Log::warning('Booking entitlement creation failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $booking;
    }

    public function cancel(Booking $booking): Booking
    {
        $booking->status = Booking::STATUS_CANCELLED;
        $booking->save();

        try {
            foreach ($booking->items()->with('offer.flight')->get() as $item) {
                $flight = $item->offer?->flight ?? null;
                if ($flight === null) {
                    continue;
                }

                Flight::query()
                    ->where('id', $flight->id)
                    ->increment('seat_capacity_available');
            }
        } catch (\Throwable $e) {
            Log::warning('Seat capacity restore failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        $booking->load(['items', 'passengers']);

        return $booking;
    }

    public function getWithDetails(int $id): ?Booking
    {
        return Booking::with(['user', 'company', 'passengers', 'items', 'invoices'])->find($id);
    }
}
