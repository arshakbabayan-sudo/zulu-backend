<?php

namespace App\Services\Availability;

class AvailabilityNormalizerService
{
    /**
     * @param  array{
     *   available_from?: mixed,
     *   available_to?: mixed,
     *   capacity?: mixed,
     *   seats?: mixed,
     *   rooms?: mixed
     * }  $input
     * @return array{
     *   available_from: string|null,
     *   available_to: string|null,
     *   capacity: int|null,
     *   seats: int|null,
     *   rooms: int|null
     * }
     */
    public function normalize(array $input): array
    {
        $capacity = $this->toNullableInt($input['capacity'] ?? null);
        $seats = $this->toNullableInt($input['seats'] ?? null);
        $rooms = $this->toNullableInt($input['rooms'] ?? null);

        return [
            'available_from' => $this->toIso8601String($input['available_from'] ?? null),
            'available_to' => $this->toIso8601String($input['available_to'] ?? null),
            'capacity' => $capacity ?? $seats ?? $rooms,
            'seats' => $seats,
            'rooms' => $rooms,
        ];
    }

    private function toIso8601String(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return (string) $value;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
