<?php

namespace App\Services\Locations;

use App\Models\Location;
use Illuminate\Validation\ValidationException;

class LocationBusinessValidator
{
    /**
     * @param  list<string>  $allowedTypes
     */
    public function requireLocationOfTypes(
        ?int $locationId,
        string $field,
        array $allowedTypes,
        string $requiredMessage,
        string $invalidTypeMessage
    ): Location {
        if ($locationId === null || $locationId <= 0) {
            throw ValidationException::withMessages([
                $field => [$requiredMessage],
            ]);
        }

        $location = Location::query()->find($locationId);
        if ($location === null) {
            throw ValidationException::withMessages([
                $field => ['Selected location does not exist.'],
            ]);
        }

        if (! in_array($location->type, $allowedTypes, true)) {
            throw ValidationException::withMessages([
                $field => [$invalidTypeMessage],
            ]);
        }

        return $location;
    }

}
