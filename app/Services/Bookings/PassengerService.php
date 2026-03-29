<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\Passenger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PassengerService
{
    /**
     * @param  array<int, array<string, mixed>>  $passengersData
     * @return array<int, Passenger>
     *
     * @throws ValidationException
     */
    public function createForBooking(Booking $booking, array $passengersData): array
    {
        return DB::transaction(function () use ($booking, $passengersData): array {
            $created = [];
            foreach ($passengersData as $row) {
                $validated = $this->validatePassengerRow($booking, $row);

                $passenger = Passenger::query()->create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'passport_number' => $validated['passport_number'] ?? null,
                    'passport_expiry' => $validated['passport_expiry'] ?? null,
                    'nationality' => $validated['nationality'] ?? null,
                    'date_of_birth' => $validated['date_of_birth'] ?? null,
                    'gender' => $validated['gender'] ?? null,
                    'passenger_type' => $validated['passenger_type'],
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                ]);

                $booking->passengers()->attach($passenger->id, [
                    'booking_item_id' => $validated['booking_item_id'] ?? null,
                    'seat_number' => $validated['seat_number'] ?? null,
                    'special_requests' => $validated['special_requests'] ?? null,
                ]);

                $created[] = $passenger->fresh();
            }

            return $created;
        });
    }

    public function listForBooking(Booking $booking): Collection
    {
        return $booking->passengers()
            ->orderBy('booking_passengers.id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePassenger(Passenger $passenger, array $data): Passenger
    {
        $allowed = array_intersect_key($data, array_flip($passenger->getFillable()));
        $passenger->fill($allowed);
        $passenger->save();

        return $passenger->fresh();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function validatePassengerRow(Booking $booking, array $row): array
    {
        return Validator::make($row, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'passport_number' => ['nullable', 'string', 'max:50'],
            'passport_expiry' => ['nullable', 'date', 'after:today'],
            'nationality' => ['nullable', 'string', 'size:2'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'in:male,female,other'],
            'passenger_type' => ['required', 'string', 'in:adult,child,infant'],
            'booking_item_id' => [
                'nullable',
                'integer',
                Rule::exists('booking_items', 'id')->where('booking_id', $booking->id),
            ],
            'seat_number' => ['nullable', 'string', 'max:10'],
            'special_requests' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ])->validate();
    }
}
